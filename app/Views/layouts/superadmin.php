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
    
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const toggleIcon = document.getElementById('sidebarToggleIcon');
            
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            
            if (sidebar.classList.contains('collapsed')) {
                toggleIcon.textContent = '»';
            } else {
                toggleIcon.textContent = '«';
            }
        }
        
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
