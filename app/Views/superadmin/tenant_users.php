<?php
$layout = 'superadmin';
$title = 'Super Administrador - Usuarios del Cliente';
$pageTitle = 'Gestion de Usuarios';
$userName = $_SESSION['super_admin_name'] ?? 'Super Admin';
$tenant = $tenant ?? [];
$users = $users ?? [];

$availableModules = ['dashboard', 'caja', 'ventas', 'inventario', 'clientes', 'proveedores', 'cotizaciones', 'gastos', 'contabilidad', 'nomina', 'reportes'];
$moduleNames = [
    'dashboard' => 'Dashboard',
    'caja' => 'Caja',
    'ventas' => 'Ventas',
    'inventario' => 'Inventario',
    'clientes' => 'Clientes',
    'proveedores' => 'Proveedores',
    'cotizaciones' => 'Cotizaciones',
    'gastos' => 'Gastos',
    'contabilidad' => 'Contabilidad',
    'nomina' => 'Nomina',
    'reportes' => 'Reportes'
];
?>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success">
        <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-error">
        <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<div class="card neumorphic">
    <div class="card-header">
        <h3>Usuarios de: <?php echo htmlspecialchars($tenant['company_name'] ?? ''); ?></h3>
        <a href="<?php echo $viewInstance->route('superadmin/tenants'); ?>" class="btn btn-secondary">Volver a Clientes</a>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Rol</th>
                        <th>Permisos</th>
                        <th>Estado</th>
                        <th>Fecha Creacion</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: var(--color-text-secondary);">
                                No hay usuarios registrados
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <?php
                            $permissions = json_decode($user['permissions'] ?? '{}', true);
                            $permissionCount = 0;
                            if (is_array($permissions)) {
                                foreach ($permissions as $perm) {
                                    if (!empty($perm['view']) || !empty($perm['edit'])) {
                                        $permissionCount++;
                                    }
                                }
                            }
                            ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td>
                                    <?php
                                    $roleLabels = [
                                        'admin' => 'Administrador',
                                        'user' => 'Usuario',
                                        'auxiliar' => 'Auxiliar/Mesero',
                                    ];
                                    $roleBadge = $user['role'] === 'admin' ? 'badge-success' : ($user['role'] === 'auxiliar' ? 'badge-warning' : 'badge-info');
                                    ?>
                                    <span class="badge <?php echo $roleBadge; ?>">
                                        <?php echo $roleLabels[$user['role']] ?? htmlspecialchars($user['role']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($user['role'] === 'admin'): ?>
                                        <span class="badge badge-success">Todos los modulos</span>
                                    <?php elseif ($user['role'] === 'auxiliar'): ?>
                                        <span class="badge badge-warning">Ventas + Inventario (carrito)</span>
                                    <?php elseif ($permissionCount > 0): ?>
                                        <span class="badge badge-info"><?php echo $permissionCount; ?> modulos</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Sin permisos</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?php echo $user['status'] === 'active' ? 'badge-success' : 'badge-danger'; ?>">
                                        <?php echo $user['status'] === 'active' ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </td>
                                <td><?php echo date('d/m/Y', strtotime($user['created_at'])); ?></td>
                                <td class="table-actions">
                                    <form method="POST" action="<?php echo $viewInstance->route('superadmin/tenants/users'); ?>?action=delete" style="display:inline;" data-ajax="true">
                                        <?php echo \SoftNova\Core\csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                        <input type="hidden" name="tenant_id" value="<?php echo $tenant['id']; ?>">
                                        <button type="submit" onclick="return confirm('¿Eliminar este usuario?')" class="btn btn-danger">Eliminar</button>
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

<div class="card neumorphic">
    <div class="card-header">
        <h3>Crear Nuevo Usuario</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="<?php echo $viewInstance->route('superadmin/tenants/' . $tenant['id'] . '/users'); ?>?action=create" class="settings-form" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="form-group">
                <label>Nombre Completo *</label>
                <input type="text" name="name" required class="neumorphic-input">
            </div>
            <div class="form-group">
                <label>Email *</label>
                <input type="email" name="email" required class="neumorphic-input">
            </div>
            <div class="form-group">
                <label>Contraseña *</label>
                <input type="password" name="password" required class="neumorphic-input">
            </div>
            <div class="form-group">
                <label>Rol *</label>
                <select name="role" id="userRole" required class="neumorphic-input" onchange="togglePermissionsSection()">
                    <option value="admin">Administrador</option>
                    <option value="user">Usuario</option>
                    <option value="auxiliar">Auxiliar/Mesero</option>
                </select>
                <small id="roleHint" style="display:none;color:var(--color-text-secondary);font-size:12px;margin-top:6px;">
                    Auxiliar/Mesero: solo Ventas (crear, sin eliminar) e Inventario (carrito a venta; sin crear/editar/eliminar productos).
                </small>
            </div>
            
            <div id="permissionsSection" class="permissions-section" style="display: none; margin-top: 20px; padding: 20px; background-color: var(--color-secondary); border-radius: 8px; border: 1px solid var(--color-border);">
                <h4 style="margin-bottom: 15px; color: var(--color-primary);">Permisos por Modulo</h4>
                <p style="margin-bottom: 15px; color: var(--color-text-secondary); font-size: 13px;">Selecciona que modulos podra ver y/o editar este usuario.</p>
                
                <div class="permissions-header" style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 10px; padding: 10px; font-weight: 600; color: var(--color-primary); border-bottom: 1px solid var(--color-border);">
                    <span>Modulo</span>
                    <span style="text-align: center;">Ver</span>
                    <span style="text-align: center;">Editar</span>
                </div>
                
                <?php foreach ($availableModules as $module): ?>
                    <div class="permission-row" style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 10px; padding: 10px; align-items: center; border-bottom: 1px solid var(--color-border);">
                        <span><?php echo $moduleNames[$module]; ?></span>
                        <label style="display: flex; justify-content: center; align-items: center; cursor: pointer;">
                            <input type="checkbox" name="permissions[<?php echo $module; ?>][view]" value="1" class="permission-view" data-module="<?php echo $module; ?>">
                        </label>
                        <label style="display: flex; justify-content: center; align-items: center; cursor: pointer;">
                            <input type="checkbox" name="permissions[<?php echo $module; ?>][edit]" value="1" class="permission-edit" data-module="<?php echo $module; ?>">
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <button type="submit" class="btn btn-primary neumorphic-btn" style="margin-top: 20px;">Crear Usuario</button>
        </form>
    </div>
</div>

<script>
function togglePermissionsSection() {
    const role = document.getElementById('userRole').value;
    const section = document.getElementById('permissionsSection');
    const hint = document.getElementById('roleHint');
    
    if (hint) hint.style.display = role === 'auxiliar' ? 'block' : 'none';
    
    if (role === 'user') {
        section.style.display = 'block';
    } else {
        section.style.display = 'none';
        document.querySelectorAll('.permission-view, .permission-edit').forEach(checkbox => {
            checkbox.checked = false;
        });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    togglePermissionsSection();
    
    document.querySelectorAll('.permission-edit').forEach(editCheckbox => {
        editCheckbox.addEventListener('change', function() {
            const module = this.dataset.module;
            const viewCheckbox = document.querySelector('.permission-view[data-module="' + module + '"]');
            if (this.checked && viewCheckbox) {
                viewCheckbox.checked = true;
            }
        });
    });
    
    document.querySelectorAll('.permission-view').forEach(viewCheckbox => {
        viewCheckbox.addEventListener('change', function() {
            const module = this.dataset.module;
            const editCheckbox = document.querySelector('.permission-edit[data-module="' + module + '"]');
            if (!this.checked && editCheckbox) {
                editCheckbox.checked = false;
            }
        });
    });
});
</script>
