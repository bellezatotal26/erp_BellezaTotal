<!DOCTYPE html>
<html lang="es" class="auth-html">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title ?? 'Software Belleza Total'); ?></title>
    <?php echo \SoftNova\Core\og_meta_tags($title ?? null); ?>
    <link rel="icon" href="<?php echo $viewInstance->asset('img/favicon.ico'); ?>" sizes="any">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo $viewInstance->asset('img/favicon-16.png'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo $viewInstance->asset('img/favicon-32.png'); ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo $viewInstance->asset('img/pwa-icon-192.png'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo $viewInstance->asset('img/apple-touch-icon.png'); ?>">
    <meta name="theme-color" content="#000000">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Source+Sans+3:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo $viewInstance->asset('css/style.css'); ?>">
</head>
<body class="auth-body">
    <div class="login-page">
        <?php echo $content; ?>
    </div>

    <script src="<?php echo $viewInstance->asset('js/app.js'); ?>"></script>
    <script src="<?php echo $viewInstance->asset('js/auth.js'); ?>"></script>
</body>
</html>
