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
    'consignacion' => ['route' => 'app/consignacion', 'label' => 'Consignación', 'icon' => 'consign'],
    'compras'   => ['route' => 'app/compras', 'label' => 'Compras', 'icon' => 'bag'],
    'clientes'  => ['route' => 'app/clientes', 'label' => 'Clientes', 'icon' => 'users'],
    'proveedores'=>['route' => 'app/proveedores', 'label' => 'Proveedores', 'icon' => 'truck'],
    'cotizaciones'=>['route' => 'app/cotizaciones', 'label' => 'Cotizaciones', 'icon' => 'quote'],
    'gastos'    => ['route' => 'app/gastos', 'label' => 'Gastos', 'icon' => 'dollar'],
    'reportes'  => ['route' => 'app/reportes', 'label' => 'Reportes', 'icon' => 'file'],
    'contabilidad'=>['route' => 'app/contabilidad', 'label' => 'Contabilidad', 'icon' => 'ledger'],
    'nomina'    => ['route' => 'app/nomina', 'label' => 'Nómina', 'icon' => 'payroll'],
];

// Secciones visuales del menú (mismo orden de módulos de siempre)
$moduleGroups = [
    'Principal'  => ['dashboard', 'caja', 'ventas'],
    'Inventario' => ['inventario', 'prestamos', 'consignacion', 'compras'],
    'Comercial'  => ['clientes', 'proveedores', 'cotizaciones'],
    'Finanzas'   => ['gastos', 'reportes', 'contabilidad', 'nomina'],
];

// Filtrar módulos por rol del usuario
$userRole = \SoftNova\Core\TenantMiddleware::getRole();

$visibleModules = [];
foreach ($allModules as $key => $mod) {
    // POS: solo menú Caja (POS + lista de ventas de la sesión)
    if ($userRole === 'pos' && $key !== 'caja') {
        continue;
    }
    // Plan: Préstamos / Consignación van con Inventario si el plan aún no los lista.
    $planAccess = $showAll
        || in_array($key, $allowedModules, true)
        || ($key === 'prestamos' && in_array('inventario', $allowedModules, true))
        || ($key === 'consignacion' && in_array('inventario', $allowedModules, true));
    if ($planAccess && \SoftNova\Core\TenantMiddleware::canAccess($key)) {
        $visibleModules[$key] = $mod;
    }
}
$canSeeConfig = \SoftNova\Core\TenantMiddleware::canAccess('configuracion');

$sbWords = preg_split('/\s+/u', trim((string)$tenantName), -1, PREG_SPLIT_NO_EMPTY) ?: ['S'];
$sbMonogram = mb_strtoupper(mb_substr($sbWords[0], 0, 1) . (isset($sbWords[1]) ? mb_substr($sbWords[1], 0, 1) : ''));
$sbUserInitial = mb_strtoupper(mb_substr(trim((string)$userName) ?: 'U', 0, 1));
$sbRoleLabel = \SoftNova\Core\TenantMiddleware::roleDisplayLabel($userRole);

// IA y Soporte ahora están en el header

if (!function_exists('tenantIcon')) {
function tenantIcon(string $name): string {
    $open = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
    return $open . match ($name) {
        'home' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline>',
        'cash' => '<rect x="2" y="4" width="20" height="16" rx="2"></rect><line x1="12" y1="1" x2="12" y2="4"></line><line x1="12" y1="20" x2="12" y2="23"></line><line x1="6" y1="8" x2="6.01" y2="8"></line><line x1="10" y1="8" x2="18" y2="8"></line><line x1="6" y1="12" x2="6.01" y2="12"></line><line x1="10" y1="12" x2="18" y2="12"></line><line x1="6" y1="16" x2="18" y2="16"></line>',
        'cart' => '<circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>',
        'box' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'truck' => '<rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle>',
        'bag' => '<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path>',
        'quote' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><line x1="10" y1="9" x2="8" y2="9"></line>',
        'ledger' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path><line x1="9" y1="7" x2="16" y2="7"></line><line x1="9" y1="11" x2="14" y2="11"></line>',
        'file' => '<line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line>',
        'dollar' => '<line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>',
        'star' => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>',
        'help' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>',
        'payroll' => '<rect x="2" y="3" width="20" height="18" rx="2"></rect><line x1="6" y1="8" x2="18" y2="8"></line><line x1="6" y1="12" x2="18" y2="12"></line><line x1="6" y1="16" x2="14" y2="16"></line><circle cx="18" cy="18" r="3"></circle><line x1="18" y1="16" x2="18" y2="20"></line><line x1="16" y1="18" x2="20" y2="18"></line>',
        'loan' => '<path d="M16 3h5v5"></path><path d="M8 21H3v-5"></path><path d="M21 3l-9 9"></path><path d="M3 21l9-9"></path><rect x="8" y="8" width="8" height="8" rx="1"></rect>',
        'consign' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line>',
        'gear' => '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>',
        default => '<circle cx="12" cy="12" r="10"></circle>',
    } . '</svg>';
}
}

