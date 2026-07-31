<?php
$layout = 'tenant';
$title = 'Configuración - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Configuración del Sistema';
$tenantName = $tenantName ?? 'Mi Empresa';
$userName = $userName ?? 'Usuario';
$settings = $settings ?? [];
$currencies = $currencies ?? [];
$languages = $languages ?? [];
$currentUser = $currentUser ?? null;
$subscription = $subscription ?? null;
$currentLang = $settings['language'] ?? 'es';
$avatarUrl = $settings['user_avatar'] ?? null;
$isAdmin = $isAdmin ?? (\SoftNova\Core\TenantMiddleware::getRole() === 'admin');
$teamUsers = $teamUsers ?? [];
$jobsPending = $jobsPending ?? 0;
$managedModules = $managedModules ?? \SoftNova\Core\TenantMiddleware::MANAGED_MODULES;
$permActions = $permActions ?? \SoftNova\Core\TenantMiddleware::ACTIONS;
$teamRoleLabels = $teamRoleLabels ?? \SoftNova\Core\TenantMiddleware::teamRoleLabels();
$moduleLabels = [
    'dashboard' => 'Dashboard', 'caja' => 'Caja', 'ventas' => 'Ventas', 'inventario' => 'Inventario',
    'clientes' => 'Clientes', 'proveedores' => 'Proveedores', 'compras' => 'Compras',
    'cotizaciones' => 'Cotizaciones', 'gastos' => 'Gastos', 'contabilidad' => 'Contabilidad',
    'nomina' => 'Nómina', 'reportes' => 'Reportes',
];
$actionLabels = ['view' => 'Ver', 'create' => 'Crear', 'edit' => 'Editar', 'delete' => 'Eliminar', 'export' => 'Exportar'];
$configBase = $viewInstance->route('app/configuracion');
$activeTab = 'general';
if (!empty($_SERVER['REQUEST_URI']) && str_contains((string)$_SERVER['REQUEST_URI'], '#usuarios')) {
    $activeTab = 'usuarios';
}
?>

<?php echo flashMessage(); ?>

