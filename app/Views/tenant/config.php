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
$avatarUrl = !empty($settings['user_avatar'])
    ? \SoftNova\Core\media_url((string)$settings['user_avatar'])
    : null;
$isAdmin = $isAdmin ?? (\SoftNova\Core\TenantMiddleware::getRole() === 'admin');
$isManagement = $isManagement ?? \SoftNova\Core\TenantMiddleware::isManagement();
$teamUsers = $teamUsers ?? [];
$jobsPending = $jobsPending ?? 0;
$auditLogs = $auditLogs ?? [];
$auditPagination = $auditPagination ?? ['page' => 1, 'perPage' => 25, 'total' => 0, 'totalPages' => 1];
$auditFilterOptions = $auditFilterOptions ?? ['modules' => [], 'actions' => [], 'users' => []];
$auditFilters = $auditFilters ?? ['module' => '', 'action' => '', 'user_id' => 0, 'q' => '', 'from' => '', 'to' => '', 'severity' => ''];
$auditModuleOptions = $auditModuleOptions ?? [];
$auditActionOptions = $auditActionOptions ?? [];
$auditAnomalies = $auditAnomalies ?? [];
$expenseCategories = $expenseCategories ?? [];
$managedModules = $managedModules ?? \SoftNova\Core\TenantMiddleware::MANAGED_MODULES;
$permActions = $permActions ?? \SoftNova\Core\TenantMiddleware::ACTIONS;
$teamRoleLabels = $teamRoleLabels ?? \SoftNova\Core\TenantMiddleware::teamRoleLabels();
$moduleLabels = [
    'dashboard' => 'Dashboard', 'caja' => 'Caja', 'ventas' => 'Ventas', 'inventario' => 'Inventario',
    'prestamos' => 'Préstamos', 'consignacion' => 'Consignación', 'clientes' => 'Clientes', 'proveedores' => 'Proveedores', 'compras' => 'Compras',
    'cotizaciones' => 'Cotizaciones', 'gastos' => 'Gastos', 'contabilidad' => 'Contabilidad',
    'nomina' => 'Nómina', 'reportes' => 'Reportes',
];
$actionLabels = ['view' => 'Ver', 'create' => 'Crear', 'edit' => 'Editar', 'delete' => 'Eliminar', 'export' => 'Exportar'];
$configBase = $viewInstance->route('app/configuracion');
$docConfig = \SoftNova\Services\SalesDocumentConfig::fromSettings($settings);
$docToggleLabels = \SoftNova\Services\SalesDocumentConfig::toggleLabels();
$logoRel = trim((string)($settings['company_logo'] ?? ''));
// Preview autenticado: evita 404/403 si DocumentRoot o .htaccess bloquean /uploads/
$logoUrl = $logoRel !== ''
    ? \SoftNova\Core\base_url('/app/media?f=' . rawurlencode($logoRel))
    : null;
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
.audit-action-badge{font-size:11px;padding:2px 8px;border-radius:999px;font-weight:600;display:inline-block;}
.audit-action-create{background:rgba(16,185,129,.15);color:var(--dmc-059669, #059669);}
.audit-action-update{background:rgba(59,130,246,.15);color:var(--dmc-2563EB, #2563EB);}
.audit-action-delete{background:rgba(220,38,38,.12);color:var(--dmc-DC2626, #DC2626);}
.audit-action-payment,.audit-action-open,.audit-action-close,.audit-action-movement,.audit-action-receive,.audit-action-adjust,.audit-action-note{background:rgba(245,158,11,.15);color:var(--dmc-D97706, #D97706);}
.audit-action-cancel,.audit-action-anomaly{background:rgba(220,38,38,.12);color:var(--dmc-DC2626, #DC2626);}
.audit-action-default{background:rgba(100,116,139,.15);color:var(--dmc-475569, #475569);}
.audit-filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:14px;align-items:end;}
.audit-anomaly-box{border:1px solid rgba(220,38,38,.25);background:rgba(220,38,38,.05);border-radius:10px;padding:12px 14px;margin-bottom:14px;}
.audit-anomaly-box.ok{border-color:rgba(16,185,129,.3);background:rgba(16,185,129,.06);}
.audit-anomaly-item{display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-top:1px solid rgba(0,0,0,.06);font-size:13px;}
.audit-anomaly-item:first-child{border-top:none;padding-top:0;}
.audit-sev{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;padding:2px 6px;border-radius:4px;flex-shrink:0;}
.audit-sev-danger{background:var(--dmb-FEE2E2, #FEE2E2);color:var(--dmc-B91C1C, #B91C1C);}
.audit-sev-warning{background:var(--dmb-FEF3C7, #FEF3C7);color:var(--dmc-B45309, #B45309);}
.audit-sev-info{background:var(--dmb-E0E7FF, #E0E7FF);color:var(--dmc-3730A3, #3730A3);}
</style>

<nav class="config-tabs" role="tablist" aria-label="Secciones de configuracion">
    <button type="button" class="config-tab-btn active" data-config-tab="general">General</button>
    <?php if ($isAdmin): ?>
    <button type="button" class="config-tab-btn" data-config-tab="usuarios">Usuarios</button>
    <?php endif; ?>
    <?php if ($isManagement): ?>
    <button type="button" class="config-tab-btn" data-config-tab="auditoria">Auditoría</button>
    <button type="button" class="config-tab-btn" data-config-tab="gastos-admin">Administración de gastos</button>
    <?php endif; ?>
    <button type="button" class="config-tab-btn" data-config-tab="empresa">Empresa</button>
    <button type="button" class="config-tab-btn" data-config-tab="documentos">Documentos</button>
    <button type="button" class="config-tab-btn" data-config-tab="sistema">Sistema</button>
</nav>

<div class="config-tab-panel active" id="configTab-general">
<div class="settings-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; max-width: 960px;">
    
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

    <?php if ($isAdmin): ?>
    <div class="card neumorphic">
        <div class="card-header"><h3>Accesos rápidos</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:10px;">
            <button type="button" class="btn btn-secondary" onclick="activateConfigTab('usuarios')">Usuarios del sistema</button>
            <button type="button" class="btn btn-secondary" onclick="activateConfigTab('auditoria')">Registro de auditoría</button>
            <button type="button" class="btn btn-secondary" onclick="activateConfigTab('sistema')">Copias de seguridad</button>
        </div>
    </div>
    <?php endif; ?>
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
                Roles: <strong>User</strong> (elige módulos y permisos), <strong>POS</strong> (Caja/Ventas; gastos y préstamos desde Caja sin ver esos módulos),
                <strong>Gerencia</strong> (módulos marcados + Configuración; requiere su contraseña de administrador).
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
                            <td><span class="badge <?php echo ($tu['role'] ?? '') === 'gerencia' ? 'badge-warning' : 'badge-info'; ?>"><?php echo htmlspecialchars($teamRoleLabels[$tu['role']] ?? $tu['role']); ?></span></td>
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
                    <div id="gerenciaRoleBox" style="display:none;margin-top:4px;padding:12px 14px;border-radius:10px;border:1px solid rgba(217,119,6,.35);background:rgba(245,158,11,.08);">
                        <p style="margin:0 0 10px;font-size:13px;">
                            <strong>Rol Gerencia:</strong> accede a los módulos marcados abajo y a <strong>Configuración</strong>
                            (empresa, documentos, sistema, auditoría y categorías de gasto). La gestión de usuarios, copias de seguridad,
                            caja de arena y borrado general siguen reservadas al administrador.
                        </p>
                        <div class="form-group" id="gerenciaAdminPassGroup" style="margin:0;">
                            <label>Contraseña del administrador * <span class="field-tip" data-tip="Por seguridad, confirme su propia contraseña para asignar el rol Gerencia." tabindex="0">?</span></label>
                            <input type="password" name="admin_password" id="teamAdminPassword" class="form-control" autocomplete="current-password" placeholder="Su contraseña de administrador">
                        </div>
                    </div>
                    <div id="permMatrixWrap" style="margin-top:12px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                            <h4 style="margin:0;">Permisos por módulo</h4>
                            <div style="display:flex;gap:6px;">
                                <button type="button" class="btn btn-sm btn-secondary" onclick="setAllPerms(true)">Marcar todo</button>
                                <button type="button" class="btn btn-sm btn-secondary" onclick="setAllPerms(false)">Desmarcar todo</button>
                            </div>
                        </div>
                        <p style="font-size:12px;color:var(--color-text-secondary);margin-bottom:8px;">
                            Marque Ver / Crear / Editar / Eliminar / Exportar por cada módulo.
                            En <strong>Gastos</strong> y <strong>Préstamos</strong>: solo <em>Crear</em> (sin Ver) permite registrar desde Caja sin ver esos menús.
                            En Préstamos, <em>Editar</em> permite devoluciones desde el módulo.
                        </p>
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
                        Rol POS: acceso a Caja y Ventas. Puede registrar gastos y préstamos desde Caja (sin esos módulos).
                        Home: Caja/POS.
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
    var teamUserOriginalRole = '';
    var teamUserLastRole = '';
    function setAllPerms(on) {
        document.querySelectorAll('.perm-check').forEach(function(c) { c.checked = !!on; });
    }
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
        document.getElementById('teamAdminPassword').value = '';
        teamUserOriginalRole = '';
        teamUserLastRole = 'user';
        setAllPerms(false);
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
        document.getElementById('teamAdminPassword').value = '';
        teamUserOriginalRole = u.role || 'user';
        teamUserLastRole = teamUserOriginalRole;
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
        var isGerencia = role === 'gerencia';
        var needsAdminPass = isGerencia && teamUserOriginalRole !== 'gerencia';
        // Al pasar a Gerencia (nuevo o desde otro rol) se marca todo; el admin desmarca lo que no aplique.
        if (needsAdminPass && teamUserLastRole !== 'gerencia') {
            setAllPerms(true);
        }
        teamUserLastRole = role;
        document.getElementById('permMatrixWrap').style.display = isPos ? 'none' : '';
        document.getElementById('posRoleHint').style.display = isPos ? '' : 'none';
        document.getElementById('gerenciaRoleBox').style.display = isGerencia ? '' : 'none';
        document.getElementById('gerenciaAdminPassGroup').style.display = needsAdminPass ? '' : 'none';
        var adminPass = document.getElementById('teamAdminPassword');
        adminPass.required = needsAdminPass;
        if (!needsAdminPass) {
            adminPass.value = '';
        }
    }
    </script>
</div><!-- /tab usuarios -->
    <?php endif; ?>

    <?php if ($isManagement): ?>

<div class="config-tab-panel" id="configTab-auditoria">
    <div class="card neumorphic">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <h3 style="margin:0;">Registro de auditoría</h3>
            <span style="font-size:12px;color:var(--color-text-secondary);">
                <?php echo (int)($auditPagination['total'] ?? 0); ?> evento(s) registrados
            </span>
        </div>
        <div class="card-body">
            <p style="font-size:13px;color:var(--color-text-secondary);margin:0 0 14px;">
                Historial cruzado de ventas, caja, inventario, facturas de compra, usuarios y configuración.
                El detalle muestra la cadena relacionada (p. ej. venta ↔ salida de stock, factura ↔ entrada).
                Solo visible para administración y gerencia.
            </p>

            <?php if ($isManagement): ?>
                <?php if (empty($auditAnomalies)): ?>
                    <div class="audit-anomaly-box ok">
                        <strong style="font-size:13px;">Sin anomalías detectadas</strong>
                        <p style="margin:4px 0 0;font-size:12px;color:var(--color-text-secondary);">
                            En el rango filtrado (o últimos 30 días) no hay descuadres evidentes entre ventas, facturas e inventario.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="audit-anomaly-box">
                        <strong style="font-size:13px;"><?php echo count($auditAnomalies); ?> alerta(s) de consistencia</strong>
                        <p style="margin:4px 0 8px;font-size:12px;color:var(--color-text-secondary);">
                            Cruce automático: ventas sin stock, facturas sin entrada, stock negativo o salidas huérfanas.
                        </p>
                        <?php foreach ($auditAnomalies as $an): ?>
                            <div class="audit-anomaly-item">
                                <span class="audit-sev audit-sev-<?php echo htmlspecialchars((string)($an['severity'] ?? 'warning')); ?>">
                                    <?php echo htmlspecialchars((string)($an['severity'] ?? 'aviso')); ?>
                                </span>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-weight:600;"><?php echo htmlspecialchars((string)($an['title'] ?? '')); ?></div>
                                    <div style="color:var(--color-text-secondary);"><?php echo htmlspecialchars((string)($an['detail'] ?? '')); ?></div>
                                    <?php if (!empty($an['url'])): ?>
                                        <a href="<?php echo htmlspecialchars((string)$an['url']); ?>" style="font-size:12px;">Ver entidad</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="GET" action="<?php echo $configBase; ?>" class="audit-filters" id="auditFilterForm">
                <input type="hidden" name="audit_page" value="1">
                <div class="form-group" style="margin:0;">
                    <label style="font-size:12px;">Módulo</label>
                    <select name="audit_module" class="form-control">
                        <option value="">Todos</option>
                        <?php foreach ($auditModuleOptions as $opt): ?>
                            <option value="<?php echo htmlspecialchars((string)$opt['value']); ?>" <?php echo ($auditFilters['module'] ?? '') === $opt['value'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)$opt['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:12px;">Acción</label>
                    <select name="audit_action" class="form-control">
                        <option value="">Todas</option>
                        <?php foreach ($auditActionOptions as $opt): ?>
                            <option value="<?php echo htmlspecialchars((string)$opt['value']); ?>" <?php echo ($auditFilters['action'] ?? '') === $opt['value'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)$opt['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:12px;">Usuario</label>
                    <select name="audit_user" class="form-control">
                        <option value="0">Todos</option>
                        <?php foreach ($auditFilterOptions['users'] as $au): ?>
                            <option value="<?php echo (int)$au['user_id']; ?>" <?php echo (int)($auditFilters['user_id'] ?? 0) === (int)$au['user_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)($au['user_name'] ?? 'Usuario')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:12px;">Desde</label>
                    <input type="date" name="audit_from" class="form-control" value="<?php echo htmlspecialchars((string)($auditFilters['from'] ?? '')); ?>">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:12px;">Hasta</label>
                    <input type="date" name="audit_to" class="form-control" value="<?php echo htmlspecialchars((string)($auditFilters['to'] ?? '')); ?>">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:12px;">Buscar</label>
                    <input type="text" name="audit_q" class="form-control" placeholder="Descripción, usuario, IP..." value="<?php echo htmlspecialchars((string)($auditFilters['q'] ?? '')); ?>">
                </div>
                <div style="display:flex;gap:8px;">
                    <button type="submit" class="btn btn-primary">Filtrar</button>
                    <a class="btn btn-secondary" href="<?php echo $configBase; ?>#auditoria">Limpiar</a>
                </div>
            </form>

            <?php if (empty($auditLogs)): ?>
                <p style="text-align:center;color:var(--color-text-secondary);padding:24px 0;">No hay registros de auditoría con los filtros actuales.</p>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha / hora</th>
                                <th>Usuario</th>
                                <th>Módulo</th>
                                <th>Acción</th>
                                <th>Descripción</th>
                                <th>Entidad</th>
                                <th>IP</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($auditLogs as $log): ?>
                            <?php
                            $act = (string)($log['action'] ?? '');
                            $badgeClass = match ($act) {
                                'create' => 'audit-action-create',
                                'update', 'edit', 'note' => 'audit-action-update',
                                'delete', 'cancel', 'anomaly' => 'audit-action-delete',
                                'payment', 'open', 'close', 'movement', 'receive', 'adjust', 'import', 'pay' => 'audit-action-payment',
                                default => 'audit-action-default',
                            };
                            $entityType = (string)($log['entity_type'] ?? '');
                            $entityId = (int)($log['entity_id'] ?? 0);
                            $entityUrl = (string)($log['entity_url'] ?? '');
                            $desc = (string)($log['description'] ?? '');
                            if ($entityType !== '' && $entityId > 0 && str_ends_with($desc, '[' . $entityType . ' #' . $entityId . ']')) {
                                $desc = trim(substr($desc, 0, -strlen('[' . $entityType . ' #' . $entityId . ']')));
                            }
                            ?>
                            <tr>
                                <td style="white-space:nowrap;font-size:13px;"><?php echo date('d/m/Y H:i', strtotime((string)$log['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars((string)($log['user_name'] ?? 'Sistema')); ?></td>
                                <td><?php echo htmlspecialchars((string)($log['module_label'] ?? ($log['module'] ?? ''))); ?></td>
                                <td><span class="audit-action-badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars((string)($log['action_label'] ?? $act)); ?></span>
                                    <?php if (!empty($log['severity'])): ?>
                                        <span class="audit-sev audit-sev-<?php echo htmlspecialchars((string)$log['severity']); ?>" style="margin-left:4px;"><?php echo htmlspecialchars((string)$log['severity']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="max-width:320px;"><?php echo htmlspecialchars($desc); ?></td>
                                <td>
                                    <?php if ($entityType !== '' && $entityId > 0): ?>
                                        <?php if ($entityUrl): ?>
                                            <a href="<?php echo htmlspecialchars($entityUrl); ?>"><?php echo htmlspecialchars($entityType . ' #' . $entityId); ?></a>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($entityType . ' #' . $entityId); ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:12px;color:var(--color-text-secondary);"><?php echo htmlspecialchars((string)($log['ip_address'] ?? '—')); ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" onclick="showAuditDetail(<?php echo (int)$log['id']; ?>)">Detalle</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (($auditPagination['totalPages'] ?? 1) > 1): ?>
                    <?php
                    $auditQuery = static function (int $page) use ($auditFilters, $configBase): string {
                        $params = ['audit_page' => $page];
                        if (($auditFilters['module'] ?? '') !== '') {
                            $params['audit_module'] = $auditFilters['module'];
                        }
                        if (($auditFilters['action'] ?? '') !== '') {
                            $params['audit_action'] = $auditFilters['action'];
                        }
                        if ((int)($auditFilters['user_id'] ?? 0) > 0) {
                            $params['audit_user'] = (int)$auditFilters['user_id'];
                        }
                        if (($auditFilters['q'] ?? '') !== '') {
                            $params['audit_q'] = $auditFilters['q'];
                        }
                        if (($auditFilters['from'] ?? '') !== '') {
                            $params['audit_from'] = $auditFilters['from'];
                        }
                        if (($auditFilters['to'] ?? '') !== '') {
                            $params['audit_to'] = $auditFilters['to'];
                        }
                        return $configBase . '?' . http_build_query($params) . '#auditoria';
                    };
                    ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;flex-wrap:wrap;gap:8px;">
                        <span style="font-size:12px;color:var(--color-text-secondary);">
                            Página <?php echo (int)$auditPagination['page']; ?> de <?php echo (int)$auditPagination['totalPages']; ?>
                            · <?php echo (int)$auditPagination['total']; ?> registros
                        </span>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <?php if ((int)$auditPagination['page'] > 1): ?>
                                <a class="btn btn-sm btn-secondary" href="<?php echo htmlspecialchars($auditQuery((int)$auditPagination['page'] - 1)); ?>">Anterior</a>
                            <?php endif; ?>
                            <?php if ((int)$auditPagination['page'] < (int)$auditPagination['totalPages']): ?>
                                <a class="btn btn-sm btn-secondary" href="<?php echo htmlspecialchars($auditQuery((int)$auditPagination['page'] + 1)); ?>">Siguiente</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div><!-- /tab auditoria -->

    <?php endif; ?>

<?php if ($isManagement): ?>
<div class="config-tab-panel" id="configTab-gastos-admin">
    <?php $viewInstance->partial('expense_admin_config', [
        'expenseCategories' => $expenseCategories,
        'expenseSubgroups' => $expenseSubgroups ?? [],
        'configBase' => $configBase,
    ]); ?>
</div>

<div id="expCatEditModal" class="modal-overlay" style="display:none;z-index:1200;">
    <div class="modal-content neumorphic" style="max-width:420px;">
        <div class="modal-header">
            <h3>Editar categoría</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('expCatEditModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $configBase; ?>?action=expenseCategorySave" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="expCatEditId" value="0">
            <input type="hidden" name="kind" id="expCatEditKind" value="operational">
            <div class="modal-body">
                <div class="form-group">
                    <label>Nombre</label>
                    <input type="text" name="name" id="expCatEditName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Cuenta PUC</label>
                    <input type="text" name="account_code" id="expCatEditAccount" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('expCatEditModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>
<script>
function expCatOpenAddModal() {
    var m = document.getElementById('expCatAddModal');
    if (m) m.style.display = 'flex';
}
function expSubgroupOpenModal() {
    var m = document.getElementById('expSubgroupModal');
    if (m) m.style.display = 'flex';
}
function expCatEdit(id, name, kind, account) {
    document.getElementById('expCatEditId').value = id;
    document.getElementById('expCatEditName').value = name;
    document.getElementById('expCatEditKind').value = kind;
    document.getElementById('expCatEditAccount').value = account || '';
    document.getElementById('expCatEditModal').style.display = 'flex';
}
</script>
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
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
                        <input type="hidden" name="tax_enabled" value="0">
                        <input type="checkbox" name="tax_enabled" value="1"
                            <?php echo (($settings['tax_enabled'] ?? '1') !== '0') ? 'checked' : ''; ?>>
                        Calcular IVA en ventas (caja, POS y cotizaciones)
                    </label>
                    <small style="color:var(--color-text-secondary);display:block;margin-top:6px;">
                        Desactivado: el total de venta no incluye IVA aunque los productos tengan tasa en inventario.
                        Las <strong>compras</strong> siguen registrando IVA por línea de factura.
                    </small>
                </div>
                <div class="form-group">
                    <label>Tasa de Impuesto (%)</label>
                    <?php
                    $taxRateVal = $settings['tax_rate'] ?? '19';
                    if ($taxRateVal === null || $taxRateVal === '') {
                        $taxRateVal = '0';
                    }
                    ?>
                    <input type="number" name="tax_rate" class="form-control" 
                           value="<?php echo htmlspecialchars((string)$taxRateVal); ?>" 
                           step="0.1" min="0" max="100" placeholder="19">
                    <small style="color:var(--color-text-secondary);display:block;margin-top:6px;">
                        Tasa <strong>por defecto</strong> para comodín y productos sin % definido (solo si aplica IVA por producto).
                        En <strong>compras</strong> el IVA sigue yendo por línea de la factura.
                    </small>
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
                        <input type="hidden" name="product_tax_enabled" value="0">
                        <input type="checkbox" name="product_tax_enabled" value="1"
                            <?php echo (($settings['product_tax_enabled'] ?? '0') !== '0') ? 'checked' : ''; ?>
                            <?php echo (($settings['tax_enabled'] ?? '1') === '0') ? ' disabled' : ''; ?>>
                        Aplicar IVA por producto (según tasa en Inventario)
                    </label>
                    <small style="color:var(--color-text-secondary);display:block;margin-top:6px;">
                        <strong>Activado:</strong> cada producto usa su % IVA de inventario
                        (<strong>0</strong> = exento, <strong>19</strong> = gravado, etc.).
                        Los que están en <strong>0</strong> no heredan la tasa global.
                        <strong>Desactivado:</strong> los productos de catálogo no llevan IVA en ventas
                        (útil si vende mostly exento). El comodín sigue la tasa global de arriba.
                    </small>
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

    <!-- Fecha, hora y zona horaria de negocio -->
    <div class="card neumorphic">
        <div class="card-header">
            <h3>Fecha, hora y zona horaria</h3>
        </div>
        <div class="card-body">
            <?php
            $timezones = $timezones ?? \SoftNova\Core\BusinessTimezone::options();
            $currentTz = \SoftNova\Core\BusinessTimezone::normalize($settings['timezone'] ?? \SoftNova\Core\BusinessTimezone::DEFAULT);
            $clock = $businessClock ?? \SoftNova\Core\BusinessTimezone::clientPayload();
            ?>
            <div style="margin-bottom:14px;padding:12px 14px;border-radius:10px;background:var(--color-bg-secondary,rgba(0,0,0,.04));">
                <div style="font-size:13px;color:var(--color-text-secondary);margin-bottom:4px;">Reloj de negocio actual</div>
                <div style="font-size:18px;font-weight:700;" id="cfgBusinessClock">
                    <?php echo htmlspecialchars(date('d/m/Y H:i:s', strtotime((string)($clock['now'] ?? 'now')))); ?>
                </div>
                <div style="font-size:12px;color:var(--color-text-secondary);margin-top:4px;">
                    Zona: <?php echo htmlspecialchars((string)($clock['label'] ?? $currentTz)); ?>
                    · Usada en ventas, caja, facturas, reportes y <code>NOW()</code> de MySQL.
                </div>
            </div>
            <form method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=save" data-ajax="true">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <div class="form-group">
                    <label>Zona horaria de la empresa</label>
                    <select name="timezone" class="form-control" required>
                        <?php foreach ($timezones as $tzId => $tzLabel): ?>
                            <option value="<?php echo htmlspecialchars($tzId); ?>" <?php echo $currentTz === $tzId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tzLabel); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (!isset($timezones[$currentTz])): ?>
                            <option value="<?php echo htmlspecialchars($currentTz); ?>" selected>
                                <?php echo htmlspecialchars($currentTz); ?> (actual)
                            </option>
                        <?php endif; ?>
                    </select>
                    <small style="color:var(--color-text-secondary);display:block;margin-top:6px;">
                        Colombia debe usar <strong>America/Bogota</strong>. No depende del reloj del PC del cajero ni de UTC del navegador.
                    </small>
                </div>
                <button type="submit" class="btn btn-primary">Guardar zona horaria</button>
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

<div class="config-tab-panel" id="configTab-documentos">
<div class="settings-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px;">

    <div class="card neumorphic">
        <div class="card-header"><h3>Datos en documentos</h3></div>
        <div class="card-body">
            <p style="font-size:13px;color:var(--color-text-secondary);margin:0 0 14px;">
                Información que puede mostrarse en el ticket 58mm y en el PDF carta. Use los interruptores de cada formato para mostrar u ocultar.
            </p>
            <form method="POST" action="<?php echo $configBase; ?>?action=uploadLogo" enctype="multipart/form-data" data-ajax="true" id="companyLogoForm" style="margin-bottom:12px;">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:10px;">
                    <div id="companyLogoPreview" style="width:88px;height:88px;border:1px dashed var(--color-border);border-radius:10px;display:flex;align-items:center;justify-content:center;overflow:hidden;background:var(--bg-input,#f8fafc);">
                        <?php if ($logoUrl): ?>
                            <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Logo" style="max-width:100%;max-height:100%;object-fit:contain;">
                        <?php else: ?>
                            <span style="font-size:11px;color:var(--color-text-secondary);">Sin logo</span>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:180px;">
                        <label style="font-size:13px;" for="companyLogoInput">Logo de empresa</label>
                        <input type="file" name="logo" id="companyLogoInput" accept="image/jpeg,image/png,image/gif,image/webp" class="form-control" style="margin-top:4px;" onchange="previewAndUploadCompanyLogo(this)">
                        <small style="color:var(--color-text-secondary);">JPG/PNG/WebP · máx. 2MB · se sube al seleccionar</small>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Subir logo</button>
            </form>
            <?php if ($logoUrl): ?>
            <form method="POST" action="<?php echo $configBase; ?>?action=removeLogo" data-ajax="true" style="margin-bottom:16px;">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <button type="submit" class="btn btn-secondary btn-sm">Quitar logo</button>
            </form>
            <?php endif; ?>

            <div class="form-group">
                <label>NIT / Documento</label>
                <input type="text" name="company_nit" class="form-control" form="docsConfigForm" value="<?php echo htmlspecialchars($settings['company_nit'] ?? ''); ?>" placeholder="900.123.456-7">
            </div>
            <div class="form-group">
                <label>Dirección</label>
                <input type="text" name="company_address" class="form-control" form="docsConfigForm" value="<?php echo htmlspecialchars($settings['company_address'] ?? ''); ?>" placeholder="Calle, ciudad">
            </div>
            <div class="form-group">
                <label>Teléfono</label>
                <input type="text" name="company_phone" class="form-control" form="docsConfigForm" value="<?php echo htmlspecialchars($settings['company_phone'] ?? ''); ?>" placeholder="300 000 0000">
            </div>
            <div class="form-group">
                <label>Correo (también usado al enviar documentos)</label>
                <input type="email" name="company_email" class="form-control" form="docsConfigForm" value="<?php echo htmlspecialchars($settings['company_email'] ?? ''); ?>" placeholder="ventas@empresa.com">
            </div>
        </div>
    </div>

    <div class="card neumorphic">
        <div class="card-header"><h3>Ticket 58mm</h3></div>
        <div class="card-body">
            <p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 12px;">Campos visibles en el ticket térmico / PDF angosto.</p>
            <?php foreach ($docToggleLabels as $key => $label): ?>
                <?php if (in_array($key, ['show_payments_table', 'show_system_footer', 'auto_print_ticket'], true)) continue; ?>
                <label style="display:flex;align-items:center;gap:8px;margin:0 0 8px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" name="doc_ticket_<?php echo htmlspecialchars($key); ?>" value="1" form="docsConfigForm"
                        <?php echo !empty($docConfig['ticket'][$key]) ? 'checked' : ''; ?>
                        style="width:16px;height:16px;">
                    <?php echo htmlspecialchars($label); ?>
                </label>
            <?php endforeach; ?>
            <div class="form-group" style="margin-top:12px;">
                <label>Texto superior adicional</label>
                <textarea name="doc_ticket_header_extra" class="form-control" rows="2" form="docsConfigForm"
                    placeholder="Ej. Régimen simple · www.tienda.com"><?php echo htmlspecialchars((string)($docConfig['ticket']['header_extra'] ?? '')); ?></textarea>
            </div>
            <div class="form-group">
                <label>Mensaje de pie</label>
                <textarea name="doc_ticket_footer_text" class="form-control" rows="2" form="docsConfigForm"><?php echo htmlspecialchars((string)($docConfig['ticket']['footer_text'] ?? 'Gracias por su compra')); ?></textarea>
            </div>
            <label style="display:flex;align-items:flex-start;gap:8px;margin:12px 0 0;font-size:13px;cursor:pointer;">
                <input type="checkbox" name="doc_ticket_auto_print_ticket" value="1" form="docsConfigForm"
                    <?php echo !empty($docConfig['ticket']['auto_print_ticket']) ? 'checked' : ''; ?>
                    style="width:16px;height:16px;margin-top:2px;">
                <span>
                    Imprimir tirilla al confirmar la venta
                    <span style="display:block;font-size:12px;color:var(--color-text-secondary);font-weight:400;">
                        Abre el diálogo de impresión. En Windows, ponga la térmica 58 mm como impresora predeterminada para no elegirla cada vez.
                    </span>
                </span>
            </label>
        </div>
    </div>

    <div class="card neumorphic">
        <div class="card-header"><h3>PDF Carta (A4)</h3></div>
        <div class="card-body">
            <p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 12px;">Campos visibles en la factura / documento en tamaño carta.</p>
            <?php foreach ($docToggleLabels as $key => $label): ?>
                <label style="display:flex;align-items:center;gap:8px;margin:0 0 8px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" name="doc_a4_<?php echo htmlspecialchars($key); ?>" value="1" form="docsConfigForm"
                        <?php echo !empty($docConfig['a4'][$key]) ? 'checked' : ''; ?>
                        style="width:16px;height:16px;">
                    <?php echo htmlspecialchars($label); ?>
                </label>
            <?php endforeach; ?>
            <div class="form-group" style="margin-top:12px;">
                <label>Color de encabezado / tabla</label>
                <input type="color" name="doc_a4_header_color" form="docsConfigForm" class="form-control"
                       value="<?php echo htmlspecialchars((string)($docConfig['a4']['header_color'] ?? '#0D7C4A')); ?>"
                       style="width:70px;height:40px;padding:4px;cursor:pointer;">
            </div>
            <div class="form-group">
                <label>Texto superior adicional</label>
                <textarea name="doc_a4_header_extra" class="form-control" rows="2" form="docsConfigForm"
                    placeholder="Ej. Resolución DIAN · sucursal"><?php echo htmlspecialchars((string)($docConfig['a4']['header_extra'] ?? '')); ?></textarea>
            </div>
            <div class="form-group">
                <label>Mensaje de pie personalizado</label>
                <textarea name="doc_a4_footer_text" class="form-control" rows="2" form="docsConfigForm"><?php echo htmlspecialchars((string)($docConfig['a4']['footer_text'] ?? '')); ?></textarea>
            </div>
            <button type="submit" form="docsConfigForm" class="btn btn-primary" style="width:100%;margin-top:8px;">Guardar documentos</button>
        </div>
    </div>

</div>
<form method="POST" action="<?php echo $configBase; ?>?action=saveDocuments" data-ajax="true" id="docsConfigForm" style="display:none;">
    <?php echo \SoftNova\Core\csrf_field(); ?>
</form>
</div><!-- /tab documentos -->

<div class="config-tab-panel" id="configTab-sistema">
<div class="config-sistema-layout">

    <!-- Suscripción -->
    <div class="card neumorphic config-sistema-sub">
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
    <div class="card neumorphic config-sistema-prefs">
        <div class="card-header">
            <h3>⚙️ Preferencias</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=save" data-ajax="true">
                <?php echo \SoftNova\Core\csrf_field(); ?>

                <div class="config-prefs-grid">
                    <div class="form-group" style="margin:0;">
                        <label>Prefijo de Factura</label>
                        <input type="text" name="invoice_prefix" class="form-control"
                               value="<?php echo htmlspecialchars($settings['invoice_prefix'] ?? 'FAC-'); ?>">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Alerta de Stock Bajo</label>
                        <input type="number" name="low_stock_alert" class="form-control"
                               value="<?php echo htmlspecialchars($settings['low_stock_alert'] ?? '5'); ?>"
                               min="1" placeholder="5">
                        <small style="color: var(--color-text-secondary);">Se alertará cuando el stock esté por debajo de este valor.</small>
                    </div>
                    <div class="form-group config-prefs-span" style="margin:0;">
                        <label>Webhook venta fallida/cancelada (opcional)</label>
                        <input type="url" name="webhook_sale_url" class="form-control"
                               value="<?php echo htmlspecialchars($settings['webhook_sale_url'] ?? ''); ?>"
                               placeholder="https://hooks.ejemplo.com/venta">
                        <small style="color: var(--color-text-secondary);">
                            URL externa (Zapier, Make, n8n, Slack, etc.). Si se cancela o falla una venta, el ERP encola un
                            <strong>POST JSON</strong> con el evento (<code>sale.cancelled</code> / <code>sale.failed</code>),
                            id de venta y mensaje. Déjelo vacío si no lo usa.
                        </small>
                    </div>
                </div>

                <div class="config-prefs-blocks">
                    <div class="config-prefs-block">
                        <h4 class="config-prefs-block-title">Secuencia automática de EAN</h4>
                        <p class="config-prefs-block-help">
                            En Inventario puede escribir el EAN a mano o usar <strong>Generar EAN</strong>,
                            que toma el siguiente número de esta secuencia.
                        </p>
                        <div class="config-prefs-fields">
                            <div class="form-group" style="margin:0;">
                                <label>Prefijo</label>
                                <input type="text" name="ean_prefix" class="form-control" inputmode="numeric"
                                       value="<?php echo htmlspecialchars($settings['ean_prefix'] ?? '200'); ?>"
                                       maxlength="12" placeholder="200">
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label>Siguiente número</label>
                                <input type="number" name="ean_next" class="form-control" min="1"
                                       value="<?php echo htmlspecialchars($settings['ean_next'] ?? '1'); ?>">
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label>Longitud</label>
                                <select name="ean_length" class="form-control">
                                    <?php $eanLen = (string)($settings['ean_length'] ?? '13'); ?>
                                    <option value="13" <?php echo $eanLen === '13' ? 'selected' : ''; ?>>EAN-13</option>
                                    <option value="8" <?php echo $eanLen === '8' ? 'selected' : ''; ?>>EAN-8</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin:0;display:flex;align-items:flex-end;">
                                <label style="display:flex;gap:8px;align-items:center;font-weight:500;">
                                    <input type="checkbox" name="ean_check_digit" value="1"
                                        <?php echo ($settings['ean_check_digit'] ?? '1') !== '0' ? 'checked' : ''; ?>>
                                    Calcular dígito de control
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="config-prefs-block">
                        <h4 class="config-prefs-block-title">Secuencia automática de SKU</h4>
                        <p class="config-prefs-block-help">
                            En Inventario puede escribir el SKU a mano o usar <strong>Generar SKU</strong>
                            (ej. SKU-000001).
                        </p>
                        <div class="config-prefs-fields">
                            <div class="form-group" style="margin:0;">
                                <label>Prefijo</label>
                                <input type="text" name="sku_prefix" class="form-control"
                                       value="<?php echo htmlspecialchars($settings['sku_prefix'] ?? 'SKU-'); ?>"
                                       maxlength="40" placeholder="SKU-">
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label>Siguiente número</label>
                                <input type="number" name="sku_next" class="form-control" min="1"
                                       value="<?php echo htmlspecialchars($settings['sku_next'] ?? '1'); ?>">
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label>Ceros (padding)</label>
                                <input type="number" name="sku_pad" class="form-control" min="3" max="12"
                                       value="<?php echo htmlspecialchars($settings['sku_pad'] ?? '6'); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="margin-top:16px;">Guardar Preferencias</button>
            </form>
        </div>
    </div>

    <!-- Copia de seguridad -->
    <?php
    $backups = $backups ?? [];
    $backupEnabled = ($settings['backup_enabled'] ?? '0') === '1';
    $backupTime = $settings['backup_time'] ?? '02:00';
    $backupLast = $settings['backup_last_run'] ?? '';
    $isAdmin = \SoftNova\Core\TenantMiddleware::getRole() === 'admin';
    ?>
    <div class="card neumorphic config-sistema-backup">
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

    <?php if ($isAdmin): ?>
    <?php
        $sandboxStatus = $sandboxStatus ?? \SoftNova\Services\SandboxService::status();
        $sandboxActive = !empty($sandboxStatus['active']);
    ?>
    <!-- Modo caja de arena -->
    <div class="card neumorphic" style="grid-column: 1 / -1;border:1px solid <?php echo $sandboxActive ? '#D97706' : '#2563EB'; ?>;">
        <div class="card-header" style="background:<?php echo $sandboxActive ? 'rgba(217,119,6,0.12)' : 'rgba(37,99,235,0.08)'; ?>;">
            <h3 style="color:<?php echo $sandboxActive ? '#B45309' : '#1D4ED8'; ?>;">
                <?php echo $sandboxActive ? 'Modo caja de arena — ACTIVO' : 'Modo caja de arena (pruebas)'; ?>
            </h3>
        </div>
        <div class="card-body">
            <p style="color:var(--color-text-secondary);font-size:13px;margin-bottom:12px;">
                Clona la base de datos real a un espejo de pruebas. Mientras esté activo <strong>solo en su sesión</strong>,
                ventas, compras, caja e inventario afectan el espejo; el resto del equipo sigue en producción.
                Al volver a producción, los movimientos de prueba <strong>no se aplican</strong> a los datos reales.
            </p>
            <p style="font-size:12px;color:var(--color-text-secondary);margin-bottom:14px;">
                Producción: <code><?php echo htmlspecialchars((string)($sandboxStatus['production_db'] ?? '')); ?></code>
                · Espejo: <code><?php echo htmlspecialchars((string)($sandboxStatus['sandbox_db'] ?? '')); ?></code>
                <?php if (!empty($sandboxStatus['cloned_at'])): ?>
                    · Clonado: <?php echo htmlspecialchars((string)$sandboxStatus['cloned_at']); ?>
                <?php endif; ?>
            </p>
            <?php if ($sandboxActive): ?>
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
                    <form method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=sandboxExit" style="display:inline;">
                        <?php echo \SoftNova\Core\csrf_field(); ?>
                        <input type="hidden" name="drop_sandbox" value="0">
                        <button type="submit" class="btn btn-primary">Volver a producción</button>
                    </form>
                    <form method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=sandboxExit" id="sandboxDropForm" style="display:inline;" onsubmit="return openSandboxDropModal(event)">
                        <?php echo \SoftNova\Core\csrf_field(); ?>
                        <input type="hidden" name="drop_sandbox" value="1">
                        <input type="hidden" name="confirm_password" id="sandboxDropPassword" value="">
                        <button type="submit" class="btn btn-danger">Volver y eliminar espejo</button>
                    </form>
                    <form method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=sandboxEnter" id="sandboxRefreshForm" style="display:inline;" onsubmit="return openSandboxEnterModal(event, this)">
                        <?php echo \SoftNova\Core\csrf_field(); ?>
                        <input type="hidden" name="refresh" value="1">
                        <input type="hidden" name="confirm_password" class="sandbox-enter-password" value="">
                        <button type="submit" class="btn btn-secondary">Reclonar desde producción</button>
                    </form>
                </div>
            <?php else: ?>
                <form method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=sandboxEnter" id="sandboxEnterForm" onsubmit="return openSandboxEnterModal(event, this)">
                    <?php echo \SoftNova\Core\csrf_field(); ?>
                    <input type="hidden" name="refresh" value="1">
                    <input type="hidden" name="confirm_password" class="sandbox-enter-password" value="">
                    <button type="submit" class="btn btn-primary">Activar modo caja de arena</button>
                </form>
                <p style="font-size:12px;color:var(--color-text-secondary);margin-top:10px;">
                    En hosting compartido el usuario MySQL <strong>no puede crear bases</strong>. Hágalo una vez en cPanel:
                    <br>1) <strong>Bases de datos MySQL</strong> → crear <code><?php echo htmlspecialchars((string)($sandboxStatus['sandbox_db'] ?? '')); ?></code>
                    <br>2) Agregar el usuario <code>bellezat_master</code> (el mismo de la app) con <strong>todos los privilegios</strong>
                    <br>3) Volver aquí y pulsar «Activar modo caja de arena»
                </p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <!-- Zona de peligro: borrado general -->
    <div class="card neumorphic" style="grid-column: 1 / -1;border:1px solid #DC2626;">
        <div class="card-header" style="background:rgba(220,38,38,0.08);">
            <h3 style="color:var(--dmc-B91C1C, #B91C1C);">Zona de peligro — Borrado general<?php echo !empty($sandboxStatus['active']) ? ' (solo espejo)' : ''; ?></h3>
        </div>
        <div class="card-body">
            <p style="color:var(--color-text-secondary);font-size:13px;margin-bottom:12px;">
                Elimina <strong>todos</strong> los datos operativos: ventas, inventario, compras, clientes, proveedores,
                caja, nómina, asientos contables, usuarios del equipo, etc.
            </p>
            <p style="color:var(--color-text-secondary);font-size:13px;margin-bottom:16px;">
                Se conservan: su usuario administrador actual (misma contraseña), preferencias de configuración
                y el catálogo contable (PUC). <strong>Esta acción no se puede deshacer</strong> —
                descargue una copia de seguridad antes.
            </p>
            <form method="POST" action="<?php echo $viewInstance->route('app/configuracion'); ?>?action=wipeDatabase" id="wipeDatabaseForm" onsubmit="return openWipeDangerModal(event)">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <input type="hidden" name="confirm_password" id="wipeConfirmPassword" value="">
                <input type="hidden" name="confirm_phrase" id="wipeConfirmPhrase" value="">
                <button type="submit" class="btn btn-danger">Borrar todo y limpiar base de datos</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
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

<!-- Modal alto riesgo: borrado general BD -->
<div id="wipeDangerModal" class="modal-overlay restore-danger-overlay" style="display:none;">
    <div class="restore-danger-modal" role="dialog" aria-modal="true" aria-labelledby="wipeDangerTitle">
        <h3 id="wipeDangerTitle">BORRADO GENERAL DE DATOS</h3>
        <p class="restore-danger-warn">¿Estás seguro?</p>
        <p class="restore-danger-text">
            Se eliminarán ventas, inventario, compras, clientes, proveedores, caja, nómina y el resto de datos operativos.
            Solo quedará <strong>su usuario administrador</strong>, la configuración y el PUC contable.
            <strong>No se puede deshacer</strong>.
        </p>
        <div class="form-group" style="text-align:left;">
            <label style="color:#fff;">Escriba <strong>BORRAR TODO</strong> para confirmar</label>
            <input type="text" id="wipeDangerPhrase" class="form-control" autocomplete="off" placeholder="BORRAR TODO" style="text-transform:uppercase;">
        </div>
        <div class="form-group" style="text-align:left;">
            <label style="color:#fff;">Contraseña de administrador</label>
            <input type="password" id="wipeDangerPassword" class="form-control" autocomplete="current-password" placeholder="Contraseña de administrador">
        </div>
        <div class="restore-danger-actions">
            <button type="button" class="btn btn-secondary" onclick="closeWipeDangerModal()">Cancelar</button>
            <button type="button" class="btn btn-danger" onclick="confirmWipeDanger()">Sí, borrar todo</button>
        </div>
    </div>
</div>

<!-- Modal: activar / reclonar caja de arena -->
<div id="sandboxEnterModal" class="modal-overlay restore-danger-overlay" style="display:none;">
    <div class="restore-danger-modal" role="dialog" aria-modal="true" aria-labelledby="sandboxEnterTitle">
        <h3 id="sandboxEnterTitle">ACTIVAR CAJA DE ARENA</h3>
        <p class="restore-danger-warn">Se clonará la base de producción</p>
        <p class="restore-danger-text">
            Su sesión pasará a un espejo de pruebas. El resto del equipo sigue en producción.
            La primera vez (o al reclonar) puede tardar varios minutos.
        </p>
        <div class="form-group" style="text-align:left;">
            <label style="color:#fff;">Contraseña de administrador</label>
            <input type="password" id="sandboxEnterPassword" class="form-control" autocomplete="current-password" placeholder="Contraseña de administrador">
        </div>
        <div class="restore-danger-actions">
            <button type="button" class="btn btn-secondary" onclick="closeSandboxEnterModal()">Cancelar</button>
            <button type="button" class="btn btn-primary" onclick="confirmSandboxEnter()">Activar</button>
        </div>
    </div>
</div>

<!-- Modal: salir y eliminar espejo -->
<div id="sandboxDropModal" class="modal-overlay restore-danger-overlay" style="display:none;">
    <div class="restore-danger-modal" role="dialog" aria-modal="true" aria-labelledby="sandboxDropTitle">
        <h3 id="sandboxDropTitle">ELIMINAR ESPEJO DE PRUEBAS</h3>
        <p class="restore-danger-warn">¿Volver a producción y borrar el espejo?</p>
        <p class="restore-danger-text">
            Se cierra el modo caja de arena y se elimina la base espejo. Producción no se modifica.
        </p>
        <div class="form-group" style="text-align:left;">
            <label style="color:#fff;">Contraseña de administrador</label>
            <input type="password" id="sandboxDropPasswordInput" class="form-control" autocomplete="current-password" placeholder="Contraseña de administrador">
        </div>
        <div class="restore-danger-actions">
            <button type="button" class="btn btn-secondary" onclick="closeSandboxDropModal()">Cancelar</button>
            <button type="button" class="btn btn-danger" onclick="confirmSandboxDrop()">Sí, eliminar espejo</button>
        </div>
    </div>
</div>

<?php if ($isManagement): ?>
<div id="auditDetailModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:640px;">
        <div class="modal-header">
            <h3 id="auditDetailTitle">Detalle de auditoría</h3>
            <button type="button" class="modal-close" onclick="closeAuditDetailModal()">&times;</button>
        </div>
        <div class="modal-body" id="auditDetailBody">
            <p class="dashboard-empty">Cargando...</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeAuditDetailModal()">Cerrar</button>
        </div>
    </div>
</div>
<?php endif; ?>

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
function closeAuditDetailModal() {
    var modal = document.getElementById('auditDetailModal');
    if (modal) modal.style.display = 'none';
}
function escapeAuditHtml(value) {
    var div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
}
function showAuditDetail(id) {
    var modal = document.getElementById('auditDetailModal');
    var body = document.getElementById('auditDetailBody');
    if (!modal || !body) return;
    body.innerHTML = '<p class="dashboard-empty">Cargando...</p>';
    modal.style.display = 'flex';
    var url = <?php echo json_encode($configBase); ?> + '?action=auditDetail&id=' + encodeURIComponent(id);
    fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (!data.success || !data.log) throw new Error(data.message || 'No se pudo cargar');
            var log = data.log;
            document.getElementById('auditDetailTitle').textContent = log.action_label + ' · ' + log.module_label;
            var html = '<dl style="margin:0;display:grid;grid-template-columns:120px 1fr;gap:8px 12px;font-size:13px;">';
            html += '<dt style="color:var(--color-text-secondary);">Fecha</dt><dd>' + escapeAuditHtml(log.created_at) + '</dd>';
            html += '<dt style="color:var(--color-text-secondary);">Usuario</dt><dd>' + escapeAuditHtml(log.user_name) + '</dd>';
            html += '<dt style="color:var(--color-text-secondary);">Módulo</dt><dd>' + escapeAuditHtml(log.module_label) + '</dd>';
            html += '<dt style="color:var(--color-text-secondary);">Acción</dt><dd>' + escapeAuditHtml(log.action_label) + '</dd>';
            html += '<dt style="color:var(--color-text-secondary);">Descripción</dt><dd>' + escapeAuditHtml(log.description) + '</dd>';
            if (log.entity_type && log.entity_id) {
                html += '<dt style="color:var(--color-text-secondary);">Entidad</dt><dd>';
                if (log.entity_url) {
                    html += '<a href="' + escapeAuditHtml(log.entity_url) + '">' + escapeAuditHtml(log.entity_type + ' #' + log.entity_id) + '</a>';
                } else {
                    html += escapeAuditHtml(log.entity_type + ' #' + log.entity_id);
                }
                html += '</dd>';
            }
            html += '<dt style="color:var(--color-text-secondary);">IP</dt><dd>' + escapeAuditHtml(log.ip_address || '—') + '</dd>';
            if (log.user_agent) {
                html += '<dt style="color:var(--color-text-secondary);">Navegador</dt><dd style="word-break:break-word;">' + escapeAuditHtml(log.user_agent) + '</dd>';
            }
            html += '</dl>';

            var anomalies = data.anomalies || [];
            if (anomalies.length) {
                html += '<h4 style="margin:18px 0 8px;font-size:14px;">Alertas de esta entidad</h4>';
                anomalies.forEach(function(an) {
                    html += '<div class="audit-anomaly-item"><span class="audit-sev audit-sev-' + escapeAuditHtml(an.severity || 'warning') + '">'
                        + escapeAuditHtml(an.severity || 'aviso') + '</span><div><strong>' + escapeAuditHtml(an.title || '')
                        + '</strong><div style="color:var(--color-text-secondary);">' + escapeAuditHtml(an.detail || '') + '</div></div></div>';
                });
            }

            var related = data.related || [];
            if (related.length) {
                html += '<h4 style="margin:18px 0 8px;font-size:14px;">Eventos relacionados</h4>';
                html += '<div style="max-height:240px;overflow:auto;border:1px solid rgba(0,0,0,.06);border-radius:8px;padding:8px 10px;">';
                related.forEach(function(ev) {
                    html += '<div style="padding:6px 0;border-bottom:1px solid rgba(0,0,0,.04);font-size:12px;">';
                    html += '<div style="color:var(--color-text-secondary);">' + escapeAuditHtml(ev.at || '') + ' · ' + escapeAuditHtml(ev.source || '') + '</div>';
                    html += '<strong>' + escapeAuditHtml(ev.label || '') + '</strong>';
                    html += '<div>' + escapeAuditHtml(ev.detail || '') + '</div>';
                    html += '</div>';
                });
                html += '</div>';
            } else {
                html += '<p style="margin:16px 0 0;font-size:12px;color:var(--color-text-secondary);">Sin eventos relacionados en inventario/compras/auditoría.</p>';
            }

            body.innerHTML = html;
        })
        .catch(function(err){
            body.innerHTML = '<p class="dashboard-empty" style="color:var(--dmc-DC2626, #DC2626);">' + escapeAuditHtml(err.message || 'Error') + '</p>';
        });
}
function openWipeDangerModal(e) {
    e.preventDefault();
    document.getElementById('wipeDangerPhrase').value = '';
    document.getElementById('wipeDangerPassword').value = '';
    document.getElementById('wipeDangerModal').style.display = 'flex';
    if (typeof initPasswordToggles === 'function') initPasswordToggles(document.getElementById('wipeDangerModal'));
    setTimeout(function(){ document.getElementById('wipeDangerPhrase').focus(); }, 50);
    return false;
}
function closeWipeDangerModal() {
    document.getElementById('wipeDangerModal').style.display = 'none';
}
function confirmWipeDanger() {
    var phrase = (document.getElementById('wipeDangerPhrase').value || '').trim().toUpperCase();
    var pwd = (document.getElementById('wipeDangerPassword').value || '').trim();
    if (phrase !== 'BORRAR TODO') {
        if (typeof showAlert === 'function') showAlert('Debe escribir exactamente BORRAR TODO', 'error');
        else alert('Debe escribir exactamente BORRAR TODO');
        return;
    }
    if (!pwd) {
        if (typeof showAlert === 'function') showAlert('Debe escribir la contraseña del administrador', 'error');
        else alert('Debe escribir la contraseña del administrador');
        return;
    }
    var form = document.getElementById('wipeDatabaseForm');
    document.getElementById('wipeConfirmPhrase').value = phrase;
    document.getElementById('wipeConfirmPassword').value = pwd;
    document.getElementById('wipeDangerModal').style.display = 'none';
    if (!form) return;
    form.onsubmit = null;
    form.removeAttribute('onsubmit');
    if (typeof handleAjaxSubmit === 'function') {
        handleAjaxSubmit(form);
    } else {
        form.submit();
    }
}

var sandboxEnterFormPending = null;
function openSandboxEnterModal(e, form) {
    e.preventDefault();
    sandboxEnterFormPending = form || e.target;
    document.getElementById('sandboxEnterPassword').value = '';
    document.getElementById('sandboxEnterModal').style.display = 'flex';
    if (typeof initPasswordToggles === 'function') initPasswordToggles(document.getElementById('sandboxEnterModal'));
    setTimeout(function(){ document.getElementById('sandboxEnterPassword').focus(); }, 50);
    return false;
}
function closeSandboxEnterModal() {
    document.getElementById('sandboxEnterModal').style.display = 'none';
    sandboxEnterFormPending = null;
}
function confirmSandboxEnter() {
    var pwd = (document.getElementById('sandboxEnterPassword').value || '').trim();
    if (!pwd) {
        if (typeof showAlert === 'function') showAlert('Debe escribir la contraseña del administrador', 'error');
        else alert('Debe escribir la contraseña del administrador');
        return;
    }
    var form = sandboxEnterFormPending || document.getElementById('sandboxEnterForm');
    if (!form) return;
    var hidden = form.querySelector('.sandbox-enter-password');
    if (hidden) hidden.value = pwd;
    document.getElementById('sandboxEnterModal').style.display = 'none';
    form.onsubmit = null;
    form.removeAttribute('onsubmit');
    if (typeof showAlert === 'function') showAlert('Clonando base de datos… puede tardar unos minutos', 'info');
    if (typeof handleAjaxSubmit === 'function') {
        handleAjaxSubmit(form);
    } else {
        form.submit();
    }
}
function openSandboxDropModal(e) {
    e.preventDefault();
    document.getElementById('sandboxDropPasswordInput').value = '';
    document.getElementById('sandboxDropModal').style.display = 'flex';
    if (typeof initPasswordToggles === 'function') initPasswordToggles(document.getElementById('sandboxDropModal'));
    setTimeout(function(){ document.getElementById('sandboxDropPasswordInput').focus(); }, 50);
    return false;
}
function closeSandboxDropModal() {
    document.getElementById('sandboxDropModal').style.display = 'none';
}
function confirmSandboxDrop() {
    var pwd = (document.getElementById('sandboxDropPasswordInput').value || '').trim();
    if (!pwd) {
        if (typeof showAlert === 'function') showAlert('Debe escribir la contraseña del administrador', 'error');
        else alert('Debe escribir la contraseña del administrador');
        return;
    }
    var form = document.getElementById('sandboxDropForm');
    document.getElementById('sandboxDropPassword').value = pwd;
    document.getElementById('sandboxDropModal').style.display = 'none';
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

function previewAndUploadCompanyLogo(input) {
    if (!input.files || !input.files[0]) return;
    var file = input.files[0];
    if (file.size > 2 * 1024 * 1024) {
        showAlert('El logo no debe superar 2MB', 'error');
        input.value = '';
        return;
    }
    var preview = document.getElementById('companyLogoPreview');
    if (preview && typeof FileReader !== 'undefined') {
        var reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = '<img src="' + e.target.result + '" alt="Logo" style="max-width:100%;max-height:100%;object-fit:contain;">';
        };
        reader.readAsDataURL(file);
    }
    var form = document.getElementById('companyLogoForm');
    if (form && typeof handleAjaxSubmit === 'function') {
        handleAjaxSubmit(form);
    } else if (form) {
        form.requestSubmit ? form.requestSubmit() : form.submit();
    }
}

(function(){
    if(localStorage.getItem('theme')==='dark') setTheme('dark'); else document.getElementById('btnLight').className='btn btn-primary neumorphic-btn';
    var savedLang = localStorage.getItem('lang') || '<?php echo $currentLang; ?>';
    var activeBtn = document.getElementById('btnLang' + savedLang);
    if (activeBtn) activeBtn.className = 'btn btn-primary neumorphic-btn';
})();

(function(){
    window.activateConfigTab = function(name) {
        if (!document.querySelector('.config-tab-btn[data-config-tab="' + name + '"]')) {
            name = 'general';
        }
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
    };
    document.querySelectorAll('.config-tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            window.activateConfigTab(btn.getAttribute('data-config-tab'));
        });
    });
    var hash = (location.hash || '').replace(/^#/, '');
    if (hash === 'teamUsers' || hash === 'usuarios') {
        window.activateConfigTab('usuarios');
    } else if (hash === 'auditoria') {
        window.activateConfigTab('auditoria');
    } else if (hash === 'gastos-admin' || hash === 'gastos_admin') {
        window.activateConfigTab('gastos-admin');
    } else if (hash === 'backup-list' || hash === 'sistema') {
        window.activateConfigTab('sistema');
    } else if (hash === 'empresa') {
        window.activateConfigTab('empresa');
    } else if (hash === 'documentos') {
        window.activateConfigTab('documentos');
    } else if (hash === 'general') {
        window.activateConfigTab('general');
    } else {
        var params = new URLSearchParams(window.location.search);
        if (params.has('audit_page') || params.has('audit_module') || params.has('audit_action')
            || params.has('audit_user') || params.has('audit_q') || params.has('audit_from') || params.has('audit_to')) {
            window.activateConfigTab('auditoria');
        }
    }
})();
(function () {
    var taxOn = document.querySelector('input[name="tax_enabled"][type="checkbox"]');
    var prodOn = document.querySelector('input[name="product_tax_enabled"][type="checkbox"]');
    if (!taxOn || !prodOn) return;
    function syncProductTaxToggle() {
        if (!taxOn.checked) {
            prodOn.checked = false;
            prodOn.disabled = true;
        } else {
            prodOn.disabled = false;
        }
    }
    taxOn.addEventListener('change', syncProductTaxToggle);
    syncProductTaxToggle();
})();
</script>
