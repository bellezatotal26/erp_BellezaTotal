<?php
$layout = 'tenant';
$title = ($moduleName ?? 'Módulo') . ' - ' . ($tenantName ?? 'Sistema');
$pageTitle = $moduleName ?? 'Módulo';
$tenantName = $tenantName ?? 'Mi Empresa';
$userName = $userName ?? 'Usuario';
?>

<div class="card neumorphic" style="text-align: center; padding: 60px 40px;">
    <div style="font-size: 64px; margin-bottom: 15px;">🚧</div>
    <h2 style="color: var(--color-primary); margin-bottom: 10px;">
        Módulo de <?php echo htmlspecialchars($moduleName ?? ''); ?>
    </h2>
    <p style="color: var(--color-text-secondary); font-size: 16px; max-width: 500px; margin: 0 auto 20px;">
        Este módulo está en desarrollo. Pronto podrá gestionar 
        <?php echo htmlspecialchars(strtolower($moduleName ?? 'este módulo')); ?> desde aquí.
    </p>
    <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
        <a href="<?php echo $viewInstance->route('app/dashboard'); ?>" class="btn btn-primary neumorphic-btn">
            ← Volver al Dashboard
        </a>
        <a href="<?php echo $viewInstance->route('app/caja'); ?>" class="btn btn-secondary">
            Ir a Caja
        </a>
    </div>
</div>
