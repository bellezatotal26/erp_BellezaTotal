<?php
$layout = 'superadmin';
$title = 'Super Administrador - Auditorías';
$pageTitle = 'Registro de Auditorías';
$userName = $_SESSION['super_admin_name'] ?? 'Super Admin';

$actionBadgeMap = [
    'create' => 'success',
    'update' => 'info',
    'delete' => 'danger',
    'login' => 'success',
    'logout' => 'warning',
];
?>

<div class="card neumorphic">
    <div class="card-header">
        <h3>Log de Auditoria Global</h3>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Cliente</th>
                        <th>Usuario</th>
                        <th>Accion</th>
                        <th>Modulo</th>
                        <th>Descripcion</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($audits)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--color-text-secondary);">
                                No hay registros de auditoria
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($audits as $audit): ?>
                            <?php
                            $action = $audit['action'] ?? '-';
                            $badgeClass = $actionBadgeMap[$action] ?? 'info';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($audit['created_at'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($audit['tenant_id'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($audit['user_name'] ?? 'Sistema'); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo htmlspecialchars($badgeClass); ?>">
                                        <?php echo htmlspecialchars($action); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($audit['module'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($audit['description'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($audit['ip_address'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
