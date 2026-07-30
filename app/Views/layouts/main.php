<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title ?? 'Seri ERP'); ?></title>
    <?php echo \SoftNova\Core\og_meta_tags($title ?? null); ?>
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
