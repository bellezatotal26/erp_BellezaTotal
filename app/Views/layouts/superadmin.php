<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $viewInstance->escape($title ?? 'Super Administrador - Seri ERP'); ?></title>
    <?php echo \SoftNova\Core\og_meta_tags($title ?? null); ?>
    <link rel="stylesheet" href="<?php echo $viewInstance->asset('css/style.css'); ?>">
</head>
<body>
    <div class="app-layout">
        <?php $viewInstance->partial('sidebar', ['isSuperAdmin' => true]); ?>
        
        <div class="main-content" id="mainContent">
            <?php $viewInstance->partial('header', ['isSuperAdmin' => true]); ?>
            
            <div class="content">
                <?php echo $content; ?>
            </div>
            
            <footer class="app-footer">
                <p>Seri ERP &copy; 2026 | Osgo Support</p>
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
            if (overlay) overlay.addEventListener('click', closeMobileSidebar);
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
        
        function changeTheme(theme) {
            document.body.classList.remove('dark-mode');
            
            if (theme === 'dark') {
                document.body.classList.add('dark-mode');
                localStorage.setItem('theme', 'dark');
            } else {
                localStorage.setItem('theme', 'light');
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-mode');
            }
        });
    </script>
    <script src="<?php echo $viewInstance->asset('js/app.js'); ?>"></script>
</body>
</html>