<style>
.config-tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;border-bottom:1px solid var(--color-border);padding-bottom:10px;}
.config-tab-btn{appearance:none;border:1px solid var(--color-border);background:var(--bg-card);color:var(--color-text);padding:8px 14px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;}
.config-tab-btn.active{background:var(--color-primary);color:#fff;border-color:var(--color-primary);}
.config-tab-panel{display:none;}
.config-tab-panel.active{display:block;}
</style>

<nav class="config-tabs" role="tablist" aria-label="Secciones de configuracion">
    <button type="button" class="config-tab-btn active" data-config-tab="general">General</button>
    <?php if ($isAdmin): ?>
    <button type="button" class="config-tab-btn" data-config-tab="usuarios">Usuarios</button>
    <?php endif; ?>
    <button type="button" class="config-tab-btn" data-config-tab="empresa">Empresa</button>
    <button type="button" class="config-tab-btn" data-config-tab="sistema">Sistema</button>
</nav>

<div class="config-tab-panel active" id="configTab-general">
<div class="settings-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 20px;">
    
    <!-- Perfil de Usuario -->
    <div class="card neumorphic">
        <div class="card-header">
            <h3>Perfil de Usuario</h3>
        </div>
        <div class="card-body">
            <div style="display:flex;align-items:center;gap:20px;margin-bottom:20px;">
                <div style="position:relative;">
                    <div id="avatarPreview" style="width:80px;height:80px;border-radius:50%;background-color:var(--color-primary);display:flex;align-items:center;justify-content:center;color:#fff;font-size:32px;font-weight:700;overflow:hidden;">
                        <?php if ($avatarUrl): ?>
                            <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Avatar" style="width:100%;height:100%;object-fit:cover;">
                        <?php else: ?>
                            <?php echo strtoupper(substr($userName, 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <label for="avatarInput" style="position:absolute;bottom:0;right:0;width:28px;height:28px;background:var(--color-primary);border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#fff;font-size:14px;border:2px solid var(--bg-card);" title="Cambiar foto">+</label>
                    <form id="avatarForm" method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=uploadAvatar" enctype="multipart/form-data" style="display:none;">
                        <?php echo \SoftNova\Core\csrf_field(); ?>
                        <input type="file" id="avatarInput" name="avatar" accept="image/*" onchange="uploadAvatar(this)">
                    </form>
                </div>
                <div>
                    <strong style="font-size:16px;"><?php echo htmlspecialchars($currentUser['name'] ?? $userName); ?></strong>
                    <p style="color:var(--color-text-secondary);font-size:13px;margin:4px 0;"><?php echo htmlspecialchars($currentUser['email'] ?? ''); ?></p>
                    <span class="badge badge-info" style="font-size:11px;"><?php echo htmlspecialchars(\SoftNova\Core\TenantMiddleware::roleDisplayLabel((string)($currentUser['role'] ?? 'admin'))); ?></span>
                    <?php if (!empty($currentUser['last_login_at'])): ?>
                        <p style="color:var(--color-text-secondary);font-size:11px;margin-top:4px;">Ultimo acceso: <?php echo date('d/m/Y H:i', strtotime($currentUser['last_login_at'])); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <hr style="border-color:var(--color-border);margin:15px 0;">
            <h4 style="margin-bottom:12px;">Cambiar Contraseña</h4>
            <form method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=changePassword" data-ajax="true">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <div class="form-group">
                    <label>Contraseña Actual</label>
                    <input type="password" name="current_password" class="form-control" required placeholder="••••••••">
                </div>
                <div class="form-group">
                    <label>Nueva Contraseña</label>
                    <input type="password" name="new_password" class="form-control" required placeholder="Minimo 6 caracteres" minlength="6">
                </div>
                <div class="form-group">
                    <label>Confirmar Nueva Contraseña</label>
                    <input type="password" name="confirm_password" class="form-control" required placeholder="Repetir contraseña" minlength="6">
                </div>
                <button type="submit" class="btn btn-primary">Actualizar Contraseña</button>
            </form>
        </div>
    </div>
</div>
</div><!-- /tab general -->

    <?php if ($isAdmin): ?>
<div class="config-tab-panel" id="configTab-usuarios">
    <!-- Equipo / Usuarios -->
    <div class="card neumorphic" id="teamUsers">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <h3>Usuarios del sistema</h3>
            <button type="button" class="btn btn-primary btn-sm" onclick="openTeamUserModal()">+ Crear usuario</button>
        </div>
        <div class="card-body">
            <p style="font-size:13px;color:var(--color-text-secondary);margin-bottom:12px;">
                Roles: <strong>User</strong> (elige modulos y permisos), <strong>POS</strong> (solo punto de venta y lista de ventas).
                El rol <strong>Administrativo</strong> no se crea desde aqui.
            </p>
            <?php if (empty($teamUsers)): ?>
                <p style="text-align:center;color:var(--color-text-secondary);padding:16px;">No hay usuarios de equipo aun</p>
            <?php else: ?>
                <div class="table-container"><table>
                    <thead><tr><th>Nombre</th><th>Email</th><th>Rol</th><th>Estado</th><th>Ultimo acceso</th><th>Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ($teamUsers as $tu): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($tu['name']); ?></td>
                            <td><?php echo htmlspecialchars($tu['email']); ?></td>
                            <td><span class="badge badge-info"><?php echo htmlspecialchars($teamRoleLabels[$tu['role']] ?? $tu['role']); ?></span></td>
                            <td>
                                <?php if (($tu['status'] ?? '') === 'active'): ?>
                                    <span class="badge badge-success">Activo</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo !empty($tu['last_login_at']) ? date('d/m/Y H:i', strtotime($tu['last_login_at'])) : '—'; ?></td>
                            <td class="table-actions">
                                <button type="button" class="btn btn-sm btn-secondary" onclick='editTeamUser(<?php echo json_encode([
                                    'id' => (int)$tu['id'],
                                    'name' => $tu['name'],
                                    'email' => $tu['email'],
                                    'role' => $tu['role'],
                                    'permissions' => $tu['permissions_map'] ?? [],
                                ], JSON_UNESCAPED_UNICODE); ?>)'>Editar</button>
                                <form method="POST" action="<?php echo $configBase; ?>?action=toggleUser" data-ajax="true" style="display:inline;">
                                    <?php echo \SoftNova\Core\csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo (int)$tu['id']; ?>">
                                    <button type="submit" class="btn btn-sm <?php echo ($tu['status'] ?? '') === 'active' ? 'btn-danger' : 'btn-success'; ?>" data-confirm="¿Cambiar estado de este usuario?">
                                        <?php echo ($tu['status'] ?? '') === 'active' ? 'Desactivar' : 'Activar'; ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>

    <div id="teamUserModal" class="modal-overlay" style="display:none;z-index:1200;">
        <div class="modal-content neumorphic" style="max-width:720px;max-height:90vh;overflow-y:auto;">
            <div class="modal-header">
                <h3 id="teamUserModalTitle">Crear usuario</h3>
                <button type="button" class="modal-close" onclick="document.getElementById('teamUserModal').style.display='none'">&times;</button>
            </div>
            <form method="POST" action="<?php echo $configBase; ?>?action=createUser" id="teamUserForm" data-ajax="true">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <input type="hidden" name="id" id="teamUserId" value="">
                <div class="modal-body">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="form-group"><label>Nombre *</label><input type="text" name="name" id="teamUserName" class="form-control" required></div>
                        <div class="form-group"><label>Email *</label><input type="email" name="email" id="teamUserEmail" class="form-control" required></div>
                        <div class="form-group">
                            <label>Rol *</label>
                            <select name="role" id="teamUserRole" class="form-control" onchange="togglePermMatrix()">
                                <?php foreach ($teamRoleLabels as $code => $lab): ?>
                                    <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($lab); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Contraseña <span id="teamPassHint">*</span></label>
                            <input type="password" name="password" id="teamUserPassword" class="form-control" minlength="6" autocomplete="new-password">
                        </div>
                    </div>
                    <div id="permMatrixWrap" style="margin-top:12px;">
                        <h4 style="margin-bottom:8px;">Permisos por modulo (rol User)</h4>
                        <p style="font-size:12px;color:var(--color-text-secondary);margin-bottom:8px;">Marque Ver / Crear / Editar / Eliminar / Exportar por cada modulo.</p>
                        <div class="table-container"><table style="font-size:13px;">
                            <thead><tr>
                                <th>Modulo</th>
                                <?php foreach ($permActions as $a): ?>
                                    <th style="text-align:center;"><?php echo htmlspecialchars($actionLabels[$a] ?? $a); ?></th>
                                <?php endforeach; ?>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($managedModules as $mod): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($moduleLabels[$mod] ?? $mod); ?></td>
                                    <?php foreach ($permActions as $a): ?>
                                        <td style="text-align:center;">
                                            <input type="checkbox" class="perm-check" name="permissions[<?php echo $mod; ?>][<?php echo $a; ?>]" value="1" data-module="<?php echo $mod; ?>" data-action="<?php echo $a; ?>">
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table></div>
                    </div>
                    <div id="posRoleHint" style="display:none;margin-top:10px;" class="alert alert-info">
                        Rol POS: solo acceso al punto de venta (Caja/POS) y lista de ventas de la sesion.
                        No ve tarjetas de informacion, movimientos de caja ni el boton Gasto/Compra. Home: POS.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('teamUserModal').style.display='none'">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    function openTeamUserModal() {
        document.getElementById('teamUserModalTitle').textContent = 'Crear usuario';
        document.getElementById('teamUserForm').action = '<?php echo $configBase; ?>?action=createUser';
        document.getElementById('teamUserId').value = '';
        document.getElementById('teamUserName').value = '';
        document.getElementById('teamUserEmail').value = '';
        document.getElementById('teamUserRole').value = 'user';
        document.getElementById('teamUserPassword').required = true;
        document.getElementById('teamPassHint').textContent = '*';
        document.getElementById('teamUserPassword').value = '';
        document.querySelectorAll('.perm-check').forEach(function(c) { c.checked = false; });
        togglePermMatrix();
        document.getElementById('teamUserModal').style.display = 'flex';
    }
    function editTeamUser(u) {
        document.getElementById('teamUserModalTitle').textContent = 'Editar usuario';
        document.getElementById('teamUserForm').action = '<?php echo $configBase; ?>?action=editUser';
        document.getElementById('teamUserId').value = u.id;
        document.getElementById('teamUserName').value = u.name || '';
        document.getElementById('teamUserEmail').value = u.email || '';
        document.getElementById('teamUserRole').value = u.role || 'user';
        document.getElementById('teamUserPassword').required = false;
        document.getElementById('teamPassHint').textContent = '(opcional)';
        document.getElementById('teamUserPassword').value = '';
        document.querySelectorAll('.perm-check').forEach(function(c) {
            var mod = c.getAttribute('data-module');
            var act = c.getAttribute('data-action');
            c.checked = !!(u.permissions && u.permissions[mod] && u.permissions[mod][act]);
        });
        togglePermMatrix();
        document.getElementById('teamUserModal').style.display = 'flex';
    }
    function togglePermMatrix() {
        var role = document.getElementById('teamUserRole').value;
        var isPos = role === 'pos';
        document.getElementById('permMatrixWrap').style.display = isPos ? 'none' : '';
        document.getElementById('posRoleHint').style.display = isPos ? '' : 'none';
    }
    </script>
