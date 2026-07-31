<?php
$layout = 'superadmin';
$title = 'Seri ERP - Super Administrador';
$pageTitle = 'Dashboard';
$userName = $_SESSION['super_admin_name'] ?? 'Super Admin';
$stats = $stats ?? [];
$ticketStats = $ticketStats ?? [];
$licenseStats = $licenseStats ?? [];
$recentActivity = $recentActivity ?? [];
$expiringTenants = $expiringTenants ?? [];

$actionLabels = [
    'create' => 'Creación',
    'update' => 'Actualización',
    'delete' => 'Eliminación',
    'login' => 'Inicio de sesión',
    'logout' => 'Cierre de sesión',
];
$actionBadges = [
    'create' => 'badge-success',
    'update' => 'badge-info',
    'delete' => 'badge-danger',
    'login' => 'badge-success',
    'logout' => 'badge-warning',
];
?>

<?php echo flashMessage(); ?>

<!-- Fila 1: KPIs principales -->
<div class="stats-grid">
    <a href="<?php echo $viewInstance->route('superadmin/tenants'); ?>" class="stat-card neumorphic stat-card-link">
        <div class="stat-icon" style="background: rgba(8, 0, 64, 0.1);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary)" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
        </div>
        <div class="stat-info">
            <h4>Total Clientes</h4>
            <div class="stat-value"><?php echo number_format($stats['total_tenants'] ?? 0); ?></div>
        </div>
    </a>
    
    <a href="<?php echo $viewInstance->route('superadmin/tenants'); ?>" class="stat-card neumorphic stat-card-link">
        <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
        </div>
        <div class="stat-info">
            <h4>Clientes Activos</h4>
            <div class="stat-value" style="color: #10B981;"><?php echo number_format($stats['active_tenants'] ?? 0); ?></div>
        </div>
    </a>
    
    <a href="<?php echo $viewInstance->route('superadmin/tenants'); ?>" class="stat-card neumorphic stat-card-link">
        <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
        </div>
        <div class="stat-info">
            <h4>Suspendidos</h4>
            <div class="stat-value" style="color: #F59E0B;"><?php echo number_format($stats['suspended_tenants'] ?? 0); ?></div>
        </div>
    </a>
    
    <a href="<?php echo $viewInstance->route('superadmin/plans'); ?>" class="stat-card neumorphic stat-card-link">
        <div class="stat-icon" style="background: rgba(8, 0, 64, 0.1);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary)" stroke-width="2">
                <line x1="12" y1="1" x2="12" y2="23"></line>
                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
            </svg>
        </div>
        <div class="stat-info">
            <h4>Planes</h4>
            <div class="stat-value"><?php echo number_format($stats['total_plans'] ?? 0); ?></div>
        </div>
    </a>
</div>

<!-- Fila 2: Tickets y Licencias -->
<div class="stats-grid" style="margin-top: 20px;">
    <a href="<?php echo $viewInstance->route('superadmin/tickets'); ?>" class="stat-card neumorphic stat-card-link">
        <div class="stat-icon" style="background: rgba(220, 38, 38, 0.1);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
            </svg>
        </div>
        <div class="stat-info">
            <h4>Tickets Abiertos</h4>
            <div class="stat-value" style="color: #DC2626;"><?php echo number_format($ticketStats['open'] ?? 0); ?></div>
            <small style="color: var(--color-text-secondary);">
                <?php echo number_format($ticketStats['urgent'] ?? 0); ?> urgentes · 
                <?php echo number_format($ticketStats['in_progress'] ?? 0); ?> en progreso
            </small>
        </div>
    </a>
    
    <a href="<?php echo $viewInstance->route('superadmin/licencias'); ?>" class="stat-card neumorphic stat-card-link">
        <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
        </div>
        <div class="stat-info">
            <h4>Licencias Activas</h4>
            <div class="stat-value" style="color: #10B981;"><?php echo number_format($licenseStats['active_subscriptions'] ?? 0); ?></div>
            <small style="color: var(--color-text-secondary);">
                $<?php echo number_format($licenseStats['total_revenue'] ?? 0, 2); ?> facturado
            </small>
        </div>
    </a>
    
    <a href="<?php echo $viewInstance->route('superadmin/licencias'); ?>" class="stat-card neumorphic stat-card-link">
        <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="2">
                <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                <polyline points="17 6 23 6 23 12"></polyline>
            </svg>
        </div>
        <div class="stat-info">
            <h4>Pagos Pendientes</h4>
            <div class="stat-value" style="color: #F59E0B;"><?php echo number_format($licenseStats['pending_payments'] ?? 0); ?></div>
            <small style="color: var(--color-text-secondary);">
                <?php echo number_format($licenseStats['active_sales'] ?? 0); ?> ventas totales
            </small>
        </div>
    </a>
    
    <a href="<?php echo $viewInstance->route('superadmin/tenants'); ?>" class="stat-card neumorphic stat-card-link">
        <div class="stat-icon" style="background: rgba(8, 0, 64, 0.1);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary)" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
            </svg>
        </div>
        <div class="stat-info">
            <h4>Total Usuarios</h4>
            <div class="stat-value"><?php echo number_format($stats['total_users'] ?? 0); ?></div>
        </div>
    </a>
</div>

<!-- Fila 3: Próximos a vencer + Actividad reciente -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
    
    <div class="card neumorphic">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h3>Próximos a Vencer (7 días)</h3>
            <a href="<?php echo $viewInstance->route('superadmin/tenants'); ?>" class="btn btn-sm btn-secondary">Ver clientes</a>
        </div>
        <div class="card-body">
            <?php if (empty($expiringTenants)): ?>
                <p style="text-align: center; color: var(--color-text-secondary); padding: 20px;">
                    No hay suscripciones por vencer próximamente
                </p>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Plan</th>
                                <th>Vence</th>
                                <th>Días</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expiringTenants as $tenant): ?>
                                <?php $remaining = daysRemaining($tenant['subscription_end_date']); ?>
                                <tr class="clickable-row" onclick="window.location='<?php echo $viewInstance->route('superadmin/tenants'); ?>'">
                                    <td><?php echo htmlspecialchars($tenant['company_name']); ?></td>
                                    <td><?php echo htmlspecialchars($tenant['plan_name'] ?? '-'); ?></td>
                                    <td><?php echo formatDate($tenant['subscription_end_date']); ?></td>
                                    <td>
                                        <span class="badge <?php echo $remaining <= 3 ? 'badge-danger' : 'badge-warning'; ?>">
                                            <?php echo $remaining; ?> días
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="card neumorphic">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h3>Actividad Reciente</h3>
            <a href="<?php echo $viewInstance->route('superadmin/audits'); ?>" class="btn btn-sm btn-secondary">Ver auditorías</a>
        </div>
        <div class="card-body">
            <?php if (empty($recentActivity)): ?>
                <p style="text-align: center; color: var(--color-text-secondary); padding: 20px;">
                    No hay actividad registrada
                </p>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Usuario</th>
                                <th>Acción</th>
                                <th>Módulo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentActivity as $activity): ?>
                                <tr class="clickable-row" onclick="window.location='<?php echo $viewInstance->route('superadmin/audits'); ?>'">
                                    <td style="font-size: 12px;"><?php echo formatDateTime($activity['created_at']); ?></td>
                                    <td><?php echo htmlspecialchars($activity['user_name'] ?? 'Sistema'); ?></td>
                                    <td>
                                        <span class="badge <?php echo $actionBadges[$activity['action']] ?? 'badge-info'; ?>">
                                            <?php echo $actionLabels[$activity['action']] ?? ucfirst($activity['action']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($activity['module'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
