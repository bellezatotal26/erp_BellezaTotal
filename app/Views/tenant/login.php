<?php
$layout = 'auth';
$title = 'Acceso - Software Belleza Total';
$bgUrl = $viewInstance->asset('img/login-beauty-bg.jpg');
?>

<div class="login-page-bg" style="--login-bg-image: url('<?php echo htmlspecialchars($bgUrl, ENT_QUOTES); ?>');" aria-hidden="true"></div>
<div class="login-page-scrim" aria-hidden="true"></div>

<div class="login-card login-card--belleza">
    <div class="login-brand">
        <p class="login-brand-eyebrow">Software ERP</p>
        <h1>Belleza Total</h1>
        <p class="login-brand-sub">Acceda a su sistema de gestión</p>
    </div>

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

    <form method="POST" action="<?php echo $viewInstance->route(\SoftNova\Core\is_standalone() ? 'login' : 'app/login'); ?>" class="login-form">
        <?php echo \SoftNova\Core\csrf_field(); ?>
        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" class="form-control"
                   placeholder="su@email.com" required autofocus autocomplete="username">
        </div>

        <div class="form-group">
            <label for="password">Contraseña</label>
            <div class="login-password-wrap">
                <input type="password" id="password" name="password" class="form-control"
                       placeholder="Su contraseña" required autocomplete="current-password">
                <button type="button" class="login-password-toggle" id="togglePassword" aria-label="Mostrar contraseña" title="Mostrar u ocultar contraseña">
                    <span class="login-eye" aria-hidden="true"></span>
                </button>
            </div>
        </div>

        <button type="submit" class="btn btn-primary login-submit">
            Ingresar al Sistema
        </button>
    </form>

    <?php if (!\SoftNova\Core\is_standalone()): ?>
    <div class="login-admin-link">
        <a href="<?php echo $viewInstance->route('login'); ?>">← Panel de Administración</a>
    </div>
    <?php endif; ?>
</div>