</div><!-- /tab usuarios -->
    <?php endif; ?>

<div class="config-tab-panel" id="configTab-empresa">
<div class="settings-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 20px;">

    <!-- Datos de Empresa -->
    <div class="card neumorphic">
        <div class="card-header">
            <h3>Datos de la Empresa</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=save" data-ajax="true">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <div class="form-group">
                    <label>Nombre de la Empresa</label>
                    <input type="text" name="company_name" class="form-control" 
                           value="<?php echo htmlspecialchars($settings['company_name'] ?? ''); ?>" 
                           placeholder="Mi Empresa">
                </div>
                <div class="form-group">
                    <label>Nombre del Impuesto</label>
                    <input type="text" name="tax_name" class="form-control" 
                           value="<?php echo htmlspecialchars($settings['tax_name'] ?? 'IVA'); ?>">
                </div>
                <div class="form-group">
                    <label>Tasa de Impuesto (%)</label>
                    <input type="number" name="tax_rate" class="form-control" 
                           value="<?php echo htmlspecialchars($settings['tax_rate'] ?? '19'); ?>" 
                           step="0.1" min="0" max="100" placeholder="19">
                </div>
                <button type="submit" class="btn btn-primary">Guardar Datos</button>
            </form>
        </div>
    </div>
    
    <!-- Moneda -->
    <div class="card neumorphic">
        <div class="card-header">
            <h3>💱 Moneda del Sistema</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=save" data-ajax="true">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <div class="form-group">
                    <label>Seleccione la moneda principal</label>
                    <select name="currency" class="form-control" required>
                        <?php foreach ($currencies as $code => $cur): ?>
                            <option value="<?php echo $code; ?>" <?php echo ($settings['currency'] ?? 'COP') === $code ? 'selected' : ''; ?>>
                                <?php echo $cur['name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: var(--color-text-secondary);">Esta moneda se usará en ventas, caja y reportes.</small>
                </div>
                <button type="submit" class="btn btn-primary">Guardar Moneda</button>
            </form>
        </div>
    </div>
    
    <!-- Apariencia e Idioma -->
    <div class="card neumorphic">
        <div class="card-header"><h3>🎨 Apariencia e Idioma</h3></div>
        <div class="card-body">
            <p style="margin-bottom:12px;color:var(--color-text-secondary);font-size:13px;">Tema visual</p>
            <div style="display:flex;gap:10px;margin-bottom:20px;">
                <button onclick="setTheme('light')" id="btnLight" class="btn btn-secondary" style="flex:1;">☀️ Claro</button>
                <button onclick="setTheme('dark')" id="btnDark" class="btn btn-secondary" style="flex:1;">🌙 Oscuro</button>
            </div>

            <?php
            $currentColor = $settings['primary_color'] ?? '';
            $currentColor = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$currentColor) ? strtoupper($currentColor) : '#0D7C4A';
            $presetColors = ['#0D7C4A', '#2563EB', '#7C3AED', '#DB2777', '#EA580C', '#0891B2', '#DC2626', '#4F46E5'];
            ?>
            <p style="margin-bottom:12px;color:var(--color-text-secondary);font-size:13px;">🎨 Color principal del programa</p>
            <form method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=save" data-ajax="true" id="colorForm" style="margin-bottom:20px;">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;" id="colorPresets">
                    <?php foreach ($presetColors as $preset): ?>
                        <button type="button" class="color-swatch" data-color="<?php echo $preset; ?>"
                                onclick="pickColor('<?php echo $preset; ?>')"
                                title="<?php echo $preset; ?>"
                                style="width:34px;height:34px;border-radius:50%;cursor:pointer;background:<?php echo $preset; ?>;border:3px solid <?php echo strcasecmp($preset, $currentColor) === 0 ? 'var(--color-text-primary)' : 'transparent'; ?>;box-shadow:0 2px 6px rgba(0,0,0,0.15);"></button>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <input type="color" name="primary_color" id="primaryColorInput" value="<?php echo $currentColor; ?>"
                           class="form-control" style="width:60px;height:42px;padding:4px;cursor:pointer;" onchange="pickColor(this.value)">
                    <span id="colorPreview" style="font-size:13px;color:var(--color-text-secondary);"><?php echo $currentColor; ?></span>
                    <button type="submit" class="btn btn-primary" id="colorPreviewBtn">Guardar color</button>
                    <button type="button" class="btn btn-secondary" onclick="pickColor('#0D7C4A')">Restablecer</button>
                </div>
                <p style="margin-top:8px;color:var(--color-text-secondary);font-size:12px;">
                    El texto de los botones se ajusta automáticamente para mantener buen contraste.
                </p>
            </form>
            
            <p style="margin-bottom:12px;color:var(--color-text-secondary);font-size:13px;">🌐 Idioma del Sistema</p>
            <form method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=save" data-ajax="true" id="langForm">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <input type="hidden" name="language" id="langInput" value="<?php echo $currentLang; ?>">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <?php foreach ($languages as $code => $lang): ?>
                        <button type="button" 
                                onclick="setLang('<?php echo $code; ?>')" 
                                id="btnLang<?php echo $code; ?>"
                                class="btn <?php echo $currentLang === $code ? 'btn-primary neumorphic-btn' : 'btn-secondary'; ?>"
                                style="font-size:13px;text-align:left;">
                            <?php echo $lang['flag']; ?> <?php echo $lang['name']; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </form>
        </div>
    </div>
