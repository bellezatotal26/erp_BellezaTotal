<?php
$layout = 'auth';
$title = 'Iniciar Sesión - Seri ERP';
?>

<div class="login-card">
    <h1>Seri ERP</h1>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-error">
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>
    
    <form method="POST" action="<?php echo $viewInstance->route('login'); ?>">
        <?php echo \SoftNova\Core\csrf_field(); ?>
        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" class="form-control" required>
        </div>
        
        <div class="form-group">
            <label for="password">Contraseña</label>
            <input type="password" id="password" name="password" class="form-control" required>
        </div>
        
        <button type="submit" class="btn btn-primary" style="width: 100%;">
            Iniciar Sesión
        </button>
    </form>
</div>
