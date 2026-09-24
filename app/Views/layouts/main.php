<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title ?? 'Seri ERP'); ?></title>
    <?php echo \SoftNova\Core\og_meta_tags($title ?? null); ?>
    <link rel="icon" href="<?php echo $viewInstance->asset('img/favicon.ico'); ?>" sizes="any">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo $viewInstance->asset('img/favicon-16.png'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo $viewInstance->asset('img/favicon-32.png'); ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo $viewInstance->asset('img/pwa-icon-192.png'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo $viewInstance->asset('img/apple-touch-icon.png'); ?>">
    <meta name="theme-color" content="#000000">
    <link rel="stylesheet" href="<?php echo $viewInstance->asset('css/style.css'); ?>">
</head>
<body>
    <div class="app-layout">
        <?php $viewInstance->partial('sidebar'); ?>
        
        <div class="main-content">
            <?php $viewInstance->partial('header'); ?>
            
            <div class="content">
                <?php echo $content; ?>
            </div>
        </div>
    </div>
    
    <script src="<?php echo $viewInstance->asset('js/app.js'); ?>"></script>
</body>
</html>