</div>
</div><!-- /tab empresa -->

<div class="config-tab-panel" id="configTab-sistema">
<div class="settings-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 20px;">

    <!-- Suscripción -->
    <div class="card neumorphic">
        <div class="card-header"><h3>Suscripcion</h3></div>
        <div class="card-body">
            <?php if ($subscription): ?>
                <div style="margin-bottom:15px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <span style="color:var(--color-text-secondary);">Plan:</span>
                        <strong><?php echo htmlspecialchars($subscription['plan_name']); ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <span style="color:var(--color-text-secondary);">Precio Mensual:</span>
                        <strong>$<?php echo number_format($subscription['monthly_price'] ?? 0, 2); ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <span style="color:var(--color-text-secondary);">Precio Anual:</span>
                        <strong>$<?php echo number_format($subscription['annual_price'] ?? 0, 2); ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <span style="color:var(--color-text-secondary);">Estado:</span>
                        <span class="badge <?php echo $subscription['subscription_status'] === 'active' ? 'badge-success' : ($subscription['subscription_status'] === 'pending' ? 'badge-warning' : 'badge-danger'); ?>">
                            <?php echo $subscription['subscription_status'] === 'active' ? 'Activo' : ($subscription['subscription_status'] === 'pending' ? 'Pendiente' : ucfirst($subscription['subscription_status'])); ?>
                        </span>
                    </div>
                    <?php if (!empty($subscription['subscription_end_date'])): ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <span style="color:var(--color-text-secondary);">Vence:</span>
                            <strong><?php echo date('d/m/Y', strtotime($subscription['subscription_end_date'])); ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
                <hr style="border-color:var(--color-border);margin:15px 0;">
            <?php endif; ?>
            <p style="color:var(--color-text-secondary);font-size:13px;margin-bottom:12px;">¿Necesita renovar o cambiar de plan? Contáctenos para gestionar su suscripción.</p>
            <a href="<?php echo $viewInstance->route('app/soporte'); ?>" class="btn btn-primary neumorphic-btn" style="width:100%;">📞 Solicitar Soporte / Renovación</a>
        </div>
    </div>
    
    <!-- Preferencias -->
    <div class="card neumorphic">
        <div class="card-header">
            <h3>⚙️ Preferencias</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=save" data-ajax="true">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <div class="form-group">
                    <label>Prefijo de Factura</label>
                    <input type="text" name="invoice_prefix" class="form-control" 
                           value="<?php echo htmlspecialchars($settings['invoice_prefix'] ?? 'FAC-'); ?>">
                </div>
                <div class="form-group">
                    <label>Alerta de Stock Bajo</label>
                    <input type="number" name="low_stock_alert" class="form-control" 
                           value="<?php echo htmlspecialchars($settings['low_stock_alert'] ?? '5'); ?>" 
                           min="1" placeholder="5">
                    <small style="color: var(--color-text-secondary);">Se alertará cuando el stock esté por debajo de este valor.</small>
                </div>
                <div class="form-group">
                    <label>Webhook venta fallida/cancelada (opcional)</label>
                    <input type="url" name="webhook_sale_url" class="form-control"
                           value="<?php echo htmlspecialchars($settings['webhook_sale_url'] ?? ''); ?>"
                           placeholder="https://hooks.ejemplo.com/venta">
                    <small style="color: var(--color-text-secondary);">POST JSON encolado al cancelar o fallar una venta.</small>
                </div>
                <button type="submit" class="btn btn-primary">Guardar Preferencias</button>
            </form>
        </div>
    </div>

    <!-- Copia de seguridad -->
    <?php
    $backups = $backups ?? [];
    $backupEnabled = ($settings['backup_enabled'] ?? '0') === '1';
    $backupTime = $settings['backup_time'] ?? '02:00';
    $backupLast = $settings['backup_last_run'] ?? '';
    $isAdmin = ($_SESSION['tenant_user_role'] ?? '') === 'admin';
    ?>
    <div class="card neumorphic" style="grid-column: 1 / -1;">
        <div class="card-header">
            <h3>Copia de seguridad</h3>
        </div>
        <div class="card-body">
            <?php if (!$isAdmin): ?>
                <p style="color:var(--color-text-secondary);font-size:13px;">Solo el administrador puede descargar o restaurar copias de seguridad.</p>
            <?php else: ?>
                <p style="color:var(--color-text-secondary);font-size:13px;margin-bottom:16px;">
                    Formato <strong>SQL</strong>: incluye usuarios, clientes, proveedores, productos, ventas, caja, cotizaciones, gastos y demas tablas del sistema.
                    Util para recuperar datos si alguien borra informacion por error o con mala intencion.
                </p>
                
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;">
                    <div>
                        <h4 style="margin-bottom:10px;">Cola de jobs</h4>
                        <p style="font-size:13px;color:var(--color-text-secondary);margin-bottom:10px;">
                            Pendientes: <strong><?php echo (int)($jobsPending ?? 0); ?></strong>
                            (backups, import CSV grandes, webhooks).
                        </p>
                        <form method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=processJobs" data-ajax="true">
                            <?php echo \SoftNova\Core\csrf_field(); ?>
                            <button type="submit" class="btn btn-secondary">Procesar jobs ahora</button>
                        </form>
                        <small style="display:block;margin-top:8px;color:var(--color-text-secondary);">CLI: <code>php database/process_jobs.php</code></small>
                    </div>
                    <div>
                        <h4 style="margin-bottom:10px;">Descargar copia</h4>
                        <form method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=backupDownload" data-ajax="true">
                            <?php echo \SoftNova\Core\csrf_field(); ?>
                            <div class="form-group">
                                <label>Contraseña de administrador *</label>
                                <input type="password" name="confirm_password" class="form-control" required placeholder="Confirme su contraseña" autocomplete="current-password">
                            </div>
                            <button type="submit" class="btn btn-primary" title="Generar y descargar backup SQL">Generar y descargar .sql</button>
                        </form>
                        <?php if ($backupLast): ?>
                            <small style="display:block;margin-top:8px;color:var(--color-text-secondary);">Ultimo backup: <?php echo htmlspecialchars($backupLast); ?></small>
                        <?php endif; ?>
                    </div>
                    
                    <div>
                        <h4 style="margin-bottom:10px;">Programacion automatica</h4>
                        <form method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=backupSchedule" data-ajax="true">
                            <?php echo \SoftNova\Core\csrf_field(); ?>
                            <label style="display:flex;align-items:center;gap:8px;margin-bottom:10px;cursor:pointer;">
                                <input type="checkbox" name="backup_enabled" value="1" <?php echo $backupEnabled ? 'checked' : ''; ?> style="accent-color:var(--color-primary);">
                                Activar auto-backup diario
                            </label>
                            <div class="form-group">
                                <label>Hora del backup</label>
                                <input type="time" name="backup_time" class="form-control" value="<?php echo htmlspecialchars($backupTime); ?>" required>
                                <small style="color:var(--color-text-secondary);">Se ejecuta al detectar actividad despues de esa hora (sin cron externo).</small>
                            </div>
                            <button type="submit" class="btn btn-secondary">Guardar programacion</button>
                        </form>
                    </div>
                    
                    <div>
                        <h4 style="margin-bottom:10px;">Restaurar copia</h4>
                        <form method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=backupRestore" id="backupRestoreForm" enctype="multipart/form-data" onsubmit="return openRestoreDangerModal(event)">
                            <?php echo \SoftNova\Core\csrf_field(); ?>
                            <input type="hidden" name="confirm_password" id="restoreConfirmPassword" value="">
                            <div class="form-group">
                                <label>Backup guardado en el servidor</label>
                                <select name="backup_file" class="form-control">
                                    <option value="">— Opcional —</option>
                                    <?php foreach (($backupsAll ?? $backups) as $b): ?>
                                        <option value="<?php echo htmlspecialchars($b['filename']); ?>">
                                            <?php echo htmlspecialchars($b['filename']); ?> (<?php echo htmlspecialchars($b['size_label']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>O subir archivo .sql</label>
                                <input type="file" name="backup_upload" class="form-control" accept=".sql,application/sql,text/plain">
                            </div>
                            <button type="submit" class="btn btn-danger">Restaurar ahora</button>
                        </form>
                    </div>
                </div>
                
                <?php if (!empty($backups) || !empty($backupPagination['total'])): ?>
                <hr style="border-color:var(--color-border);margin:20px 0;" id="backup-list">
                <h4 style="margin-bottom:10px;">Copias recientes en el servidor</h4>
                <p style="color:var(--color-text-secondary);font-size:12px;margin-bottom:10px;">Las copias con mas de 7 dias se eliminan automaticamente. Se muestran 7 por pagina.</p>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr><th>Archivo</th><th>Tamano</th><th>Fecha</th><th>Acciones</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($backups)): ?>
                                <tr><td colspan="4" style="text-align:center;color:var(--color-text-secondary);">No hay copias en esta pagina</td></tr>
                            <?php else: ?>
                                <?php foreach ($backups as $b): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($b['filename']); ?></td>
                                    <td><?php echo htmlspecialchars($b['size_label']); ?></td>
                                    <td><?php echo htmlspecialchars($b['created_at']); ?></td>
                                    <td class="table-actions">
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="openDownloadBackupModal(<?php echo htmlspecialchars(json_encode($b['filename']), ENT_QUOTES, 'UTF-8'); ?>)">Descargar</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (($backupPagination['totalPages'] ?? 1) > 1): ?>
                <div class="pagination-bar" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:16px;padding-top:12px;border-top:1px solid var(--color-border);">
                    <span style="font-size:13px;color:var(--color-text-secondary);">
                        Total: <?php echo (int)$backupPagination['total']; ?> | Pagina <?php echo (int)$backupPagination['page']; ?> de <?php echo (int)$backupPagination['totalPages']; ?>
                    </span>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <?php if ((int)$backupPagination['page'] > 1): ?>
                            <a class="btn btn-sm btn-secondary" href="<?php echo $viewInstance->route('app/configuracion'); ?>?backup_page=<?php echo (int)$backupPagination['page'] - 1; ?>#backup-list">Anterior</a>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= (int)$backupPagination['totalPages']; $i++): ?>
                            <a class="btn btn-sm <?php echo $i === (int)$backupPagination['page'] ? 'btn-primary' : 'btn-secondary'; ?>"
                               href="<?php echo $viewInstance->route('app/configuracion'); ?>?backup_page=<?php echo $i; ?>#backup-list"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        <?php if ((int)$backupPagination['page'] < (int)$backupPagination['totalPages']): ?>
                            <a class="btn btn-sm btn-secondary" href="<?php echo $viewInstance->route('app/configuracion'); ?>?backup_page=<?php echo (int)$backupPagination['page'] + 1; ?>#backup-list">Siguiente</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</div><!-- /tab sistema -->

