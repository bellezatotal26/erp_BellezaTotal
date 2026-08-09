<?php
$tenantName = $tenantName ?? 'Mi Empresa';
$userName = $userName ?? 'Usuario';
$currentUri = $_SERVER['REQUEST_URI'] ?? '';

// Consultar módulos del plan desde BD maestra (siempre actualizado)
$allowedModules = [];
$showAll = true;
$tenantId = $_SESSION['tenant_id'] ?? 0;

if (\SoftNova\Core\is_standalone()) {
    $showAll = true;
    $allowedModules = [];
} elseif ($tenantId > 0) {
    $masterDb = \SoftNova\Core\Database::getInstance();
    $plan = $masterDb->query(
        "SELECT sp.modules FROM tenants t
         JOIN subscription_plans sp ON t.subscription_plan_id = sp.id
         WHERE t.id = ? LIMIT 1",
        [$tenantId]
    )->fetch();
    
    if ($plan && !empty($plan['modules'])) {
        $allowedModules = json_decode($plan['modules'], true) ?: [];
    }
}

// Filtrar siempre por plan (incluso admins solo ven lo que su plan permite)
$showAll = $showAll || empty($allowedModules);

// Definición de módulos disponibles con sus rutas e iconos
$allModules = [
    'dashboard' => ['route' => 'app/dashboard', 'label' => 'Dashboard', 'icon' => 'home'],
    'caja'      => ['route' => 'app/caja', 'label' => 'Caja', 'icon' => 'cash'],
    'ventas'    => ['route' => 'app/ventas', 'label' => 'Ventas', 'icon' => 'cart'],
    'inventario'=> ['route' => 'app/inventario', 'label' => 'Inventario', 'icon' => 'box'],
    'prestamos' => ['route' => 'app/prestamos', 'label' => 'Préstamos', 'icon' => 'loan'],
    'compras'   => ['route' => 'app/compras', 'label' => 'Compras', 'icon' => 'truck'],
    'clientes'  => ['route' => 'app/clientes', 'label' => 'Clientes', 'icon' => 'users'],
    'proveedores'=>['route' => 'app/proveedores', 'label' => 'Proveedores', 'icon' => 'truck'],
    'cotizaciones'=>['route' => 'app/cotizaciones', 'label' => 'Cotizaciones', 'icon' => 'file'],
    'gastos'    => ['route' => 'app/gastos', 'label' => 'Gastos', 'icon' => 'dollar'],
    'reportes'  => ['route' => 'app/reportes', 'label' => 'Reportes', 'icon' => 'file'],
    'contabilidad'=>['route' => 'app/contabilidad', 'label' => 'Contabilidad', 'icon' => 'dollar'],
    'nomina'    => ['route' => 'app/nomina', 'label' => 'Nómina', 'icon' => 'payroll'],
];

// Filtrar módulos por rol del usuario
$userRole = \SoftNova\Core\TenantMiddleware::getRole();

// IA y Soporte ahora están en el header

if (!function_exists('tenantIcon')) {
function tenantIcon(string $name): string {
    return match ($name) {
        'home' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>',
        'cash' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"></rect><line x1="12" y1="1" x2="12" y2="4"></line><line x1="12" y1="20" x2="12" y2="23"></line><line x1="6" y1="8" x2="6.01" y2="8"></line><line x1="10" y1="8" x2="18" y2="8"></line><line x1="6" y1="12" x2="6.01" y2="12"></line><line x1="10" y1="12" x2="18" y2="12"></line><line x1="6" y1="16" x2="18" y2="16"></line></svg>',
        'cart' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>',
        'box' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>',
        'users' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>',
        'truck' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>',
        'file' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>',
        'dollar' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>',
        'star' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>',
        'help' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>',
        'payroll' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="18" rx="2"></rect><line x1="6" y1="8" x2="18" y2="8"></line><line x1="6" y1="12" x2="18" y2="12"></line><line x1="6" y1="16" x2="14" y2="16"></line><circle cx="18" cy="18" r="3"></circle><line x1="18" y1="16" x2="18" y2="20"></line><line x1="16" y1="18" x2="20" y2="18"></line></svg>',
        'loan' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 3h5v5"></path><path d="M8 21H3v-5"></path><path d="M21 3l-9 9"></path><path d="M3 21l9-9"></path><rect x="8" y="8" width="8" height="8" rx="1"></rect></svg>',
        default => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle></svg>',
    };
}
}
?>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h1><?php echo htmlspecialchars($tenantName); ?></h1>
        <button class="sidebar-toggle" onclick="toggleSidebar()">
            <span id="sidebarToggleIcon">«</span>
        </button>
    </div>
    
    <nav class="sidebar-nav">
        <ul>
            <?php foreach ($allModules as $key => $mod): ?>
                <?php
                // POS: solo menú Caja (POS + lista de ventas de la sesión)
                if ($userRole === 'pos' && $key !== 'caja') {
                    continue;
                }
                // Plan: Préstamos va con Inventario si el plan aún no lista "prestamos".
                $planAccess = $showAll
                    || in_array($key, $allowedModules, true)
                    || ($key === 'prestamos' && in_array('inventario', $allowedModules, true));
                $roleAccess = \SoftNova\Core\TenantMiddleware::canAccess($key);
                if ($planAccess && $roleAccess):
                ?>
                    <li>
                        <a href="<?php echo $viewInstance->route($mod['route']); ?>" 
                           class="<?php echo str_contains($currentUri, '/app/' . $key) || ($key === 'dashboard' && str_ends_with($currentUri, '/app/dashboard')) ? 'active' : ''; ?>">
                            <span class="nav-icon"><?php echo tenantIcon($mod['icon']); ?></span>
                            <span class="nav-text"><?php echo $userRole === 'pos' && $key === 'caja' ? 'POS' : $mod['label']; ?></span>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
            
            <!-- Configuración solo admin -->
            <?php if (\SoftNova\Core\TenantMiddleware::canAccess('configuracion')): ?>
            <li>
                <a href="<?php echo $viewInstance->route('app/configuracion'); ?>" 
                   class="<?php echo str_contains($currentUri, '/app/configuracion') ? 'active' : ''; ?>">
                    <span class="nav-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                        </svg>
                    </span>
                    <span class="nav-text">Configuración</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>
    
    <div class="sidebar-footer">
        <p style="color: rgba(255,255,255,0.5); font-size: 11px; text-align: center;">
            <?php echo htmlspecialchars($userName); ?>
        </p>
    </div>
</aside>
