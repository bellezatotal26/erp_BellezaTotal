<?php
/**
 * Panel Integraciones e-commerce (WooCommerce / Mercado Libre).
 * Usado desde Inventario. Variables: $catalogStatuses, $mlOAuthRedirect, $invBase, $viewInstance,
 * $canEditProduct, $canCreateProduct.
 */
$catalogStatuses = $catalogStatuses ?? [];
$mlOAuthRedirect = $mlOAuthRedirect ?? '';
$invBase = $invBase ?? ($viewInstance->route('app/inventario') ?? '/app/inventario');
$canEditProduct = $canEditProduct ?? false;
$canCreateProduct = $canCreateProduct ?? false;
$selectedCatalog = (string)($_GET['catalog'] ?? 'woocommerce');
if (!isset($catalogStatuses[$selectedCatalog])) {
    $selectedCatalog = array_key_first($catalogStatuses) ?: 'woocommerce';
}
$providerMeta = [
    'woocommerce' => 'Importa productos de su tienda WooCommerce al inventario (canal: WooCommerce).',
    'mercadolibre' => 'Importa publicaciones activas de Mercado Libre al inventario (canal: Mercado Libre).',
];
?>
<div class="inv-integrations-inner">
    <p style="margin:0 0 12px;font-size:13px;color:var(--color-text-secondary);">
        Configure WooCommerce o Mercado Libre e importe el catálogo al inventario.
        Las credenciales se guardan cifradas por empresa.
    </p>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
        <?php if (empty($catalogStatuses)): ?>
            <div class="alert alert-warning" style="margin:0;">No se cargaron conectores. Verifique el despliegue (CatalogSyncService).</div>
        <?php endif; ?>
        <?php foreach ($catalogStatuses as $code => $st): ?>
            <button type="button"
                    class="btn catalog-tab-btn <?php echo $selectedCatalog === $code ? 'btn-primary' : 'btn-secondary'; ?>"
                    data-catalog="<?php echo htmlspecialchars($code); ?>"
                    onclick="invSelectCatalogTab('<?php echo htmlspecialchars($code); ?>')">
                <?php echo htmlspecialchars($st['label'] ?? $code); ?>
                <?php if (!empty($st['enabled']) && !empty($st['configured'])): ?>
                    <span class="badge badge-success" style="margin-left:4px;">Listo</span>
                <?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>

    <?php foreach ($catalogStatuses as $code => $st): ?>
        <?php
        $form = $st['form'] ?? [];
        $isActive = $selectedCatalog === $code;
        $lastSync = trim((string)($form['last_sync_at'] ?? ''));
        ?>
        <div class="catalog-provider-panel" data-catalog-panel="<?php echo htmlspecialchars($code); ?>" style="<?php echo $isActive ? '' : 'display:none;'; ?>">
            <h4 style="margin:0 0 6px;"><?php echo htmlspecialchars($st['label'] ?? $code); ?></h4>
            <p style="margin:0 0 8px;font-size:13px;color:var(--color-text-secondary);">
                <?php echo htmlspecialchars($providerMeta[$code] ?? ''); ?>
            </p>
            <p style="margin:0 0 12px;font-size:13px;">
                Estado:
                <?php if (!empty($st['configured'])): ?>
                    <span class="badge badge-success">Listo</span>
                <?php elseif (!empty($st['enabled'])): ?>
                    <span class="badge badge-warning">Habilitado, faltan credenciales</span>
                <?php else: ?>
                    <span class="badge badge-danger">Deshabilitado</span>
                <?php endif; ?>
                <?php if ($lastSync !== ''): ?>
                    <span style="margin-left:8px;color:var(--color-text-secondary);">Última sync: <?php echo htmlspecialchars($lastSync); ?></span>
                <?php endif; ?>
            </p>

            <?php if ($canEditProduct): ?>
            <form method="POST" action="<?php echo htmlspecialchars($invBase); ?>?action=save-catalog-integration" data-ajax="true">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <input type="hidden" name="provider" value="<?php echo htmlspecialchars($code); ?>">

                <div class="form-group" style="margin-bottom:12px;">
                    <label><input type="checkbox" name="enabled" value="1" <?php echo !empty($form['enabled']) && $form['enabled'] !== '0' ? 'checked' : ''; ?>> Habilitar conector</label>
                </div>

                <?php if ($code === 'woocommerce'): ?>
                    <div class="form-grid">
                        <div class="form-group" style="grid-column:1/-1;"><label>URL de la tienda</label><input class="form-control" name="store_url" value="<?php echo htmlspecialchars($form['store_url'] ?? ''); ?>" placeholder="https://mitienda.com"></div>
                        <div class="form-group"><label>Consumer Key</label><input class="form-control" name="consumer_key" value="<?php echo htmlspecialchars($form['consumer_key'] ?? ''); ?>" autocomplete="off"></div>
                        <div class="form-group"><label>Consumer Secret <?php if (!empty($form['consumer_secret_set'])): ?><small>(guardado)</small><?php endif; ?></label><input class="form-control" type="password" name="consumer_secret" placeholder="<?php echo !empty($form['consumer_secret_set']) ? 'Dejar vacío para conservar' : 'Secret'; ?>" autocomplete="new-password"></div>
                        <div class="form-group" style="grid-column:1/-1;"><label>Política de stock (quién manda)</label>
                            <?php $auth = $form['stock_authority'] ?? ($st['stock_authority'] ?? 'create_only'); ?>
                            <select class="form-control" name="stock_authority">
                                <option value="create_only" <?php echo $auth === 'create_only' ? 'selected' : ''; ?>>Solo al crear (recomendado) — updates no tocan stock ERP</option>
                                <option value="erp" <?php echo $auth === 'erp' ? 'selected' : ''; ?>>ERP manda — nunca sincroniza cantidades</option>
                                <option value="store" <?php echo $auth === 'store' ? 'selected' : ''; ?>>Tienda manda — ajusta stock local al remoto</option>
                            </select>
                        </div>
                    </div>
                    <p style="font-size:12px;color:var(--color-text-secondary);">WooCommerce → Ajustes → Avanzado → REST API. Permisos de lectura.</p>

                <?php elseif ($code === 'mercadolibre'): ?>
                    <div class="form-grid">
                        <div class="form-group"><label>Client ID (App ML)</label><input class="form-control" name="client_id" value="<?php echo htmlspecialchars($form['client_id'] ?? ''); ?>" autocomplete="off"></div>
                        <div class="form-group"><label>Client Secret <?php if (!empty($form['client_secret_set'])): ?><small>(guardado)</small><?php endif; ?></label><input class="form-control" type="password" name="client_secret" placeholder="<?php echo !empty($form['client_secret_set']) ? 'Dejar vacío para conservar' : 'Secret'; ?>" autocomplete="new-password"></div>
                        <div class="form-group" style="grid-column:1/-1;"><label>Redirect URI (registre esta URL en su app ML)</label>
                            <input class="form-control" readonly value="<?php echo htmlspecialchars($mlOAuthRedirect); ?>">
                        </div>
                        <div class="form-group"><label>Site ID</label><input class="form-control" name="site_id" value="<?php echo htmlspecialchars($form['site_id'] ?? 'MCO'); ?>"></div>
                        <div class="form-group"><label>User ID</label><input class="form-control" name="user_id" value="<?php echo htmlspecialchars($form['user_id'] ?? ''); ?>" placeholder="Se completa con OAuth"></div>
                        <div class="form-group" style="grid-column:1/-1;"><label>Access Token (manual / legado) <?php if (!empty($form['access_token_set'])): ?><small>(guardado)</small><?php endif; ?></label><input class="form-control" type="password" name="access_token" placeholder="<?php echo !empty($form['access_token_set']) ? 'Dejar vacío para conservar' : 'Opcional si usa OAuth'; ?>" autocomplete="new-password"></div>
                        <div class="form-group" style="grid-column:1/-1;"><label>Refresh Token <?php if (!empty($form['refresh_token_set'])): ?><small>(guardado)</small><?php endif; ?></label><input class="form-control" type="password" name="refresh_token" placeholder="<?php echo !empty($form['refresh_token_set']) ? 'Dejar vacío para conservar' : 'Se guarda con OAuth'; ?>" autocomplete="new-password"></div>
                        <div class="form-group" style="grid-column:1/-1;"><label>Política de stock</label>
                            <?php $auth = $form['stock_authority'] ?? ($st['stock_authority'] ?? 'create_only'); ?>
                            <select class="form-control" name="stock_authority">
                                <option value="create_only" <?php echo $auth === 'create_only' ? 'selected' : ''; ?>>Solo al crear (recomendado)</option>
                                <option value="erp" <?php echo $auth === 'erp' ? 'selected' : ''; ?>>ERP manda</option>
                                <option value="store" <?php echo $auth === 'store' ? 'selected' : ''; ?>>ML manda (ajusta stock local)</option>
                            </select>
                        </div>
                    </div>
                    <p style="margin:10px 0;">
                        <a class="btn btn-secondary" href="<?php echo htmlspecialchars($invBase); ?>?action=ml-oauth-start">Conectar con Mercado Libre (OAuth)</a>
                        <?php if (!empty($st['meta']['token_expires_at'])): ?>
                            <small style="margin-left:8px;color:var(--color-text-secondary);">Expira: <?php echo htmlspecialchars($st['meta']['token_expires_at']); ?><?php echo !empty($st['meta']['has_refresh']) ? ' · refresh activo' : ''; ?></small>
                        <?php endif; ?>
                    </p>
                    <p style="font-size:12px;color:var(--color-text-secondary);">El sistema renueva el access_token automáticamente con refresh_token cuando vence o responde 401.</p>
                <?php endif; ?>

                <div style="margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary">Guardar configuración</button>
                    <button type="button" class="btn btn-secondary" onclick="testCatalogProvider(this, '<?php echo htmlspecialchars($code); ?>')">Probar conexión</button>
                    <span class="catalog-test-result" data-provider="<?php echo htmlspecialchars($code); ?>" style="font-size:13px;"></span>
                </div>
            </form>
            <?php else: ?>
                <p class="dashboard-empty" style="margin:0;">No tiene permiso para editar integraciones de inventario.</p>
            <?php endif; ?>

            <?php if ($canCreateProduct && !empty($st['enabled']) && !empty($st['configured'])): ?>
                <form method="POST" action="<?php echo htmlspecialchars($invBase); ?>?action=import_catalog" data-ajax="true" style="margin-top:16px;padding-top:14px;border-top:1px solid var(--color-border);">
                    <?php echo \SoftNova\Core\csrf_field(); ?>
                    <input type="hidden" name="provider" value="<?php echo htmlspecialchars($code); ?>">
                    <div class="form-grid" style="align-items:end;">
                        <div class="form-group" style="margin:0;">
                            <label>Cantidad máx. a importar</label>
                            <select class="form-control" name="limit">
                                <option value="50">50</option>
                                <option value="100" selected>100</option>
                                <option value="250">250</option>
                                <option value="500">500</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <button type="submit" class="btn btn-primary" onclick="return confirm('¿Importar productos desde <?php echo htmlspecialchars($st['label'] ?? $code); ?>?')">
                                Importar ahora
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<script>
function invSelectCatalogTab(code) {
    document.querySelectorAll('.catalog-tab-btn').forEach(function(btn) {
        var on = btn.getAttribute('data-catalog') === code;
        btn.classList.toggle('btn-primary', on);
        btn.classList.toggle('btn-secondary', !on);
    });
    document.querySelectorAll('[data-catalog-panel]').forEach(function(panel) {
        panel.style.display = panel.getAttribute('data-catalog-panel') === code ? '' : 'none';
    });
}
function testCatalogProvider(btn, provider) {
    var result = document.querySelector('.catalog-test-result[data-provider="' + provider + '"]');
    if (!result) return;
    btn.disabled = true;
    result.textContent = 'Probando...';
    result.style.color = 'var(--color-text-secondary)';
    var url = <?php echo json_encode($invBase); ?>
        + '?action=catalog-test&provider=' + encodeURIComponent(provider);
    fetch(url, {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(response){ return response.json(); })
        .then(function(data){
            result.textContent = data.message || (data.success ? 'Conexión OK' : 'Error');
            result.style.color = data.success ? '#10B981' : '#DC2626';
        })
        .catch(function(){
            result.textContent = 'Error de red';
            result.style.color = '#DC2626';
        })
        .finally(function(){ btn.disabled = false; });
}
</script>