<!-- Modal descarga backup -->
<div id="downloadBackupModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:420px;">
        <div class="modal-header">
            <h3>Descargar copia</h3>
            <button type="button" class="modal-close" onclick="closeDownloadBackupModal()">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=backupGetFile" id="downloadBackupForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="file" id="downloadBackupFile" value="">
            <div class="modal-body">
                <p style="margin-bottom:12px;color:var(--color-text-secondary);font-size:13px;">Confirme su contraseña de administrador para descargar el archivo.</p>
                <div class="form-group">
                    <label>Contraseña *</label>
                    <input type="password" name="confirm_password" id="downloadBackupPassword" class="form-control" required autocomplete="current-password" placeholder="Contraseña de administrador">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDownloadBackupModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Descargar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal alto riesgo: restaurar backup -->
<div id="restoreDangerModal" class="modal-overlay restore-danger-overlay" style="display:none;">
    <div class="restore-danger-modal" role="dialog" aria-modal="true" aria-labelledby="restoreDangerTitle">
        <h3 id="restoreDangerTitle">PASO DE ALTO RIESGO</h3>
        <p class="restore-danger-warn">¿Estás seguro?</p>
        <p class="restore-danger-text">
            Esta acción <strong>sobrescribe TODOS los datos actuales</strong> del sistema con la copia seleccionada.
            No se creará una copia previa automática. Si continúa sin un respaldo externo, puede perder información de forma irreversible.
        </p>
        <div class="form-group" style="text-align:left;">
            <label style="color:#fff;">Escriba la contraseña del administrador para confirmar</label>
            <input type="password" id="restoreDangerPassword" class="form-control" autocomplete="current-password" placeholder="Contraseña de administrador">
        </div>
        <div class="restore-danger-actions">
            <button type="button" class="btn btn-secondary" onclick="closeRestoreDangerModal()">Cancelar</button>
            <button type="button" class="btn btn-danger" onclick="confirmRestoreDanger()">Sí, restaurar ahora</button>
        </div>
    </div>
