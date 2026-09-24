<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(\SoftNova\Core\csrf_token()); ?>">
    <meta name="session-ping-url" content="<?php echo htmlspecialchars($viewInstance->route('app/notifications') . '?action=ping'); ?>">
    <?php
    $layoutPrimaryColor = '';
    $themeDb = null;
    try {
        $themeDb = \SoftNova\Core\TenantMiddleware::getDb();
        $tc = $themeDb->prepare("SELECT setting_value FROM settings WHERE setting_key = 'primary_color'");
        $tc->execute();
        $layoutPrimaryColor = (string)($tc->fetchColumn() ?: '');
    } catch (\Throwable $e) { /* silencioso */ }
    $layoutThemeColor = \SoftNova\Core\normalize_theme_hex($layoutPrimaryColor, '#000000');
    ?>
    <meta name="theme-color" content="<?php echo htmlspecialchars($layoutThemeColor); ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="BT Caja">
    <meta name="application-name" content="BT Caja">
    <?php
    $layoutCurrency = $currency ?? ['symbol' => '$', 'decimals' => 2, 'thousands' => '.', 'decimal' => ','];
    ?>
    <meta name="currency-symbol" content="<?php echo htmlspecialchars((string)($layoutCurrency['symbol'] ?? '$')); ?>">
    <meta name="currency-decimals" content="<?php echo (int)($layoutCurrency['decimals'] ?? 2); ?>">
    <meta name="currency-thousands" content="<?php echo htmlspecialchars((string)($layoutCurrency['thousands'] ?? '.')); ?>">
    <meta name="currency-decimal" content="<?php echo htmlspecialchars((string)($layoutCurrency['decimal'] ?? ',')); ?>">
    <?php
    $layoutBusinessClock = ['timezone' => 'America/Bogota', 'date' => date('Y-m-d'), 'time' => date('H:i:s'), 'now' => date('Y-m-d H:i:s')];
    try {
        $tzDb = $themeDb ?? \SoftNova\Core\TenantMiddleware::getDb();
        $layoutBusinessClock = \SoftNova\Core\BusinessTimezone::clientPayload($tzDb);
    } catch (\Throwable $e) {
        $layoutBusinessClock = \SoftNova\Core\BusinessTimezone::clientPayload(null);
    }
    ?>
    <meta name="seri-timezone" content="<?php echo htmlspecialchars((string)$layoutBusinessClock['timezone']); ?>">
    <meta name="seri-business-date" content="<?php echo htmlspecialchars((string)$layoutBusinessClock['date']); ?>">
    <script>
    window.SERI_TIMEZONE = <?php echo json_encode((string)$layoutBusinessClock['timezone'], JSON_UNESCAPED_UNICODE); ?>;
    window.SERI_BUSINESS_DATE = <?php echo json_encode((string)$layoutBusinessClock['date'], JSON_UNESCAPED_UNICODE); ?>;
    window.SERI_BUSINESS_TIME = <?php echo json_encode((string)$layoutBusinessClock['time'], JSON_UNESCAPED_UNICODE); ?>;
    window.SERI_BUSINESS_NOW = <?php echo json_encode((string)$layoutBusinessClock['now'], JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <title><?php echo htmlspecialchars($title ?? 'Seri ERP'); ?></title>
    <?php echo \SoftNova\Core\og_meta_tags($title ?? null); ?>
    <link rel="stylesheet" href="<?php echo $viewInstance->asset('css/style.css'); ?>">
    <meta name="barcode-lookup-url" content="<?php echo htmlspecialchars($viewInstance->route('app/dashboard') . '?action=lookup_barcode'); ?>">
    <meta name="custom-option-url" content="<?php echo htmlspecialchars($viewInstance->route('app/dashboard') . '?action=save_custom_option'); ?>">
    <?php
    $publicPrefix = \SoftNova\Core\public_web_prefix();
    $swUrl = \SoftNova\Core\base_url(ltrim($publicPrefix . '/sw.php', '/'));
    $swFile = defined('PUBLIC_PATH') ? (PUBLIC_PATH . '/sw.js') : '';
    if ($swFile !== '' && is_file($swFile)) {
        $swUrl .= (str_contains($swUrl, '?') ? '&' : '?') . 'v=' . filemtime($swFile);
    }
    $manifestUrl = \SoftNova\Core\base_url(ltrim($publicPrefix . '/manifest.php', '/'));
    $appBase = rtrim(parse_url(\SoftNova\Core\config('app.url', ''), PHP_URL_PATH) ?: '', '/');
    $swScope = ($appBase === '' ? '/' : $appBase . '/');
    ?>
    <link rel="manifest" href="<?php echo htmlspecialchars($manifestUrl); ?>">
    <meta name="sw-url" content="<?php echo htmlspecialchars($swUrl); ?>">
    <meta name="sw-scope" content="<?php echo htmlspecialchars($swScope); ?>">
    <link rel="icon" href="<?php echo $viewInstance->asset('img/favicon.ico'); ?>" sizes="any">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo $viewInstance->asset('img/favicon-16.png'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo $viewInstance->asset('img/favicon-32.png'); ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo $viewInstance->asset('img/pwa-icon-192.png'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo $viewInstance->asset('img/apple-touch-icon.png'); ?>">
    <?php if (!empty($needBarcode)): ?>
    <script src="<?php echo $viewInstance->asset('js/barcode.js'); ?>"></script>
    <?php endif; ?>
    <?php
    echo \SoftNova\Core\theme_color_style($layoutPrimaryColor);
    ?>
</head>
<body>
    <?php
    $layoutSandboxActive = !empty($_SESSION['sandbox_mode']);
    if ($layoutSandboxActive):
    ?>
    <div id="sandboxModeBanner" style="position:sticky;top:0;z-index:9999;background:#B45309;color:#fff;padding:10px 16px;text-align:center;font-weight:700;font-size:14px;letter-spacing:0.02em;box-shadow:0 2px 8px rgba(0,0,0,0.2);">
        MODO CAJA DE ARENA — Los cambios de esta sesión son de prueba y no afectan producción
        <a href="<?php echo htmlspecialchars($viewInstance->route('app/configuracion')); ?>" style="color:#fff;text-decoration:underline;margin-left:12px;font-weight:600;">Salir en Configuración</a>
    </div>
    <?php endif; ?>
    <div class="app-layout">
        <?php $viewInstance->partial('tenant_sidebar', [
            'tenantName' => $tenantName ?? 'Sistema',
            'userName' => $userName ?? 'Usuario',
        ]); ?>
        
        <div class="main-content" id="mainContent">
            <header class="header">
                <div class="header-left">
                    <button type="button" class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleSidebar()" aria-label="Abrir menú de módulos" aria-controls="sidebar" aria-expanded="false">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <line x1="3" y1="6" x2="21" y2="6"></line>
                            <line x1="3" y1="12" x2="21" y2="12"></line>
                            <line x1="3" y1="18" x2="21" y2="18"></line>
                        </svg>
                    </button>
                    <h2><?php echo htmlspecialchars($pageTitle ?? 'Dashboard'); ?></h2>
                </div>
                <div class="header-search" id="globalSearch">
                    <div class="search-input-wrapper">
                        <span class="search-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                        </span>
                        <input type="text" class="search-input" id="globalSearchInput" placeholder="Buscar en Seri ERP... (clientes, productos, ventas)" autocomplete="off" data-search-url="<?php echo $viewInstance->route('app/dashboard'); ?>">
                        <button type="button" id="searchClear" class="search-clear" style="display:none;" onclick="clearSearch()">&times;</button>
                        <div class="search-results" id="searchResults" style="display:none;"></div>
                    </div>
                </div>
                <div class="header-right">
                    <a href="<?php echo $viewInstance->route('app/soporte'); ?>" class="header-icon-btn ticket-notification" title="Soporte Técnico">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                        </svg>
                        <span class="header-icon-label">Soporte</span>
                    </a>
                    <a href="<?php echo $viewInstance->route('app/ia'); ?>" class="header-icon-btn" title="Asistente IA">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                        </svg>
                        <span class="header-icon-label">Asistente IA</span>
                    </a>
                    <div class="datetime-info">
                        <div class="datetime-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                        </div>
                        <div class="datetime-text">
                            <span id="currentDate"></span>
                            <span id="currentTime"></span>
                        </div>
                    </div>
                    <div class="user-dropdown" id="userDropdown">
                        <button class="user-dropdown-btn" onclick="toggleUserMenu()">
                            <span class="user-avatar">
                                <?php
                                $avatarUrl = null;
                                try {
                                    $avatarDb = \SoftNova\Core\TenantMiddleware::getDb();
                                    $av = $avatarDb->prepare("SELECT setting_value FROM settings WHERE setting_key = 'user_avatar'");
                                    $av->execute();
                                    $avatarUrl = $av->fetchColumn() ?: null;
                                } catch (\Exception $e) { /* silencioso */ }
                                if ($avatarUrl): ?>
                                    <img src="<?php echo htmlspecialchars(\SoftNova\Core\media_url((string)$avatarUrl)); ?>" alt="Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($userName ?? 'U', 0, 1)); ?>
                                <?php endif; ?>
                            </span>
                            <span class="user-name"><?php echo htmlspecialchars($userName ?? 'Usuario'); ?></span>
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </button>
                        <div class="user-dropdown-menu" id="userMenu" style="display:none;">
                            <?php if (\SoftNova\Core\TenantMiddleware::canAccess('configuracion')): ?>
                            <a href="<?php echo $viewInstance->route('app/configuracion'); ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                                Configuración
                            </a>
                            <?php endif; ?>
                            <a href="<?php echo $viewInstance->route('app/logout'); ?>" style="color:var(--color-error);">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                                Cerrar Sesión
                            </a>
                        </div>
                    </div>
                    <!-- Campana de notificaciones (al final del header) -->
                    <div class="notification-dropdown" id="notificationDropdown">
                        <button type="button" class="header-icon-btn notification-bell notification-bell-solid" id="notificationBellBtn" title="Notificaciones" aria-haspopup="true" aria-expanded="false">
                            <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
                                <path fill="currentColor" d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22zm6-6V11a6 6 0 0 0-5-5.91V4a1 1 0 0 0-2 0v1.09A6 6 0 0 0 6 11v5l-2 2v1h16v-1l-2-2z"/>
                            </svg>
                            <span class="notification-badge" id="notificationBadge" style="display:none;">0</span>
                        </button>
                        <div class="notification-menu neumorphic" id="notificationMenu" style="display:none;" role="menu">
                            <div class="notification-menu-header">
                                <strong>Notificaciones</strong>
                                <div class="notification-header-actions">
                                    <button type="button" class="notification-refresh" id="notificationClear" title="Limpiar todas">Limpiar</button>
                                    <button type="button" class="notification-refresh" id="notificationRefresh" title="Actualizar">↻</button>
                                </div>
                            </div>
                            <div class="notification-menu-body" id="notificationList">
                                <div class="notification-empty">Cargando...</div>
                            </div>
                            <div class="notification-menu-footer">
                                <a href="<?php echo $viewInstance->route('app/soporte'); ?>">Ver soporte</a>
                                <a href="<?php echo $viewInstance->route('app/ventas'); ?>">Ver ventas</a>
                            </div>
                        </div>
                    </div>
                </div>
            </header>
            
            <!-- Modal aviso Super Admin -->
            <div id="announcementModal" class="announcement-modal" style="display:none;" aria-hidden="true">
                <div class="announcement-modal-backdrop"></div>
                <div class="announcement-modal-card neumorphic" role="dialog" aria-modal="true" aria-labelledby="announcementModalTitle">
                    <div class="announcement-modal-header">
                        <span class="announcement-modal-badge" id="announcementModalBadge">Aviso</span>
                        <button type="button" class="modal-close" id="announcementModalClose" title="Cerrar">&times;</button>
                    </div>
                    <h3 id="announcementModalTitle"></h3>
                    <p id="announcementModalBody"></p>
                    <div class="announcement-modal-actions">
                        <button type="button" class="btn btn-secondary" id="announcementDismissBtn">Eliminar aviso</button>
                        <button type="button" class="btn btn-primary" id="announcementOkBtn">Entendido</button>
                    </div>
                </div>
            </div>
            
            <div class="content">
                <?php if (!empty($pageTitle) || !empty($pageTopStatsHtml)): ?>
                <div class="page-topbar<?php echo !empty($pageTopStatsHtml) ? ' page-topbar--with-stats' : ''; ?>">
                    <?php if (!empty($pageTitle)): ?>
                    <div class="breadcrumbs">
                        <a href="<?php echo $viewInstance->route('app/dashboard'); ?>">Dashboard</a>
                        <span class="separator">›</span>
                        <span class="current"><?php echo htmlspecialchars($pageTitle); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($pageTopStatsHtml)): ?>
                    <div class="page-top-stats" aria-label="Resumen de caja">
                        <?php echo $pageTopStatsHtml; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php echo $content; ?>
            </div>
            
            <footer class="app-footer">
                <p>Software Belleza Total | 2026</p>
            </footer>
        </div>
    </div>
    <button type="button" class="sidebar-overlay" id="sidebarOverlay" aria-label="Cerrar menú" tabindex="-1"></button>
    
    <script>
        function isMobileNav() {
            return window.matchMedia('(max-width: 900px)').matches;
        }

        function setMobileMenuExpanded(open) {
            var btn = document.getElementById('mobileMenuBtn');
            if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        function openMobileSidebar() {
            var sidebar = document.getElementById('sidebar');
            var overlay = document.getElementById('sidebarOverlay');
            if (!sidebar) return;
            sidebar.classList.add('mobile-open');
            if (overlay) overlay.classList.add('is-visible');
            document.body.classList.add('sidebar-open');
            setMobileMenuExpanded(true);
        }

        function closeMobileSidebar() {
            var sidebar = document.getElementById('sidebar');
            var overlay = document.getElementById('sidebarOverlay');
            if (sidebar) sidebar.classList.remove('mobile-open');
            if (overlay) overlay.classList.remove('is-visible');
            document.body.classList.remove('sidebar-open');
            setMobileMenuExpanded(false);
        }

        function toggleSidebar() {
            var sidebar = document.getElementById('sidebar');
            var mainContent = document.getElementById('mainContent');
            var toggleIcon = document.getElementById('sidebarToggleIcon');
            if (!sidebar) return;

            if (isMobileNav()) {
                if (sidebar.classList.contains('mobile-open')) closeMobileSidebar();
                else openMobileSidebar();
                return;
            }

            sidebar.classList.toggle('collapsed');
            if (mainContent) mainContent.classList.toggle('expanded');
            if (toggleIcon) {
                toggleIcon.textContent = sidebar.classList.contains('collapsed') ? '»' : '«';
            }
        }

        (function initMobileSidebar() {
            var overlay = document.getElementById('sidebarOverlay');
            var sidebar = document.getElementById('sidebar');
            if (overlay) {
                overlay.addEventListener('click', closeMobileSidebar);
            }
            if (sidebar) {
                sidebar.querySelectorAll('a').forEach(function (link) {
                    link.addEventListener('click', function () {
                        if (isMobileNav()) closeMobileSidebar();
                    });
                });
            }
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeMobileSidebar();
            });
            window.addEventListener('resize', function () {
                if (!isMobileNav()) closeMobileSidebar();
            });
        })();
        
        function updateDateTime() {
            var tz = window.SERI_TIMEZONE || 'America/Bogota';
            var now = new Date();
            var optionsDate = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', timeZone: tz };
            var optionsTime = { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: tz, hour12: false };
            var dateEl = document.getElementById('currentDate');
            var timeEl = document.getElementById('currentTime');
            try {
                if (dateEl) dateEl.textContent = now.toLocaleDateString('es-CO', optionsDate);
                if (timeEl) timeEl.textContent = now.toLocaleTimeString('es-CO', optionsTime);
                if (window.SeriDate) {
                    window.SERI_BUSINESS_DATE = SeriDate.localYmd(now);
                    window.SERI_BUSINESS_TIME = SeriDate.localHis(now);
                    window.SERI_BUSINESS_NOW = window.SERI_BUSINESS_DATE + ' ' + window.SERI_BUSINESS_TIME;
                }
            } catch (e) {
                if (dateEl) dateEl.textContent = now.toLocaleDateString('es-ES');
                if (timeEl) timeEl.textContent = now.toLocaleTimeString('es-ES');
            }
        }
        updateDateTime();
        setInterval(updateDateTime, 1000);
        
        function toggleUserMenu() {
            var menu = document.getElementById('userMenu');
            if (menu) menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
        }
        
        document.addEventListener('click', function(e) {
            var dd = document.getElementById('userDropdown');
            if (dd && !dd.contains(e.target)) {
                var menu = document.getElementById('userMenu');
                if (menu) menu.style.display = 'none';
            }
            
            var notifDd = document.getElementById('notificationDropdown');
            if (notifDd && !notifDd.contains(e.target)) {
                var nMenu = document.getElementById('notificationMenu');
                if (nMenu) nMenu.style.display = 'none';
                var bell = document.getElementById('notificationBellBtn');
                if (bell) bell.setAttribute('aria-expanded', 'false');
            }
        });
        
        (function initNotifications() {
            var url = <?php echo json_encode($viewInstance->route('app/notifications')); ?>;
            var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
            var btn = document.getElementById('notificationBellBtn');
            var menu = document.getElementById('notificationMenu');
            var list = document.getElementById('notificationList');
            var badge = document.getElementById('notificationBadge');
            var refreshBtn = document.getElementById('notificationRefresh');
            var clearBtn = document.getElementById('notificationClear');
            var modal = document.getElementById('announcementModal');
            var modalTitle = document.getElementById('announcementModalTitle');
            var modalBody = document.getElementById('announcementModalBody');
            var modalBadge = document.getElementById('announcementModalBadge');
            var currentPopupId = null;
            var popupOpen = false;
            if (!btn || !menu || !list) return;
            
            function esc(s) {
                if (typeof window.esc === 'function') return window.esc(s);
                return String(s || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }
            
            var loaded = false;
            var lastBadge = null;
            
            function typeIcon(type) {
                if (type === 'ticket') return 'S';
                if (type === 'sale') return 'V';
                if (type === 'inventory') return 'I';
                if (type === 'news') return 'N';
                return '!';
            }
            
            function typeClass(type) {
                return 'notif-type-' + (type || 'other');
            }
            
            function applyBadge(count) {
                count = parseInt(count || 0, 10);
                if (count > 0) {
                    badge.style.display = 'flex';
                    badge.textContent = count > 99 ? '99+' : String(count);
                    if (lastBadge !== null && count > lastBadge) {
                        if (typeof ringNotificationBell === 'function') {
                            ringNotificationBell();
                        } else {
                            btn.classList.remove('bell-ring');
                            void btn.offsetWidth;
                            btn.classList.add('bell-ring');
                        }
                    }
                } else {
                    badge.style.display = 'none';
                }
                lastBadge = count;
            }
            
            function postAction(action, extra) {
                var fd = new FormData();
                fd.append('csrf_token', csrf);
                fd.append('action', action);
                if (extra) {
                    Object.keys(extra).forEach(function(k) { fd.append(k, extra[k]); });
                }
                return fetch(url, {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                }).then(function(r) { return r.json(); });
            }
            
            function dismissItem(itemKey) {
                return postAction('dismiss', { item_key: itemKey });
            }
            
            function hideAnnouncementModal() {
                if (!modal) return;
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden', 'true');
                popupOpen = false;
                currentPopupId = null;
            }
            
            function showAnnouncementModal(popup) {
                if (!modal || !popup || popupOpen) return;
                currentPopupId = popup.id || null;
                if (modalTitle) modalTitle.textContent = popup.title || 'Aviso';
                if (modalBody) modalBody.textContent = popup.message || '';
                if (modalBadge) {
                    modalBadge.textContent = popup.priority === 'important' ? 'Importante' : 'Aviso del sistema';
                    modalBadge.className = 'announcement-modal-badge' + (popup.priority === 'important' ? ' is-important' : '');
                }
                modal.style.display = 'flex';
                modal.setAttribute('aria-hidden', 'false');
                popupOpen = true;
                if (typeof ringNotificationBell === 'function') ringNotificationBell();
            }
            
            function handlePopups(popups) {
                if (!popups || !popups.length || popupOpen) return;
                showAnnouncementModal(popups[0]);
            }
            
            function renderItems(items) {
                if (!items || !items.length) {
                    list.innerHTML = '<div class="notification-empty">No hay notificaciones recientes</div>';
                    return;
                }
                var html = '';
                items.forEach(function(item) {
                    html += '<div class="notification-item ' + (item.urgent ? 'is-urgent' : '') + '">'
                        + '<a class="notification-item-link" href="' + (item.url || '#') + '">'
                        + '<span class="notification-item-icon ' + typeClass(item.type) + '">' + typeIcon(item.type) + '</span>'
                        + '<span class="notification-item-content">'
                        + '<span class="notification-item-title">' + esc(item.title || '') + '</span>'
                        + '<span class="notification-item-message">' + esc(item.message || '') + '</span>'
                        + '<span class="notification-item-meta">' + esc(item.meta || '') + (item.time_label ? ' · ' + esc(item.time_label) : '') + '</span>'
                        + '</span></a>'
                        + '<button type="button" class="notification-dismiss" data-key="' + esc(item.id || '') + '" title="Eliminar">×</button>'
                        + '</div>';
                });
                list.innerHTML = html;
                list.querySelectorAll('.notification-dismiss').forEach(function(b) {
                    b.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        var key = b.getAttribute('data-key');
                        if (!key) return;
                        dismissItem(key).then(function(d) {
                            if (d && d.success) {
                                poll(true);
                            } else if (typeof showAlert === 'function') {
                                showAlert((d && d.message) || 'No se pudo eliminar', 'error');
                            }
                        });
                    });
                });
            }
            
            function poll(forceList) {
                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (window.SeriCsrf && typeof SeriCsrf.refreshFromPayload === 'function') {
                            SeriCsrf.refreshFromPayload(data);
                        } else if (data && data.csrf_token) {
                            var meta = document.querySelector('meta[name="csrf-token"]');
                            if (meta) meta.setAttribute('content', data.csrf_token);
                            csrf = data.csrf_token;
                        }
                        if (data && data.csrf_token) csrf = data.csrf_token;
                        applyBadge(data.badge || 0);
                        handlePopups(data.popups || []);
                        if (forceList || menu.style.display !== 'none') {
                            loaded = true;
                            renderItems(data.items || []);
                        }
                    })
                    .catch(function() {
                        if (forceList) list.innerHTML = '<div class="notification-empty">No se pudieron cargar</div>';
                    });
            }
            
            function loadNotifications() {
                list.innerHTML = '<div class="notification-empty">Cargando...</div>';
                poll(true);
            }
            
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var open = menu.style.display !== 'none';
                if (open) {
                    menu.style.display = 'none';
                    btn.setAttribute('aria-expanded', 'false');
                } else {
                    menu.style.display = 'block';
                    btn.setAttribute('aria-expanded', 'true');
                    if (!loaded) loadNotifications();
                }
            });
            
            if (refreshBtn) {
                refreshBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    loadNotifications();
                });
            }
            
            if (clearBtn) {
                clearBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    postAction('clear').then(function(d) {
                        if (d && d.success) {
                            hideAnnouncementModal();
                            poll(true);
                            if (typeof showAlert === 'function') showAlert('Notificaciones limpiadas', 'success');
                        } else if (typeof showAlert === 'function') {
                            showAlert((d && d.message) || 'No se pudo limpiar', 'error');
                        }
                    });
                });
            }
            
            function dismissCurrentPopup() {
                if (!currentPopupId) {
                    hideAnnouncementModal();
                    return;
                }
                dismissItem(currentPopupId).then(function() {
                    hideAnnouncementModal();
                    poll(true);
                });
            }
            
            var okBtn = document.getElementById('announcementOkBtn');
            var dismissBtn = document.getElementById('announcementDismissBtn');
            var closeBtn = document.getElementById('announcementModalClose');
            if (okBtn) okBtn.addEventListener('click', dismissCurrentPopup);
            if (dismissBtn) dismissBtn.addEventListener('click', dismissCurrentPopup);
            if (closeBtn) closeBtn.addEventListener('click', dismissCurrentPopup);
            if (modal) {
                var backdrop = modal.querySelector('.announcement-modal-backdrop');
                if (backdrop) backdrop.addEventListener('click', dismissCurrentPopup);
            }
            
            poll(false);
            setInterval(function() { poll(false); }, 45000);
        })();
        
        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') document.body.classList.add('dark-mode');
        });
    </script>
    <script src="<?php echo $viewInstance->asset('js/money-input.js'); ?>"></script>
    <script src="<?php echo $viewInstance->asset('js/app.js'); ?>"></script>
    <script src="<?php echo $viewInstance->asset('js/pos-offline.js'); ?>"></script>
    <script>
    if (window.SeriCsrf && typeof SeriCsrf.startKeepAlive === 'function') {
        SeriCsrf.startKeepAlive();
    }
    if (window.SeriPosOffline && typeof SeriPosOffline.registerServiceWorker === 'function') {
        SeriPosOffline.registerServiceWorker();
    }
    </script>
    <?php
    $pageScripts = $pageScripts ?? [];
    if (is_string($pageScripts)) {
        $pageScripts = [$pageScripts];
    }
    foreach ($pageScripts as $script):
        $script = ltrim((string)$script, '/');
        if ($script === '') {
            continue;
        }
        if (!str_starts_with($script, 'js/')) {
            $script = 'js/' . $script;
        }
    ?>
    <script src="<?php echo $viewInstance->asset($script); ?>"></script>
    <?php endforeach; ?>
</body>
</html>
