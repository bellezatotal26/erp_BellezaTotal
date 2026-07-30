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
    <div class="login-page">
        <?php echo $content; ?>
    </div>
    
    <script src="<?php echo $viewInstance->asset('js/app.js'); ?>"></script>
    <script src="<?php echo $viewInstance->asset('js/auth.js'); ?>"></script>
</body>
</html>
