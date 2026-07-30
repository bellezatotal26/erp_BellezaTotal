<?php
$layout = 'auth';
$title = 'Acceso al Sistema - Seri ERP';
?>

<div class="login-card">
    <h1>Seri ERP</h1>
    <p style="text-align:center; color: var(--color-text-secondary); margin-bottom: 20px;">
        Acceda a su sistema de gestión
    </p>
    
    <?php if (isset($_SESSION['tenant_error'])): ?>
        <div class="alert alert-error">
            <?php echo htmlspecialchars($_SESSION['tenant_error']); unset($_SESSION['tenant_error']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    
    <form method="POST" action="<?php echo $viewInstance->route(\SoftNova\Core\is_standalone() ? 'login' : 'app/login'); ?>">
        <?php echo \SoftNova\Core\csrf_field(); ?>
        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" class="form-control" 
                   placeholder="su@email.com" required autofocus>
        </div>
        
        <div class="form-group">
            <label for="password">Contraseña</label>
            <input type="password" id="password" name="password" class="form-control" 
                   placeholder="Su contraseña" required>
        </div>
        
        <button type="submit" class="btn btn-primary" style="width: 100%;">
            Ingresar al Sistema
        </button>
    </form>

    <?php if (!\SoftNova\Core\is_standalone()): ?>
    <div style="text-align: center; margin-top: 20px;">
        <a href="<?php echo $viewInstance->route('login'); ?>" style="color: var(--color-text-secondary); font-size: 13px;">
            ← Panel de Administración
        </a>
    </div>
    <?php endif; ?>
</div>