$sbRenderLink = static function (string $href, string $label, string $icon, bool $active): void {
    ?>
    <li>
        <a href="<?php echo $href; ?>" class="sb-link<?php echo $active ? ' active' : ''; ?>" data-sb-label="<?php echo htmlspecialchars($label); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
            <span class="nav-icon"><?php echo tenantIcon($icon); ?></span>
            <span class="nav-text"><?php echo htmlspecialchars($label); ?></span>
        </a>
    </li>
    <?php
};
?>
<script>
(function () {
    try {
        var d = document.documentElement;
        var pref = localStorage.getItem('seri.sidebar');
        var collapsed = pref
            ? pref === 'collapsed'
            : window.matchMedia('(min-width: 901px) and (max-width: 1199px)').matches;
        d.classList.add('sb-preload');
        if (collapsed) d.classList.add('sb-collapsed');
    } catch (e) {}
})();
</script>
<aside class="sidebar sidebar--v3" id="sidebar" aria-label="Menú principal">
    <div class="sidebar-header">
        <a class="sb-brand" href="<?php echo $viewInstance->route(ltrim(\SoftNova\Core\TenantMiddleware::homePath(), '/')); ?>" data-sb-label="<?php echo htmlspecialchars($tenantName); ?>">
            <span class="sb-brand-mark" aria-hidden="true"><?php echo htmlspecialchars($sbMonogram); ?></span>
            <div class="sb-brand-text">
                <h1><?php echo htmlspecialchars($tenantName); ?></h1>
                <span class="sb-brand-sub">Seri ERP</span>
            </div>
        </a>
        <button type="button" class="sidebar-toggle" id="sidebarToggleBtn" onclick="toggleSidebar()" aria-controls="sidebar" aria-expanded="true" aria-label="Contraer menú">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>
        </button>
        <button type="button" class="sb-close" onclick="closeMobileSidebar()" aria-label="Cerrar menú">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
    </div>
    
    <nav class="sidebar-nav" id="sidebarNav">
        <?php foreach ($moduleGroups as $groupLabel => $groupKeys): ?>
            <?php
            $groupItems = array_intersect_key($visibleModules, array_flip($groupKeys));
            if ($groupItems === []) {
                continue;
            }
            ?>
            <div class="sb-group">
                <div class="sb-group-label"><span><?php echo htmlspecialchars($groupLabel); ?></span></div>
                <ul>
                    <?php foreach ($groupItems as $key => $mod): ?>
                        <?php
                        $isActive = str_contains($currentUri, '/app/' . $key)
                            || ($key === 'dashboard' && str_ends_with($currentUri, '/app/dashboard'));
                        $label = $userRole === 'pos' && $key === 'caja' ? 'POS' : $mod['label'];
                        $sbRenderLink($viewInstance->route($mod['route']), $label, $mod['icon'], $isActive);
                        ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>

        <?php if ($canSeeConfig): ?>
            <div class="sb-group">
                <div class="sb-group-label"><span>Sistema</span></div>
                <ul>
                    <?php $sbRenderLink($viewInstance->route('app/configuracion'), 'Configuración', 'gear', str_contains($currentUri, '/app/configuracion')); ?>
                </ul>
            </div>
        <?php endif; ?>
    </nav>
    
    <div class="sidebar-footer">
        <div class="sb-user" data-sb-label="<?php echo htmlspecialchars($userName . ' · ' . $sbRoleLabel); ?>" tabindex="-1">
            <span class="sb-user-avatar" aria-hidden="true"><?php echo htmlspecialchars($sbUserInitial); ?></span>
            <span class="sb-user-text">
                <span class="sb-user-name"><?php echo htmlspecialchars($userName); ?></span>
                <span class="sb-user-role"><?php echo htmlspecialchars($sbRoleLabel); ?></span>
            </span>
        </div>
    </div>
</aside>
