<?php
$isSuperAdmin = $isSuperAdmin ?? false;
?>

<aside class="sidebar <?php echo $isSuperAdmin ? 'superadmin-sidebar' : ''; ?>" id="sidebar">
    <div class="sidebar-header">
        <h1>Seri ERP</h1>
        <button class="sidebar-toggle" onclick="toggleSidebar()">
            <span id="sidebarToggleIcon">«</span>
        </button>
    </div>
    
    <nav class="sidebar-nav">
        <ul>
            <?php if ($isSuperAdmin): ?>
                <li>
                    <a href="<?php echo $viewInstance->route('superadmin'); ?>" class="<?php echo ($_SERVER['REQUEST_URI'] ?? '') === '/SoftNova/public/superadmin' ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="7" height="7"></rect>
                                <rect x="14" y="3" width="7" height="7"></rect>
                                <rect x="14" y="14" width="7" height="7"></rect>
                                <rect x="3" y="14" width="7" height="7"></rect>
                            </svg>
                        </span>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo $viewInstance->route('superadmin/tenants'); ?>" class="<?php echo ($_SERVER['REQUEST_URI'] ?? '') === '/SoftNova/public/superadmin/tenants' ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                        </span>
                        <span class="nav-text">Clientes</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo $viewInstance->route('superadmin/plans'); ?>" class="<?php echo ($_SERVER['REQUEST_URI'] ?? '') === '/SoftNova/public/superadmin/plans' ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="1" x2="12" y2="23"></line>
                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                            </svg>
                        </span>
                        <span class="nav-text">Planes</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo $viewInstance->route('superadmin/licencias'); ?>" class="<?php echo ($_SERVER['REQUEST_URI'] ?? '') === '/SoftNova/public/superadmin/licencias' ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                            </svg>
                        </span>
                        <span class="nav-text">Control de Licencias</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo $viewInstance->route('superadmin/tickets'); ?>" class="<?php echo str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/SoftNova/public/superadmin/tickets') ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                            </svg>
                        </span>
                        <span class="nav-text">Tickets de Soporte</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo $viewInstance->route('superadmin/announcements'); ?>" class="<?php echo str_contains($_SERVER['REQUEST_URI'] ?? '', '/superadmin/announcements') ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 17H2a3 3 0 0 0 3-3V9a7 7 0 0 1 14 0v5a3 3 0 0 0 3 3zm-8.27 4a2 2 0 0 1-3.46 0"></path>
                            </svg>
                        </span>
                        <span class="nav-text">Noticias</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo $viewInstance->route('superadmin/audits'); ?>" class="<?php echo ($_SERVER['REQUEST_URI'] ?? '') === '/SoftNova/public/superadmin/audits' ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                <polyline points="10 9 9 9 8 9"></polyline>
                            </svg>
                        </span>
                        <span class="nav-text">Auditorías</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo $viewInstance->route('superadmin/settings'); ?>" class="<?php echo ($_SERVER['REQUEST_URI'] ?? '') === '/SoftNova/public/superadmin/settings' ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="3"></circle>
                                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                            </svg>
                        </span>
                        <span class="nav-text">Configuración</span>
                    </a>
                </li>
            <?php else: ?>
                <li>
                    <a href="<?php echo $viewInstance->route('dashboard'); ?>" class="<?php echo $_SERVER['REQUEST_URI'] === '/SoftNova/public/dashboard' ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                <polyline points="9 22 9 12 15 12 15 22"></polyline>
                            </svg>
                        </span>
                        <span class="nav-text">Inicio</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo $viewInstance->route('caja'); ?>" class="<?php echo $_SERVER['REQUEST_URI'] === '/SoftNova/public/caja' ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                                <line x1="2" y1="10" x2="22" y2="10"></line>
                            </svg>
                        </span>
                        <span class="nav-text">Caja</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo $viewInstance->route('ventas'); ?>" class="<?php echo $_SERVER['REQUEST_URI'] === '/SoftNova/public/ventas' ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="9" cy="21" r="1"></circle>
                                <circle cx="20" cy="21" r="1"></circle>
                                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                            </svg>
                        </span>
                        <span class="nav-text">Ventas</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo $viewInstance->route('inventario'); ?>" class="<?php echo $_SERVER['REQUEST_URI'] === '/SoftNova/public/inventario' ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                                <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                                <line x1="12" y1="22.08" x2="12" y2="12"></line>
                            </svg>
                        </span>
                        <span class="nav-text">Inventario</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo $viewInstance->route('clientes'); ?>" class="<?php echo $_SERVER['REQUEST_URI'] === '/SoftNova/public/clientes' ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                            </svg>
                        </span>
                        <span class="nav-text">Clientes</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo $viewInstance->route('proveedores'); ?>" class="<?php echo $_SERVER['REQUEST_URI'] === '/SoftNova/public/proveedores' ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </span>
                        <span class="nav-text">Proveedores</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo $viewInstance->route('cotizaciones'); ?>" class="<?php echo $_SERVER['REQUEST_URI'] === '/SoftNova/public/cotizaciones' ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                <polyline points="10 9 9 9 8 9"></polyline>
                            </svg>
                        </span>
                        <span class="nav-text">Cotizaciones</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo $viewInstance->route('gastos'); ?>" class="<?php echo $_SERVER['REQUEST_URI'] === '/SoftNova/public/gastos' ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="23 18 13.5 8.5 8.5 13.5 1 6"></polyline>
                                <polyline points="17 6 23 6 23 12"></polyline>
                            </svg>
                        </span>
                        <span class="nav-text">Gastos / Salidas</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo $viewInstance->route('contabilidad'); ?>" class="<?php echo $_SERVER['REQUEST_URI'] === '/SoftNova/public/contabilidad' ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="1" x2="12" y2="23"></line>
                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                            </svg>
                        </span>
                        <span class="nav-text">Contabilidad</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo $viewInstance->route('nomina'); ?>" class="<?php echo $_SERVER['REQUEST_URI'] === '/SoftNova/public/nomina' ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                                <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                            </svg>
                        </span>
                        <span class="nav-text">Nómina</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo $viewInstance->route('reportes'); ?>" class="<?php echo $_SERVER['REQUEST_URI'] === '/SoftNova/public/reportes' ? 'active' : ''; ?>">
                        <span class="nav-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="20" x2="18" y2="10"></line>
                                <line x1="12" y1="20" x2="12" y2="4"></line>
                                <line x1="6" y1="20" x2="6" y2="14"></line>
                            </svg>
                        </span>
                        <span class="nav-text">Analíticas y Reportes</span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </nav>
    
    <div class="sidebar-footer">
        <p style="font-size: 12px; color: rgba(250, 250, 250, 0.7);">
            Versión 1.0.0
        </p>
    </div>
</aside>
