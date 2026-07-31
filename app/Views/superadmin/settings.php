<?php
$layout = 'superadmin';
$title = 'Super Administrador - Configuración';
$pageTitle = 'Configuración';
$userName = $_SESSION['super_admin_name'] ?? 'Super Admin';
$ogImage = $ogImage ?? '';
$ogTitle = $ogTitle ?? 'Seri ERP';
$ogDescription = $ogDescription ?? '';
$ogImageUrl = '';
if ($ogImage !== '') {
    $ogImageUrl = preg_match('#^https?://#i', $ogImage)
        ? $ogImage
        : $viewInstance->route(ltrim($ogImage, '/'));
}
?>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success">
        <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-error">
        <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<div class="settings-container">
    <div class="settings-grid">
        <div class="settings-card neumorphic" style="grid-column:1/-1;">
            <div class="settings-card-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                    <path d="M21 15l-5-5L5 21"></path>
                </svg>
            </div>
            <h3>Imagen al compartir el enlace (Open Graph)</h3>
            <p style="color:var(--color-text-secondary);font-size:13px;margin:0 0 16px;">
                Esta imagen y textos aparecen como miniatura cuando alguien envía
                <strong>https://seri.heraconsultores.com</strong> por WhatsApp, Telegram, Facebook, LinkedIn, etc.
                Recomendado: <strong>1200 × 630 px</strong>, JPG o PNG, máximo 2&nbsp;MB.
            </p>
            <form method="POST"
                  action="<?php echo $viewInstance->route('superadmin/settings'); ?>?action=save_share_preview"
                  enctype="multipart/form-data"
                  class="settings-form"
                  data-ajax="true">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <div style="display:grid;grid-template-columns:minmax(200px,280px) 1fr;gap:20px;align-items:start;">
                    <div>
                        <?php if ($ogImageUrl !== ''): ?>
                            <img src="<?php echo htmlspecialchars($ogImageUrl); ?>?v=<?php echo time(); ?>"
                                 alt="Vista previa OG"
                                 style="width:100%;max-width:280px;border-radius:10px;border:1px solid var(--color-border);aspect-ratio:1200/630;object-fit:cover;background:#eee;">
                            <label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:13px;">
                                <input type="checkbox" name="remove_og_image" value="1"> Quitar imagen actual
                            </label>
                        <?php else: ?>
                            <div style="width:100%;max-width:280px;aspect-ratio:1200/630;border-radius:10px;border:2px dashed var(--color-border);display:flex;align-items:center;justify-content:center;color:var(--color-text-secondary);font-size:13px;text-align:center;padding:12px;">
                                Sin imagen destacada
                            </div>
                        <?php endif; ?>
                        <div class="form-group" style="margin-top:12px;">
                            <label>Subir imagen</label>
                            <input type="file" name="og_image" accept="image/jpeg,image/png,image/webp" class="neumorphic-input">
                        </div>
                    </div>
                    <div>
                        <div class="form-group">
                            <label>Título al compartir</label>
                            <input type="text" name="og_title" maxlength="120" class="neumorphic-input"
                                   value="<?php echo htmlspecialchars($ogTitle); ?>"
                                   placeholder="Seri ERP — Gestión empresarial">
                        </div>
                        <div class="form-group">
                            <label>Descripción corta</label>
                            <textarea name="og_description" rows="3" maxlength="300" class="neumorphic-input"
                                      placeholder="Ventas, inventario, caja y contabilidad en un solo lugar."><?php echo htmlspecialchars($ogDescription); ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary neumorphic-btn">Guardar vista previa</button>
                        <p style="font-size:12px;color:var(--color-text-secondary);margin-top:10px;">
                            Tras guardar, WhatsApp/Facebook pueden tardar en refrescar la miniatura.
                            Puede forzar la actualización en
                            <a href="https://developers.facebook.com/tools/debug/" target="_blank" rel="noopener">Facebook Sharing Debugger</a>.
                        </p>
                    </div>
                </div>
            </form>
        </div>

        <div class="settings-card neumorphic">
            <div class="settings-card-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
            </div>
            <h3>Cambiar Contraseña</h3>
            <form method="POST" action="<?php echo $viewInstance->route('superadmin/settings'); ?>?action=change_password" class="settings-form" data-ajax="true" onsubmit="return validatePasswordForm(this)">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <div class="form-group">
                    <label>Contraseña Actual *</label>
                    <input type="password" name="current_password" required class="neumorphic-input">
                </div>
                <div class="form-group">
                    <label>Nueva Contraseña *</label>
                    <input type="password" name="new_password" required minlength="8" class="neumorphic-input">
                </div>
                <div class="form-group">
                    <label>Confirmar Nueva Contraseña *</label>
                    <input type="password" name="confirm_password" required minlength="8" class="neumorphic-input">
                </div>
                <button type="submit" class="btn btn-primary neumorphic-btn">Cambiar Contraseña</button>
            </form>
        </div>

        <div class="settings-card neumorphic">
            <div class="settings-card-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                </svg>
            </div>
            <h3>Seguridad</h3>
            <div class="security-info">
                <div class="security-item">
                    <label>Último inicio de sesión</label>
                    <p><?php echo $_SESSION['last_login_at'] ?? 'No disponible'; ?></p>
                </div>
                <div class="security-item">
                    <label>IP de último acceso</label>
                    <p><?php echo $_SESSION['last_login_ip'] ?? 'No disponible'; ?></p>
                </div>
            </div>
        </div>

        <div class="settings-card neumorphic">
            <div class="settings-card-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="5"></circle>
                    <line x1="12" y1="1" x2="12" y2="3"></line>
                    <line x1="12" y1="21" x2="12" y2="23"></line>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                    <line x1="1" y1="12" x2="3" y2="12"></line>
                    <line x1="21" y1="12" x2="23" y2="12"></line>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                </svg>
            </div>
            <h3>Apariencia</h3>
            <div class="theme-selector">
                <div class="theme-option" onclick="changeTheme('light')" id="themeLight">
                    <div class="theme-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="5"></circle>
                            <line x1="12" y1="1" x2="12" y2="3"></line>
                            <line x1="12" y1="21" x2="12" y2="23"></line>
                            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                            <line x1="1" y1="12" x2="3" y2="12"></line>
                            <line x1="21" y1="12" x2="23" y2="12"></line>
                            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                        </svg>
                    </div>
                    <span>Modo Claro</span>
                </div>
                <div class="theme-option" onclick="changeTheme('dark')" id="themeDark">
                    <div class="theme-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                        </svg>
                    </div>
                    <span>Modo Oscuro</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function validatePasswordForm(form) {
    const newPassword = form.querySelector('input[name="new_password"]').value;
    const confirmPassword = form.querySelector('input[name="confirm_password"]').value;

    if (newPassword !== confirmPassword) {
        alert('Las contraseñas nuevas no coinciden');
        return false;
    }

    if (newPassword.length < 8) {
        alert('La contraseña debe tener al menos 8 caracteres');
        return false;
    }

    return true;
}

function changeTheme(theme) {
    document.body.classList.toggle('dark-mode', theme === 'dark');
    localStorage.setItem('theme', theme);
    document.getElementById('themeLight')?.classList.toggle('active', theme === 'light');
    document.getElementById('themeDark')?.classList.toggle('active', theme === 'dark');
}

document.addEventListener('DOMContentLoaded', function() {
    const theme = localStorage.getItem('theme') || 'light';
    changeTheme(theme);
});
</script>