</div>

<script>
var restoreFormPending = null;
function openRestoreDangerModal(e) {
    e.preventDefault();
    restoreFormPending = e.target;
    document.getElementById('restoreDangerPassword').value = '';
    document.getElementById('restoreDangerModal').style.display = 'flex';
    if (typeof initPasswordToggles === 'function') initPasswordToggles(document.getElementById('restoreDangerModal'));
    setTimeout(function(){ document.getElementById('restoreDangerPassword').focus(); }, 50);
    return false;
}
function closeRestoreDangerModal() {
    document.getElementById('restoreDangerModal').style.display = 'none';
    restoreFormPending = null;
}
function confirmRestoreDanger() {
    var pwd = (document.getElementById('restoreDangerPassword').value || '').trim();
    if (!pwd) {
        if (typeof showAlert === 'function') showAlert('Debe escribir la contraseña del administrador', 'error');
        else alert('Debe escribir la contraseña del administrador');
        return;
    }
    var form = restoreFormPending || document.getElementById('backupRestoreForm');
    document.getElementById('restoreConfirmPassword').value = pwd;
    document.getElementById('restoreDangerModal').style.display = 'none';
    restoreFormPending = null;
    if (!form) return;
    form.onsubmit = null;
    form.removeAttribute('onsubmit');
    if (typeof handleAjaxSubmit === 'function') {
        handleAjaxSubmit(form);
    } else {
        form.submit();
    }
}
function openDownloadBackupModal(filename) {
    document.getElementById('downloadBackupFile').value = filename || '';
    document.getElementById('downloadBackupPassword').value = '';
    document.getElementById('downloadBackupModal').style.display = 'flex';
    if (typeof initPasswordToggles === 'function') initPasswordToggles(document.getElementById('downloadBackupModal'));
    setTimeout(function(){ document.getElementById('downloadBackupPassword').focus(); }, 50);
}
function closeDownloadBackupModal() {
    document.getElementById('downloadBackupModal').style.display = 'none';
}
</script>

<script>
function setTheme(t){
    if(t==='dark'){document.body.classList.add('dark-mode');document.getElementById('btnDark').className='btn btn-primary neumorphic-btn';document.getElementById('btnLight').className='btn btn-secondary';}
    else{document.body.classList.remove('dark-mode');document.getElementById('btnLight').className='btn btn-primary neumorphic-btn';document.getElementById('btnDark').className='btn btn-secondary';}
    localStorage.setItem('theme',t);
}

function contrastText(hex){
    var r = parseInt(hex.substr(1,2),16)/255;
    var g = parseInt(hex.substr(3,2),16)/255;
    var b = parseInt(hex.substr(5,2),16)/255;
    function lin(c){ return c <= 0.03928 ? c/12.92 : Math.pow((c+0.055)/1.055, 2.4); }
    var L = 0.2126*lin(r) + 0.7152*lin(g) + 0.0722*lin(b);
    return L > 0.4 ? '#1A1A2E' : '#FFFFFF';
}
function darken(hex){
    var r = Math.round(parseInt(hex.substr(1,2),16)*0.78);
    var g = Math.round(parseInt(hex.substr(3,2),16)*0.78);
    var b = Math.round(parseInt(hex.substr(5,2),16)*0.78);
    return '#' + [r,g,b].map(function(c){ return ('0'+c.toString(16)).slice(-2); }).join('').toUpperCase();
}
function pickColor(hex){
    if(!/^#[0-9A-Fa-f]{6}$/.test(hex)) return;
    hex = hex.toUpperCase();
    document.getElementById('primaryColorInput').value = hex;
    document.getElementById('colorPreview').textContent = hex;
    // Previsualización en vivo
    var root = document.documentElement.style;
    var txt = contrastText(hex);
    root.setProperty('--color-primary', hex);
    root.setProperty('--bg-btn-primary', hex);
    root.setProperty('--bg-btn-primary-hover', darken(hex));
    root.setProperty('--color-btn-primary-text', txt);
    root.setProperty('--color-datetime-icon', hex);
    root.setProperty('--color-datetime-time', hex);
    // Marcar swatch activo
    document.querySelectorAll('#colorPresets .color-swatch').forEach(function(s){
        s.style.borderColor = (s.dataset.color.toUpperCase() === hex) ? 'var(--color-text-primary)' : 'transparent';
    });
}

function setLang(l){
    // Actualizar botones visualmente
    document.querySelectorAll('[id^="btnLang"]').forEach(function(b){ b.className = 'btn btn-secondary'; });
    var activeBtn = document.getElementById('btnLang' + l);
    if (activeBtn) activeBtn.className = 'btn btn-primary neumorphic-btn';
    
    // Guardar en localStorage y enviar al servidor
    localStorage.setItem('lang', l);
    document.getElementById('langInput').value = l;
    
    var fd = new FormData(document.getElementById('langForm'));
    fetch(document.getElementById('langForm').action, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) showAlert('Idioma guardado. Recargue la página para aplicar los cambios.', 'success');
    });
}

function uploadAvatar(input) {
    if (!input.files || !input.files[0]) return;
    var form = document.getElementById('avatarForm');
    var fd = new FormData(form);
    fetch(form.action, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            showAlert(d.message, 'success');
            // Actualizar preview
            var reader = new FileReader();
            reader.onload = function(e) {
                var preview = document.getElementById('avatarPreview');
                preview.innerHTML = '<img src="' + e.target.result + '" alt="Avatar" style="width:100%;height:100%;object-fit:cover;">';
            };
            reader.readAsDataURL(input.files[0]);
        } else {
            showAlert(d.message || 'Error al subir imagen', 'error');
        }
    }).catch(function() {
        showAlert('Error de conexión', 'error');
    });
}

(function(){
    if(localStorage.getItem('theme')==='dark') setTheme('dark'); else document.getElementById('btnLight').className='btn btn-primary neumorphic-btn';
    var savedLang = localStorage.getItem('lang') || '<?php echo $currentLang; ?>';
    var activeBtn = document.getElementById('btnLang' + savedLang);
    if (activeBtn) activeBtn.className = 'btn btn-primary neumorphic-btn';
})();

(function(){
    function activateConfigTab(name) {
        document.querySelectorAll('.config-tab-btn').forEach(function(btn) {
            btn.classList.toggle('active', btn.getAttribute('data-config-tab') === name);
        });
        document.querySelectorAll('.config-tab-panel').forEach(function(panel) {
            panel.classList.toggle('active', panel.id === 'configTab-' + name);
        });
        try {
            if (history.replaceState) {
                history.replaceState(null, '', '#' + name);
            } else {
                location.hash = name;
            }
        } catch (e) {}
    }
    document.querySelectorAll('.config-tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            activateConfigTab(btn.getAttribute('data-config-tab'));
        });
    });
    var hash = (location.hash || '').replace(/^#/, '');
    if (hash === 'teamUsers' || hash === 'usuarios') {
        activateConfigTab('usuarios');
    } else if (hash === 'backup-list' || hash === 'sistema') {
        activateConfigTab('sistema');
    } else if (hash === 'empresa') {
        activateConfigTab('empresa');
    } else if (hash === 'general') {
        activateConfigTab('general');
    }
})();
</script>
