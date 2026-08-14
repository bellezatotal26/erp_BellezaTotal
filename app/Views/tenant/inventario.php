<?php
$layout = 'tenant';
$title = 'Inventario - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Gestión de Inventario';
$tenantName = $tenantName ?? 'Mi Empresa';
$userName = $userName ?? 'Usuario';
$products = $products ?? [];
$categories = $categories ?? [];
$lowStock = $lowStock ?? 0;
$totalProducts = $totalProducts ?? 0;
$totalServices = $totalServices ?? 0;
$currency = $currency ?? ['symbol' => '$', 'decimals' => 0];
$typeFilter = $typeFilter ?? '';
$categoryFilter = $categoryFilter ?? 0;
$stockFilter = $stockFilter ?? '';
$locationFilter = $locationFilter ?? '';
$locationOptions = $locationOptions ?? [];
$needBarcode = true;
$pageScripts = ['inventario.js'];
$channelFilter = $channelFilter ?? '';
$catalogStatuses = $catalogStatuses ?? [];
$filters = $filters ?? ['q' => '', 'sort' => 'name', 'dir' => 'asc', 'query' => []];
$canCreateProduct = \SoftNova\Core\TenantMiddleware::canDo('create', 'inventario');
$canEditProduct = \SoftNova\Core\TenantMiddleware::canDo('edit', 'inventario');
$canViewPrestamos = \SoftNova\Core\TenantMiddleware::canAccess('prestamos');
$canCreatePrestamos = \SoftNova\Core\TenantMiddleware::canDo('create', 'prestamos');
$canDeleteProduct = \SoftNova\Core\TenantMiddleware::canDo('delete', 'inventario');
$canExportInv = \SoftNova\Core\TenantMiddleware::canDo('export', 'inventario');
$inventoryValue = $inventoryValue ?? ['cost' => 0.0, 'sale' => 0.0, 'units' => 0];
$expirySummary = $expirySummary ?? [];
$expiryAlerts = (int)($expirySummary['expired_lots'] ?? 0) + (int)($expirySummary['expiring_lots'] ?? 0);
$offerOptions = $offerOptions ?? \SoftNova\Services\ProductOfferService::formOptions();
$offerPercents = $offerOptions['percents'] ?? [5, 10, 15, 20, 25, 30, 40, 50];
$offerReasons = $offerOptions['reasons'] ?? [];
$offerDurations = $offerOptions['durations'] ?? [];
$unitOptions = [
    'UND' => 'UND (unidad)',
    'UNIDAD' => 'UNIDAD',
    'DOC' => 'DOC (docena)',
    'PAR' => 'PAR',
    'KG' => 'KG',
    'GR' => 'GR',
    'LTS' => 'Lts (litros)',
    'ML' => 'ML',
    'CM' => 'Cm',
    'MT' => 'Mt (metros)',
    'M2' => 'M²',
    'M3' => 'M³',
    'CAJA' => 'CAJA',
    'PAQ' => 'PAQ (paquete)',
    'ROLLO' => 'ROLLO',
    'GL' => 'GL (galón)',
    'LB' => 'LB (libra)',
    'OZ' => 'OZ',
];

function fmtI(float $a, array $c): string
{
    return $c['symbol'] . ' ' . number_format($a, $c['decimals'], $c['decimal'] ?? ',', $c['thousands'] ?? '.');
}
?>
<meta name="currency-symbol" content="<?php echo $currency['symbol']; ?>">
<meta name="currency-decimals" content="<?php echo $currency['decimals']; ?>">
<?php echo flashMessage(); ?>

<div class="inv-kpi-grid">
    <div class="inv-kpi">
        <span class="inv-kpi-label">Productos</span>
        <span class="inv-kpi-value"><?php echo (int)$totalProducts; ?></span>
    </div>
    <div class="inv-kpi">
        <span class="inv-kpi-label">Servicios</span>
        <span class="inv-kpi-value" style="color:#3B82F6;"><?php echo (int)$totalServices; ?></span>
    </div>
    <div class="inv-kpi">
        <span class="inv-kpi-label">Stock bajo</span>
        <span class="inv-kpi-value" style="color:<?php echo $lowStock > 0 ? '#DC2626' : '#10B981'; ?>;"><?php echo (int)$lowStock; ?></span>
    </div>
    <div class="inv-kpi">
        <span class="inv-kpi-label">Total ítems</span>
        <span class="inv-kpi-value"><?php echo (int)$totalProducts + (int)$totalServices; ?></span>
    </div>
    <div class="inv-kpi inv-kpi--money">
        <span class="inv-kpi-label">Costo del inventario</span>
        <span class="inv-kpi-value"><?php echo fmtI((float)($inventoryValue['cost'] ?? 0), $currency); ?></span>
        <span class="inv-kpi-hint">
            A precio de venta: <strong><?php echo fmtI((float)($inventoryValue['sale'] ?? 0), $currency); ?></strong>
            · <?php echo (int)($inventoryValue['units'] ?? 0); ?> uds
        </span>
    </div>
    <a href="<?php echo $viewInstance->route('app/inventario'); ?>?action=vencimientos" class="inv-kpi inv-kpi--link">
        <span class="inv-kpi-label">Por vencer / vencidos</span>
        <span class="inv-kpi-value" style="color:<?php echo $expiryAlerts > 0 ? '#DC2626' : '#10B981'; ?>;"><?php echo (int)$expiryAlerts; ?></span>
        <span class="inv-kpi-hint">Ver control de vencimientos</span>
    </a>
    <?php if ($canViewPrestamos): ?>
    <a href="<?php echo $viewInstance->route('app/prestamos'); ?>" class="inv-kpi inv-kpi--link">
        <span class="inv-kpi-label">Préstamos</span>
        <span class="inv-kpi-value" style="color:var(--color-primary);">Ir →</span>
        <span class="inv-kpi-hint">Salidas por préstamo y devoluciones</span>
    </a>
    <?php endif; ?>
</div>
<p style="font-size:13px;color:var(--color-text-secondary);margin:-6px 0 16px;">
    Producto <strong>COMODIN</strong> (comodín): al venderlo en Caja/Ventas pide nombre y precio libres; no descuenta inventario. No lo elimine.
</p>

<?php
$invBase = $viewInstance->route('app/inventario');
$exportQuery = http_build_query(array_merge(['action' => 'export'], $filters['query'] ?? []));
$exportCountQuery = http_build_query(array_merge(['action' => 'export_count'], $filters['query'] ?? []));
?>
<form method="GET" action="<?php echo $invBase; ?>" class="inv-filters-form" style="margin-bottom:16px;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;flex:1;min-width:220px;">
            <label style="font-size:12px;">Buscar</label>
            <input type="text" name="q" value="<?php echo htmlspecialchars($filters['q'] ?? ''); ?>" class="form-control" placeholder="Nombre, SKU, EAN o descripción...">
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Tipo</label>
            <select name="type" class="form-control" style="width:auto;">
                <option value="">Todos</option>
                <option value="product" <?php echo $typeFilter === 'product' ? 'selected' : ''; ?>>Productos</option>
                <option value="service" <?php echo $typeFilter === 'service' ? 'selected' : ''; ?>>Servicios</option>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Categoría</label>
            <select name="category_id" class="form-control" style="width:auto;">
                <option value="0">Todas</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo (int)$categoryFilter === (int)$cat['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Stock</label>
            <select name="stock_state" class="form-control" style="width:auto;">
                <option value="">Cualquiera</option>
                <option value="low" <?php echo $stockFilter === 'low' ? 'selected' : ''; ?>>Stock bajo</option>
                <option value="out" <?php echo $stockFilter === 'out' ? 'selected' : ''; ?>>Agotado</option>
            </select>
        </div>
        <details class="inv-more-filters">
            <summary class="btn btn-secondary">Más filtros</summary>
            <div class="inv-more-filters-panel">
                <div class="form-group" style="margin:0;">
                    <label style="font-size:12px;">Ubicación</label>
                    <select name="location" class="form-control">
                        <option value="">Todas</option>
                        <?php foreach ($locationOptions as $loc):
                            if (($loc['value'] ?? '') === '__other__') continue;
                        ?>
                            <option value="<?php echo htmlspecialchars((string)$loc['value']); ?>" <?php echo $locationFilter === (string)$loc['value'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)$loc['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:12px;">Canal</label>
                    <select name="channel" class="form-control">
                        <option value="">Todos</option>
                        <?php foreach (\SoftNova\Core\product_channels() as $ck => $cl): ?>
                            <option value="<?php echo htmlspecialchars($ck); ?>" <?php echo $channelFilter === $ck ? 'selected' : ''; ?>><?php echo htmlspecialchars($cl); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </details>
        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($filters['sort'] ?? 'name'); ?>">
        <input type="hidden" name="dir" value="<?php echo htmlspecialchars($filters['dir'] ?? 'asc'); ?>">
        <div style="display:flex;gap:6px;">
            <button type="submit" class="btn btn-primary" title="Aplicar filtros">Filtrar</button>
            <a href="<?php echo $invBase; ?>" class="btn btn-secondary" title="Limpiar filtros">Limpiar</a>
        </div>
    </div>
</form>

<div class="inv-toolbar" style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center;">
    <?php if ($canCreateProduct): ?>
        <button onclick="openInventarioModal()" class="btn btn-primary neumorphic-btn" title="Nuevo producto">+ Nuevo Producto</button>
    <?php endif; ?>
    <a href="<?php echo $invBase; ?>?action=traceability" class="btn btn-secondary" title="Trazabilidad">Trazabilidad</a>
    <a href="<?php echo $invBase; ?>?action=vencimientos" class="btn btn-secondary" title="Control de vencimientos">
        Vencimientos<?php echo $expiryAlerts > 0 ? ' (' . $expiryAlerts . ')' : ''; ?>
    </a>
    <details class="inv-tools-menu">
        <summary class="btn btn-secondary">Herramientas</summary>
        <div class="inv-tools-panel">
            <?php if ($canCreateProduct): ?>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('categoriesModal').style.display='flex'">Categorías</button>
            <?php endif; ?>
            <?php if ($canExportInv): ?>
                <a href="<?php echo $invBase; ?>?action=category_report<?php echo (int)$categoryFilter > 0 ? '&category_id=' . (int)$categoryFilter : ''; ?>" class="btn btn-secondary" target="_blank">Reporte por categoría</a>
                <a href="<?php echo $invBase; ?>?action=category_report&format=csv<?php echo (int)$categoryFilter > 0 ? '&category_id=' . (int)$categoryFilter : ''; ?>" class="btn btn-secondary">CSV categorías</a>
                <a href="<?php echo $invBase . '?' . $exportQuery; ?>" class="btn btn-secondary">Exportar CSV</a>
                <a href="<?php echo $invBase . '?' . $exportCountQuery; ?>" class="btn btn-secondary" title="CSV con Cantidad vacía para conteo físico e impresión">Exportar para conteo</a>
            <?php endif; ?>
            <?php if ($canCreateProduct): ?>
                <a href="<?php echo $invBase; ?>?action=import_template" class="btn btn-secondary" title="Descargar plantilla CSV para importar">Plantilla CSV</a>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('importCsvModal').style.display='flex'">Importar CSV</button>
            <?php endif; ?>
            <a href="<?php echo $viewInstance->route('app/compras'); ?>?action=reposicion" class="btn btn-secondary">Reposición</a>
            <a href="<?php echo $viewInstance->route('app/compras'); ?>" class="btn btn-secondary">Compras</a>
            <?php if ($canEditProduct): ?>
                <button type="button" class="btn btn-secondary" onclick="openStockExitModal()">Salidas</button>
            <?php endif; ?>
            <?php
            $hasContabilidad = \SoftNova\Core\TenantMiddleware::canAccess('contabilidad');
            $wooSt = $catalogStatuses['woocommerce'] ?? [];
            $mlSt = $catalogStatuses['mercadolibre'] ?? [];
            ?>
            <?php if ($canCreateProduct && $hasContabilidad): ?>
                <a class="btn btn-secondary" href="<?php echo $viewInstance->route('app/contabilidad'); ?>?tab=integrations&amp;provider=woocommerce">WooCommerce</a>
                <a class="btn btn-secondary" href="<?php echo $viewInstance->route('app/contabilidad'); ?>?tab=integrations&amp;provider=mercadolibre">Mercado Libre</a>
            <?php endif; ?>
            <?php if ($canCreateProduct): ?>
                <?php foreach (['woocommerce' => $wooSt, 'mercadolibre' => $mlSt] as $code => $st): ?>
                    <?php if (!empty($st['enabled']) && !empty($st['configured'])): ?>
                        <form method="POST" action="<?php echo $invBase; ?>?action=import_catalog" data-ajax="true" style="display:inline;margin:0;">
                            <?php echo \SoftNova\Core\csrf_field(); ?>
                            <input type="hidden" name="provider" value="<?php echo htmlspecialchars($code); ?>">
                            <button type="submit" class="btn btn-primary" onclick="return confirm('¿Importar productos desde <?php echo htmlspecialchars($st['label'] ?? $code); ?>?')">
                                Importar <?php echo htmlspecialchars($st['label'] ?? $code); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </details>
    <?php if ($canDeleteProduct && !empty($products)): ?>
    <div class="inv-bulk-bar" id="invBulkBar" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;user-select:none;margin:0;">
            <input type="checkbox" id="invSelectAll" style="width:16px;height:16px;">
            Seleccionar página
        </label>
        <button type="button" class="btn btn-sm btn-danger" id="invBulkDeleteBtn" disabled title="Desactivar productos seleccionados">
            Eliminar seleccionados (<span id="invBulkCount">0</span>)
        </button>
    </div>
    <form method="POST" action="<?php echo $invBase; ?>?action=delete_bulk" data-ajax="true" id="invBulkDeleteForm" style="display:none;">
        <?php echo \SoftNova\Core\csrf_field(); ?>
        <div id="invBulkIds"></div>
    </form>
    <?php endif; ?>
    <div class="inv-view-toggle" role="group" aria-label="Vista de productos" style="margin-left:auto;display:flex;gap:6px;">
        <button type="button" class="btn btn-secondary inv-view-btn is-active" id="invViewGridBtn" onclick="setInventarioView('grid')" title="Vista con imágenes">Cuadrícula</button>
        <button type="button" class="btn btn-secondary inv-view-btn" id="invViewListBtn" onclick="setInventarioView('list')" title="Vista en lista sin imágenes">Lista</button>
    </div>
</div>

<div id="invCatalog" class="inv-catalog inv-catalog--grid">
<div class="inv-products-grid">
    <?php if (empty($products)): ?>
        <div class="card neumorphic" style="text-align:center;padding:40px;grid-column:1/-1;">
            <?php $hasFilters = ($filters['q'] ?? '') !== '' || $typeFilter !== '' || (int)$categoryFilter > 0 || $stockFilter !== ''; ?>
            <p style="color:var(--color-text-secondary);"><?php echo $hasFilters ? 'No hay ítems con esos filtros' : 'No hay ítems registrados'; ?></p>
        </div>
    <?php else: ?>
        <?php foreach ($products as $p): ?>
            <?php $isService = ($p['product_type'] ?? 'product') === 'service'; ?>
            <div class="card neumorphic product-card"
                 style="border-left:5px solid <?php echo $isService ? '#3B82F6' : 'var(--color-primary)'; ?>;padding:20px;position:relative;"
                 data-id="<?php echo $p['id']; ?>"
                 data-name="<?php echo htmlspecialchars($p['name']); ?>"
                 data-code="<?php echo htmlspecialchars($p['code'] ?? ''); ?>"
                 data-barcode="<?php echo htmlspecialchars($p['barcode'] ?? '', ENT_QUOTES); ?>"
                 data-cat="<?php echo $p['category_id'] ?? ''; ?>"
                 data-location="<?php echo htmlspecialchars($p['location'] ?? '', ENT_QUOTES); ?>"
                 data-unit="<?php echo htmlspecialchars($p['unit'] ?? 'UNIDAD'); ?>"
                 data-pcompra="<?php echo $p['purchase_price'] ?? 0; ?>"
                 data-pventa="<?php echo $p['sale_price'] ?? 0; ?>"
                 data-pmayoreo="<?php echo $p['wholesale_price'] ?? 0; ?>"
                 data-stock="<?php echo $p['stock'] ?? 0; ?>"
                 data-minstock="<?php echo $p['min_stock'] ?? 5; ?>"
                 data-maxstock="<?php echo $p['max_stock'] ?? ''; ?>"
                 data-tracklots="<?php echo !empty($p['track_lots']) ? '1' : '0'; ?>"
                 data-alertdays="<?php echo $p['expiry_alert_days'] ?? 30; ?>"
                 data-status="<?php echo $p['status'] ?? 'active'; ?>"
                 data-type="<?php echo $p['product_type'] ?? 'product'; ?>"
                 data-desc="<?php echo htmlspecialchars((string)($p['description'] ?? ''), ENT_QUOTES); ?>"
                 data-notes="<?php echo htmlspecialchars((string)($p['notes'] ?? ''), ENT_QUOTES); ?>"
                 data-debt="<?php echo (int)($p['stock_debt_qty'] ?? 0); ?>"
                 data-supplier-id="<?php echo (int)($p['primary_supplier_id'] ?? 0); ?>"
                 data-supplier-sku="<?php echo htmlspecialchars((string)($p['primary_supplier_sku'] ?? ''), ENT_QUOTES); ?>"
                 data-offer="<?php echo !empty($p['offer_active']) ? '1' : '0'; ?>"
                 data-offer-price="<?php echo htmlspecialchars((string)($p['offer_price'] ?? '')); ?>"
                 data-offer-original="<?php echo htmlspecialchars((string)($p['offer_original_price'] ?? '')); ?>"
                 data-offer-percent="<?php echo htmlspecialchars((string)($p['offer_percent'] ?? '')); ?>"
                 data-offer-reason="<?php echo htmlspecialchars((string)($p['offer_reason'] ?? ''), ENT_QUOTES); ?>"
                 data-offer-starts="<?php echo htmlspecialchars((string)($p['offer_starts_at'] ?? ''), ENT_QUOTES); ?>"
                 data-offer-ends="<?php echo htmlspecialchars((string)($p['offer_ends_at'] ?? ''), ENT_QUOTES); ?>">
                <?php if ($canDeleteProduct): ?>
                <label class="inv-select-wrap" title="Seleccionar" style="position:absolute;top:10px;right:10px;z-index:2;margin:0;cursor:pointer;">
                    <input type="checkbox" class="inv-product-check" value="<?php echo (int)$p['id']; ?>" style="width:18px;height:18px;">
                </label>
                <?php endif; ?>
                <div style="display:flex;align-items:flex-start;gap:18px;">
                    <div class="product-card-image" style="width:90px;height:90px;border-radius:14px;overflow:hidden;flex-shrink:0;background:var(--bg-input);display:flex;align-items:center;justify-content:center;border:2px solid var(--color-border);box-shadow:0 2px 8px rgba(0,0,0,0.06);">
                        <?php if (!empty($p['image'])): ?>
                            <?php $imgSrc = \SoftNova\Core\media_url($p['image']); ?>
                            <img src="<?php echo htmlspecialchars($imgSrc); ?>"
                                 style="width:100%;height:100%;object-fit:cover;cursor:pointer;"
                                 onclick="openImageLightbox('<?php echo htmlspecialchars($imgSrc); ?>','<?php echo htmlspecialchars(addslashes($p['name'])); ?>')"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <span style="display:none;font-size:36px;"><?php echo $isService ? '🔧' : '📦'; ?></span>
                        <?php else: ?>
                            <span style="font-size:36px;"><?php echo $isService ? '🔧' : '📦'; ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                            <h4 style="margin:0;font-size:15px;color:var(--color-primary);"><?php echo htmlspecialchars($p['name']); ?></h4>
                            <span class="badge <?php echo $isService ? 'badge-info' : 'badge-success'; ?>" style="flex-shrink:0;">
                                <?php echo $isService ? 'Servicio' : 'Producto'; ?>
                            </span>
                        </div>
                        <?php if (!empty($p['code'])): ?>
                            <div style="font-size:11px;color:var(--color-text-secondary);margin:3px 0;">SKU: <?php echo htmlspecialchars($p['code']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($p['barcode'])): ?>
                            <div style="font-size:11px;color:var(--color-text-secondary);margin:3px 0;">EAN: <?php echo htmlspecialchars($p['barcode']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($p['category_name'])): ?>
                            <div style="font-size:12px;color:var(--color-text-secondary);margin-bottom:2px;">📂 <?php echo htmlspecialchars($p['category_name']); ?></div>
                        <?php endif; ?>
                        <div style="font-size:13px;margin:4px 0;">
                            <span style="color:var(--color-text-secondary);">Venta:</span>
                            <?php if (!empty($p['offer_active'])): ?>
                                <?php
                                $offerScheduled = !empty($p['offer_starts_at']) && strtotime((string)$p['offer_starts_at']) > time();
                                ?>
                                <?php if ($offerScheduled): ?>
                                    <strong style="color:#10B981;"><?php echo fmtI((float)$p['sale_price'], $currency); ?></strong>
                                    <span class="badge badge-warning" style="margin-left:4px;" title="Inicia <?php echo date('d/m/Y H:i', strtotime((string)$p['offer_starts_at'])); ?>">
                                        Programada → <?php echo fmtI((float)($p['offer_price'] ?? 0), $currency); ?>
                                        <?php echo !empty($p['offer_percent']) ? ' (−' . rtrim(rtrim(number_format((float)$p['offer_percent'], 1, '.', ''), '0'), '.') . '%)' : ''; ?>
                                    </span>
                                <?php else: ?>
                                <strong style="color:#DC2626;"><?php echo fmtI((float)$p['sale_price'], $currency); ?></strong>
                                <span style="text-decoration:line-through;color:var(--color-text-secondary);font-size:12px;margin-left:4px;">
                                    <?php echo fmtI((float)($p['offer_original_price'] ?? 0), $currency); ?>
                                </span>
                                <span class="badge badge-danger" style="margin-left:4px;" title="Hasta <?php echo !empty($p['offer_ends_at']) ? date('d/m/Y H:i', strtotime((string)$p['offer_ends_at'])) : ''; ?>">
                                    Oferta<?php echo !empty($p['offer_percent']) ? ' −' . rtrim(rtrim(number_format((float)$p['offer_percent'], 1, '.', ''), '0'), '.') . '%' : ''; ?>
                                </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <strong style="color:#10B981;"><?php echo fmtI((float)$p['sale_price'], $currency); ?></strong>
                            <?php endif; ?>
                            <?php if ($p['purchase_price'] > 0): ?>
                                <span style="font-size:11px;color:var(--color-text-secondary);margin-left:5px;">
                                    (Compra: <?php echo fmtI($p['purchase_price'], $currency); ?>)
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isService): ?>
                            <div style="margin:4px 0;">
                                <span class="badge <?php echo $p['stock'] <= $p['min_stock'] ? 'badge-danger' : 'badge-success'; ?>">
                                    📦 Stock: <?php echo $p['stock']; ?>
                                    <?php if ($p['stock'] <= $p['min_stock']): ?>⚠️ Bajo<?php endif; ?>
                                </span>
                                <?php if (!empty($p['reserved_qty']) && $p['reserved_qty'] > 0): ?>
                                    <span class="badge badge-warning" style="margin-left:4px;" title="<?php echo $p['reserved_qty']; ?> productos reservados en cotizaciones pendientes">
                                        📝 <?php echo $p['reserved_qty']; ?> en cotización
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($p['stock_debt_qty']) && (int)$p['stock_debt_qty'] > 0): ?>
                                    <span class="badge badge-danger" style="margin-left:4px;" title="Unidades vendidas sin stock físico pendientes de reponer">
                                        Deuda stock: <?php echo (int)$p['stock_debt_qty']; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($p['notes'])): ?>
                            <div style="font-size:12px;color:var(--color-text-secondary);margin-top:4px;white-space:pre-wrap;">
                                <?php echo \SoftNova\Core\e((string)$p['notes']); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($p['last_sale_date'])): ?>
                            <div style="font-size:11px;color:var(--color-text-secondary);">
                                🕐 Última venta: <?php echo date('d/m/Y', strtotime($p['last_sale_date'])); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!$isService && ($p['days_in_inventory'] ?? 0) > 0): ?>
                            <div style="font-size:11px;color:var(--color-text-secondary);">
                                📅 <?php echo $p['days_in_inventory']; ?> días en inventario
                            </div>
                        <?php endif; ?>
                        <div style="margin-top:10px;display:flex;gap:5px;flex-wrap:wrap;">
                            <?php if (!$isService && ($p['stock'] ?? 0) > 0): ?>
                                <button onclick="addToCart(<?php echo $p['id']; ?>,'<?php echo htmlspecialchars(addslashes($p['name'])); ?>',<?php echo $p['sale_price']; ?>, event)" class="btn btn-sm btn-info" title="Agregar al carrito"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg></button>
                            <?php elseif ($isService): ?>
                                <button onclick="addToCart(<?php echo $p['id']; ?>,'<?php echo htmlspecialchars(addslashes($p['name'])); ?>',<?php echo $p['sale_price']; ?>, event)" class="btn btn-sm btn-info" title="Agregar al carrito"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg></button>
                            <?php endif; ?>
                            <?php if ($canEditProduct || $canCreateProduct): ?>
                                <button onclick="viewDetail(<?php echo $p['id']; ?>)" class="btn btn-sm btn-info" title="Ver movimientos"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg></button>
                            <?php endif; ?>
                            <?php if ($canEditProduct): ?>
                                <button onclick="editProduct(<?php echo $p['id']; ?>)" class="btn btn-sm btn-secondary" title="Editar"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></button>
                                <?php
                                $isComodin = strtoupper((string)($p['code'] ?? '')) === \SoftNova\Services\OpenItemProduct::CODE;
                                if (!$isComodin):
                                ?>
                                    <?php if (!empty($p['offer_active'])): ?>
                                        <button type="button" onclick="openOfferModal(<?php echo (int)$p['id']; ?>)" class="btn btn-sm btn-warning" title="Editar oferta">Oferta</button>
                                        <button type="button" onclick="cancelOffer(<?php echo (int)$p['id']; ?>)" class="btn btn-sm btn-secondary" title="Cancelar oferta">Fin oferta</button>
                                    <?php else: ?>
                                        <button type="button" onclick="openOfferModal(<?php echo (int)$p['id']; ?>)" class="btn btn-sm btn-success" title="Activar oferta">Oferta</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!$isService): ?>
                                    <button onclick="addStock(<?php echo $p['id']; ?>)" class="btn btn-sm btn-success" title="Ingresar lote / stock">Lote N</button>
                                    <?php if ($canViewPrestamos): ?>
                                    <a href="<?php echo $viewInstance->route('app/prestamos'); ?>?product_id=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-secondary" title="Salida por préstamo">Préstamo</a>
                                    <?php elseif ($canCreatePrestamos && \SoftNova\Core\TenantMiddleware::canAccess('caja')): ?>
                                    <a href="<?php echo $viewInstance->route('app/caja'); ?>?loan_product_id=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-secondary" title="Registrar préstamo desde Caja">Préstamo</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($canDeleteProduct): ?>
                            <form method="POST" action="<?php echo $viewInstance->route('app/inventario'); ?>?action=delete" style="display:inline;" data-ajax="true">
                                <?php echo \SoftNova\Core\csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                <button type="submit" data-confirm="Eliminar este producto?" class="btn btn-sm btn-danger" title="Eliminar"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if (!empty($products)): ?>
<div class="inv-products-list card neumorphic">
    <div class="inv-list-toolbar">
        <span id="invListFilterCount" class="inv-list-count"><?php echo count($products); ?> filas</span>
        <button type="button" class="btn btn-sm btn-secondary" onclick="clearInvListFilters()">Limpiar filtros</button>
    </div>
    <div class="table-container">
        <table class="inv-list-table" id="invListTable">
            <thead>
                <tr>
                    <?php if ($canDeleteProduct): ?>
                    <th style="width:36px;" title="Seleccionar">
                        <input type="checkbox" id="invSelectAllList" class="inv-select-all-list" style="width:16px;height:16px;" aria-label="Seleccionar todos">
                    </th>
                    <?php endif; ?>
                    <th><?php $viewInstance->partial('sortable_th', ['label' => 'Código', 'column' => 'code', 'filters' => $filters, 'baseUrl' => $invBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label' => 'Nombre', 'column' => 'name', 'filters' => $filters, 'baseUrl' => $invBase]); ?></th>
                    <th>Tipo</th>
                    <th><?php $viewInstance->partial('sortable_th', ['label' => 'Categoría', 'column' => 'category', 'filters' => $filters, 'baseUrl' => $invBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label' => 'Stock', 'column' => 'stock', 'filters' => $filters, 'baseUrl' => $invBase]); ?></th>
                    <th>Vence</th>
                    <th><?php $viewInstance->partial('sortable_th', ['label' => 'P. Compra', 'column' => 'cost', 'filters' => $filters, 'baseUrl' => $invBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label' => 'P. Venta', 'column' => 'price', 'filters' => $filters, 'baseUrl' => $invBase]); ?></th>
                    <th>Acciones</th>
                </tr>
                <tr class="inv-list-filters">
                    <?php if ($canDeleteProduct): ?><th></th><?php endif; ?>
                    <th><input type="text" class="inv-col-filter form-control" data-col="code" placeholder="Filtrar…" autocomplete="off"></th>
                    <th><input type="text" class="inv-col-filter form-control" data-col="name" placeholder="Filtrar…" autocomplete="off"></th>
                    <th>
                        <select class="inv-col-filter form-control" data-col="type">
                            <option value="">Todos</option>
                            <option value="product">Producto</option>
                            <option value="service">Servicio</option>
                        </select>
                    </th>
                    <th><input type="text" class="inv-col-filter form-control" data-col="category" placeholder="Filtrar…" autocomplete="off"></th>
                    <th><input type="text" class="inv-col-filter form-control" data-col="stock" placeholder="Filtrar…" autocomplete="off"></th>
                    <th><input type="text" class="inv-col-filter form-control" data-col="expiry" placeholder="Filtrar…" autocomplete="off"></th>
                    <th><input type="text" class="inv-col-filter form-control" data-col="purchase" placeholder="Filtrar…" autocomplete="off"></th>
                    <th><input type="text" class="inv-col-filter form-control" data-col="sale" placeholder="Filtrar…" autocomplete="off"></th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="invListBody">
                <?php foreach ($products as $p): ?>
                    <?php
                    $isService = ($p['product_type'] ?? 'product') === 'service';
                    $typeKey = $isService ? 'service' : 'product';
                    $codeVal = \SoftNova\Core\utf8_fix((string)($p['code'] ?? ''));
                    $nameVal = \SoftNova\Core\utf8_fix((string)($p['name'] ?? ''));
                    $catVal = \SoftNova\Core\utf8_fix((string)($p['category_name'] ?? ''));
                    $stockVal = $isService ? '' : (string)(int)($p['stock'] ?? 0);
                    $purchaseVal = (string)(float)($p['purchase_price'] ?? 0);
                    $saleVal = (string)(float)($p['sale_price'] ?? 0);
                    $stockLow = !$isService && (int)($p['stock'] ?? 0) <= (int)($p['min_stock'] ?? 5);
                    $expiryVal = (string)($p['next_expiry_date'] ?? '');
                    $daysToExpiry = null;
                    if ($expiryVal !== '') {
                        $daysToExpiry = (int)floor((strtotime($expiryVal) - strtotime(date('Y-m-d'))) / 86400);
                    }
                    // Semáforo: ≥12 meses verde, ≥6 amarillo, <6 (incluye ≤3 y vencidos) rojo
                    $expiryLevel = '';
                    $expiryColor = '';
                    $expiryTitle = '';
                    if ($daysToExpiry !== null) {
                        if ($daysToExpiry >= 365) {
                            $expiryLevel = 'green';
                            $expiryColor = '#16A34A';
                            $expiryTitle = 'Vence en 12 meses o más';
                        } elseif ($daysToExpiry >= 180) {
                            $expiryLevel = 'yellow';
                            $expiryColor = '#D97706';
                            $expiryTitle = 'Vence en menos de 12 meses (alerta 6 meses)';
                        } else {
                            $expiryLevel = 'red';
                            $expiryColor = '#DC2626';
                            $expiryTitle = $daysToExpiry < 0
                                ? 'Producto vencido'
                                : ($daysToExpiry <= 90
                                    ? 'Vence en 3 meses o menos'
                                    : 'Vence en menos de 6 meses');
                        }
                    }
                    ?>
                    <tr class="inv-list-row"
                        data-id="<?php echo (int)$p['id']; ?>"
                        data-code="<?php echo htmlspecialchars(mb_strtolower($codeVal)); ?>"
                        data-name="<?php echo htmlspecialchars(mb_strtolower($nameVal)); ?>"
                        data-type="<?php echo $typeKey; ?>"
                        data-category="<?php echo htmlspecialchars(mb_strtolower($catVal)); ?>"
                        data-stock="<?php echo htmlspecialchars($stockVal); ?>"
                        data-expiry="<?php echo htmlspecialchars($expiryVal); ?>"
                        data-purchase="<?php echo htmlspecialchars($purchaseVal); ?>"
                        data-sale="<?php echo htmlspecialchars($saleVal); ?>">
                        <?php if ($canDeleteProduct): ?>
                        <td>
                            <input type="checkbox" class="inv-product-check" value="<?php echo (int)$p['id']; ?>" style="width:16px;height:16px;" aria-label="Seleccionar">
                        </td>
                        <?php endif; ?>
                        <td class="inv-td-code"><?php echo $codeVal !== '' ? \SoftNova\Core\e($codeVal) : '—'; ?></td>
                        <td class="inv-td-name"><?php echo \SoftNova\Core\e($nameVal); ?></td>
                        <td><?php echo $isService ? 'Servicio' : 'Producto'; ?></td>
                        <td><?php echo $catVal !== '' ? htmlspecialchars($catVal) : '—'; ?></td>
                        <td class="<?php echo $stockLow ? 'inv-stock-low' : ''; ?>">
                            <?php echo $isService ? '—' : (int)$p['stock']; ?>
                        </td>
                        <td class="inv-td-expiry"<?php echo $expiryColor !== '' ? ' style="color:' . $expiryColor . ';font-weight:600;"' : ''; ?>
                            <?php echo $expiryTitle !== '' ? ' title="' . htmlspecialchars($expiryTitle) . '"' : ''; ?>>
                            <?php if ($expiryVal !== ''): ?>
                                <span class="inv-expiry-light inv-expiry-<?php echo $expiryLevel; ?>" aria-hidden="true"></span>
                                <?php echo htmlspecialchars($expiryVal); ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?php echo fmtI((float)$p['purchase_price'], $currency); ?></td>
                        <td class="inv-td-price">
                            <?php if (!empty($p['offer_active'])): ?>
                                <strong style="color:#DC2626;"><?php echo fmtI((float)$p['sale_price'], $currency); ?></strong>
                                <br><small style="text-decoration:line-through;color:var(--color-text-secondary);"><?php echo fmtI((float)($p['offer_original_price'] ?? 0), $currency); ?></small>
                                <br><span class="badge badge-danger">Oferta</span>
                            <?php else: ?>
                                <?php echo fmtI((float)$p['sale_price'], $currency); ?>
                            <?php endif; ?>
                        </td>
                        <td class="table-actions">
                            <?php if ($isService || ($p['stock'] ?? 0) > 0): ?>
                                <button type="button" onclick="addToCart(<?php echo (int)$p['id']; ?>,'<?php echo htmlspecialchars(addslashes($p['name'])); ?>',<?php echo (float)$p['sale_price']; ?>, event)" class="btn btn-sm btn-info" title="Carrito">+</button>
                            <?php endif; ?>
                            <?php if ($canEditProduct || $canCreateProduct): ?>
                                <button type="button" onclick="viewDetail(<?php echo (int)$p['id']; ?>)" class="btn btn-sm btn-info" title="Detalle">i</button>
                            <?php endif; ?>
                            <?php if ($canEditProduct): ?>
                                <button type="button" onclick="editProduct(<?php echo (int)$p['id']; ?>)" class="btn btn-sm btn-secondary" title="Editar">Editar</button>
                                <?php
                                $isComodinList = strtoupper((string)($p['code'] ?? '')) === \SoftNova\Services\OpenItemProduct::CODE;
                                if (!$isComodinList):
                                ?>
                                    <?php if (!empty($p['offer_active'])): ?>
                                        <button type="button" onclick="openOfferModal(<?php echo (int)$p['id']; ?>)" class="btn btn-sm btn-warning" title="Editar oferta">Oferta</button>
                                        <button type="button" onclick="cancelOffer(<?php echo (int)$p['id']; ?>)" class="btn btn-sm btn-secondary" title="Cancelar oferta">Fin</button>
                                    <?php else: ?>
                                        <button type="button" onclick="openOfferModal(<?php echo (int)$p['id']; ?>)" class="btn btn-sm btn-success" title="Activar oferta">Oferta</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!$isService): ?>
                                    <button type="button" onclick="addStock(<?php echo (int)$p['id']; ?>)" class="btn btn-sm btn-success" title="Ingresar lote / stock">Lote N</button>
                                    <?php if ($canViewPrestamos): ?>
                                    <a href="<?php echo $viewInstance->route('app/prestamos'); ?>?product_id=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-secondary" title="Salida por préstamo">Préstamo</a>
                                    <?php elseif ($canCreatePrestamos && \SoftNova\Core\TenantMiddleware::canAccess('caja')): ?>
                                    <a href="<?php echo $viewInstance->route('app/caja'); ?>?loan_product_id=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-secondary" title="Registrar préstamo desde Caja">Préstamo</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($canDeleteProduct): ?>
                            <form method="POST" action="<?php echo $viewInstance->route('app/inventario'); ?>?action=delete" style="display:inline;" data-ajax="true">
                                <?php echo \SoftNova\Core\csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                <button type="submit" data-confirm="Eliminar este producto?" class="btn btn-sm btn-danger" title="Eliminar">X</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p id="invListEmpty" class="inv-list-empty" hidden>Ningún producto coincide con los filtros de columna.</p>
</div>
<?php endif; ?>

<?php
if (!empty($products)) {
    $pagination = $pagination ?? null;
    $paginationBaseUrl = $viewInstance->route('app/inventario');
    $paginationQuery = $filters['query'] ?? [];
    echo '<div class="inv-catalog-pagination">';
    $viewInstance->partial('pagination', compact('pagination', 'paginationBaseUrl', 'paginationQuery'));
    echo '</div>';
}
?>
</div>

<!-- Modal Producto -->
<div id="productModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:600px;max-height:85vh;overflow-y:auto;">
        <div class="modal-header">
            <h3 id="modalTitle">Nuevo Producto</h3>
            <button onclick="closeInvModal()" class="modal-close">&times;</button>
        </div>
        <form method="POST" id="productForm" enctype="multipart/form-data"
              action="<?php echo $viewInstance->route('app/inventario'); ?>?action=create" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="prodId">
            <div class="alert alert-info" style="margin:0 0 12px;font-size:13px;">
                <strong>SKU y EAN son únicos por producto.</strong>
                Si llega la misma referencia con otro vencimiento o lote, no cree un ítem nuevo:
                use <strong>Lote N</strong> en el producto existente e indique la fecha de esa remesa.
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                <div class="form-group">
                    <label>Tipo * <span class="field-tip" data-tip="Producto consume stock; Servicio no maneja inventario físico.">?</span></label>
                    <select name="product_type" id="prodType" class="form-control" onchange="onTypeChange()">
                        <option value="product">📦 Producto</option>
                        <option value="service">🔧 Servicio</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Código / SKU <span class="field-tip" data-tip="Fórmula de ingreso o creación de SKU= [Categoría]-[Marca / Línea]-[Producto / Subtipo][Atributo / Tamaño].">?</span></label>
                    <div style="display:flex;gap:8px;align-items:stretch;">
                        <input type="text" name="code" id="prodCode" class="form-control" placeholder="SKU interno o Generar" maxlength="255" autocomplete="off" inputmode="text" style="flex:1;">
                        <button type="button" class="btn btn-secondary" style="white-space:nowrap;" onclick="generateProductSku()" title="Siguiente SKU de la secuencia">Generar SKU</button>
                    </div>
                    <small style="color:var(--color-text-secondary);display:block;margin-top:4px;">
                        Manual (según la fórmula del ?) o automático. Configure prefijo/consecutivo en Configuración → Preferencias.
                    </small>
                </div>
                <div class="form-group">
                    <label>Código de barras (EAN) <span class="field-tip" data-tip="Código EAN/UPC de la pistola. Puede escribirlo a mano o generar el siguiente de la secuencia configurada.">?</span></label>
                    <div style="display:flex;gap:8px;align-items:stretch;">
                        <input type="text" name="barcode" id="prodBarcode" class="form-control" placeholder="EAN / UPC o Generar" maxlength="64" autocomplete="off" inputmode="text" style="flex:1;">
                        <button type="button" class="btn btn-secondary" style="white-space:nowrap;" onclick="generateProductEan()" title="Siguiente EAN de la secuencia">Generar EAN</button>
                    </div>
                    <small style="color:var(--color-text-secondary);display:block;margin-top:4px;">
                        Manual o automático. Configure prefijo/consecutivo en Configuración → Preferencias.
                    </small>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label>Nombre * <span class="field-tip" data-tip="Nombre visible en ventas, cotizaciones e inventario.">?</span></label>
                    <input type="text" name="name" id="prodName" required class="form-control">
                </div>
                <div class="form-group">
                    <label>Categoría <span class="field-tip" data-tip="Agrupación del producto para filtros y reportes.">?</span></label>
                    <select name="category_id" id="prodCat" class="form-control">
                        <option value="">Sin categoría</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Ubicación <span class="field-tip" data-tip="Sitio físico del producto (bodega, almacén, local). Otro agrega una ubicación permanente.">?</span></label>
                    <select name="location" id="prodLocation" class="form-control" data-custom-other="product_location">
                        <option value="">Sin ubicación</option>
                        <?php foreach ($locationOptions as $loc): ?>
                            <option value="<?php echo htmlspecialchars((string)$loc['value']); ?>"><?php echo htmlspecialchars((string)$loc['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Unidad <span class="field-tip" data-tip="Unidad de medida del producto (UND, Lts, Cm, Mt, etc.).">?</span></label>
                    <select name="unit" id="prodUnit" class="form-control">
                        <?php foreach ($unitOptions as $unitVal => $unitLabel): ?>
                            <option value="<?php echo htmlspecialchars((string)$unitVal); ?>"<?php echo $unitVal === 'UND' ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)$unitLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Precio Compra <span class="field-tip" data-tip="Costo de adquisición. Sirve para margen y reportes.">?</span></label>
                    <input type="text" inputmode="decimal" name="purchase_price" id="prodPcompra" class="form-control money-input" data-money="1" placeholder="0">
                </div>
                <div class="form-group">
                    <label>Precio Venta * <span class="field-tip" data-tip="Precio al público que se usará en ventas.">?</span></label>
                    <input type="text" inputmode="decimal" name="sale_price" id="prodPventa" required class="form-control money-input" data-money="1" placeholder="0">
                </div>
                <div class="form-group">
                    <label>Precio al por mayor <span class="field-tip" data-tip="Precio para ventas al por mayor en Caja.">?</span></label>
                    <input type="text" inputmode="decimal" name="wholesale_price" id="prodPmayoreo" class="form-control money-input" data-money="1" placeholder="0">
                </div>
                <div class="form-group" id="stockGroup">
                    <label>Stock Inicial <span class="field-tip" data-tip="Cantidad disponible al crear el producto.">?</span></label>
                    <input type="number" name="stock" id="prodStock" class="form-control" placeholder="0">
                </div>
                <div class="form-group" id="minStockGroup">
                    <label>Stock Mínimo <span class="field-tip" data-tip="Umbral de alerta cuando el inventario esté bajo.">?</span></label>
                    <input type="number" name="min_stock" id="prodMinStock" class="form-control" placeholder="5">
                </div>
                <div class="form-group" id="maxStockGroup">
                    <label>Stock Máximo <span class="field-tip" data-tip="Nivel objetivo para las órdenes de reposición. Si se deja vacío se sugiere el doble del stock mínimo.">?</span></label>
                    <input type="number" name="max_stock" id="prodMaxStock" class="form-control" placeholder="Opcional">
                </div>
                <div class="form-group" id="trackLotsGroup" style="grid-column:1/-1;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" name="track_lots" id="prodTrackLots" value="1" onchange="onTrackLotsChange()" style="width:auto;">
                        Controlar por lotes y fecha de vencimiento
                        <span class="field-tip" data-tip="Ideal para perfumes, lociones u otros con caducidad. Cada remesa con distinta fecha es un lote; la caja vende primero lo que vence antes (FEFO).">?</span>
                    </label>
                    <p style="margin:6px 0 0;font-size:11px;color:var(--color-text-secondary);">
                        Al activarlo con stock existente se crea un <strong>lote de apertura</strong>. En cada entrada de mercancía indique la fecha de vencimiento de esa remesa.
                    </p>
                </div>
                <div class="form-group lot-field" id="alertDaysGroup" style="display:none;">
                    <label>Días de alerta previa <span class="field-tip" data-tip="Cuántos días antes del vencimiento debe aparecer el lote en el panel de alertas.">?</span></label>
                    <input type="number" name="expiry_alert_days" id="prodAlertDays" class="form-control" min="0" max="3650" placeholder="30">
                </div>
                <div class="form-group lot-field" id="expiryGroup" style="display:none;">
                    <label>Vence (stock inicial) <span class="field-tip" data-tip="Fecha de vencimiento del lote del stock inicial. Se puede dejar vacía y registrarla en la entrada de mercancía.">?</span></label>
                    <input type="date" name="expiry_date" id="prodExpiry" class="form-control">
                </div>
                <div class="form-group lot-field" id="lotCodeGroup" style="display:none;">
                    <label>Código de lote <span class="field-tip" data-tip="Identificador del lote del proveedor. Si se deja vacío, el sistema lo genera.">?</span></label>
                    <input type="text" name="lot_code" id="prodLotCode" class="form-control" maxlength="60" placeholder="Automático">
                </div>
                <div class="form-group" id="statusGroup">
                    <label>Estado <span class="field-tip" data-tip="Activo: disponible para vender. Inactivo: oculto de nuevas ventas.">?</span></label>
                    <select name="status" id="prodStatus" class="form-control">
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>
            </div>
            <div class="form-group" id="supplierSection" style="margin-top:8px;padding:12px;border:1px solid var(--color-border);border-radius:10px;background:var(--bg-input);">
                <label style="font-weight:600;margin-bottom:8px;display:block;">Proveedor <span class="field-tip" data-tip="Proveedor principal del producto. Puede gestionar más proveedores desde el detalle.">?</span></label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group" style="margin:0;">
                        <label style="font-size:12px;">Proveedor</label>
                        <select name="supplier_id" id="prodSupplier" class="form-control">
                            <option value="">Sin proveedor</option>
                            <?php foreach ($suppliersCatalog ?? [] as $sup): ?>
                                <option value="<?php echo (int)$sup['id']; ?>"><?php echo htmlspecialchars((string)$sup['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label style="font-size:12px;">SKU del proveedor</label>
                        <input type="text" name="supplier_sku" id="prodSupplierSku" class="form-control" maxlength="120" placeholder="Código del proveedor">
                    </div>
                </div>
                <p style="margin:8px 0 0;font-size:11px;color:var(--color-text-secondary);">Se marcará como proveedor principal para reposición. Gestione vínculos adicionales desde el detalle del producto.</p>
            </div>
            <div class="form-group">
                <label>Descripción <span class="field-tip" data-tip="Detalle comercial del producto o servicio.">?</span></label>
                <textarea name="description" id="prodDesc" rows="2" class="form-control"></textarea>
            </div>
            <div class="form-group">
                <label>Notas internas <span class="field-tip" data-tip="Anotaciones operativas editables en cualquier momento (ubicación especial, acuerdos, recordatorios).">?</span></label>
                <textarea name="notes" id="prodNotes" rows="2" class="form-control" placeholder="Ej: Se vende a veces a crédito, revisar con bodega…"></textarea>
            </div>
            <div class="form-group">
                <label>Imagen <span class="field-tip" data-tip="Foto opcional para identificar el producto en el catálogo.">?</span></label>
                <input type="file" name="image" class="form-control" accept="image/*" style="padding:8px;">
            </div>
            <div class="modal-footer" style="margin-top:15px;">
                <button type="button" class="btn btn-secondary" onclick="closeInvModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="submitBtn">Crear</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Stock -->
<div id="stockModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:400px;">
        <div class="modal-header">
            <h3>Lote N / ingreso de stock</h3>
            <button onclick="document.getElementById('stockModal').style.display='none'" class="modal-close">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('app/inventario'); ?>?action=add_stock" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="stockProdId">
            <p id="stockProdName" style="margin-bottom:10px;font-weight:600;"></p>
            <div id="stockDebtAlert" style="display:none;margin-bottom:12px;padding:10px 12px;border-radius:8px;background:#FEF3C7;border:1px solid #F59E0B;color:#92400E;font-size:13px;"></div>
            <p style="font-size:12px;color:var(--color-text-secondary);margin-bottom:12px;">
                Esto es un <strong>ajuste manual</strong> (no crea compra ni CxP).
                Para mercancía de proveedor use
                <a href="<?php echo $viewInstance->route('app/compras'); ?>">Compras</a>.
            </p>
            <div class="form-group">
                <label>Cantidad a agregar *</label>
                <input type="number" name="quantity" class="form-control" min="1" required placeholder="1">
            </div>
            <div class="form-group">
                <label>Fecha de ingreso *</label>
                <input type="date" name="movement_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label id="stockExpiryLabel">Vence <span class="field-tip" data-tip="Obligatorio si el producto controla lotes. Cada remesa con distinta fecha crea un lote aparte.">?</span></label>
                    <input type="date" name="expiry_date" id="stockExpiryDate" class="form-control">
                </div>
                <div class="form-group">
                    <label>Código de lote</label>
                    <input type="text" name="lot_code" id="stockLotCode" class="form-control" maxlength="60" placeholder="Automático si vacío">
                </div>
            </div>
            <p id="stockLotHint" style="display:none;font-size:12px;color:#92400E;background:#FEF3C7;border:1px solid #F59E0B;border-radius:8px;padding:8px 10px;margin:0 0 12px;">
                Este producto controla lotes: indique la <strong>fecha de vencimiento</strong> de esta remesa. Si es distinta a lotes previos, se crea un lote nuevo.
            </p>
            <div class="form-group">
                <label>Notas</label>
                <input type="text" name="notes" class="form-control" placeholder="Ej: Conteo físico / corrección">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('stockModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-success">Registrar Lote N</button>
            </div>
        </form>
    </div>
</div>

<?php if ($canEditProduct): ?>
<!-- Modal Salidas (pérdida operativa) -->
<div id="stockExitModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:480px;">
        <div class="modal-header">
            <h3>Salida de inventario</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('stockExitModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('app/inventario'); ?>?action=stock_exit" data-ajax="true" id="stockExitForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body">
                <p style="font-size:13px;color:var(--color-text-secondary);margin-top:0;">
                    Descuenta stock, genera <strong>gasto operativo del día</strong> (módulo Gastos)
                    y asiento de pérdida (519595). También queda en Trazabilidad.
                    No es préstamo ni venta.
                </p>
                <div class="form-group">
                    <label>Producto *</label>
                    <input type="hidden" name="product_id" id="exitProductId" required>
                    <input type="text" id="exitProductSearch" class="form-control" autocomplete="off"
                           placeholder="Buscar nombre, SKU o escanear…"
                           data-barcode-input="true">
                    <div id="exitProductResults" class="product-picker-results" style="display:none;"></div>
                    <div id="exitProductSelected" class="product-picker-selected" style="display:none;"></div>
                </div>
                <div class="form-group">
                    <label>Motivo *</label>
                    <select name="reason" id="exitReason" class="form-control" required>
                        <?php foreach (\SoftNova\Services\StockService::inventoryExitReasons() as $code => $label): ?>
                            <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Cantidad *</label>
                    <input type="number" name="quantity" id="exitQty" class="form-control" min="1" value="1" required>
                </div>
                <div class="form-group">
                    <label>Fecha *</label>
                    <input type="date" name="movement_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label>Nota / detalle</label>
                    <input type="text" name="notes" class="form-control" maxlength="180" placeholder="Opcional">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('stockExitModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-danger">Registrar salida</button>
            </div>
        </form>
    </div>
</div>
<style>
.product-picker-results {
    margin-top: 6px; border: 1px solid var(--color-border); border-radius: 8px;
    max-height: 220px; overflow: auto; background: var(--bg-card, #fff);
}
.product-picker-row {
    display: flex; justify-content: space-between; gap: 10px;
    padding: 8px 10px; cursor: pointer; border-bottom: 1px solid var(--color-border); font-size: 13px;
}
.product-picker-row:last-child { border-bottom: 0; }
.product-picker-row:hover { background: var(--bg-input, #f8fafc); }
.product-picker-selected {
    margin-top: 8px; padding: 8px 10px; border-radius: 8px;
    background: var(--bg-input, #f1f5f9); font-size: 13px;
}
</style>
<script>
(function () {
    var searchUrl = <?php echo json_encode($viewInstance->route('app/inventario') . '?action=searchProducts'); ?>;
    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function label(p) { return ((p.code ? p.code + ' · ' : '') + (p.name || '')).trim(); }
    window.openStockExitModal = function () {
        clearExitProduct();
        document.getElementById('stockExitModal').style.display = 'flex';
        var s = document.getElementById('exitProductSearch');
        if (s) setTimeout(function () { s.focus(); }, 40);
    };
    window.setExitProduct = function (p) {
        var hid = document.getElementById('exitProductId');
        var sel = document.getElementById('exitProductSelected');
        var search = document.getElementById('exitProductSearch');
        var results = document.getElementById('exitProductResults');
        if (!hid || !p) return;
        hid.value = String(p.id);
        if (sel) {
            sel.style.display = '';
            sel.innerHTML = '<strong>' + esc(label(p)) + '</strong> · stock ' + esc(p.stock)
                + ' <button type="button" class="btn btn-sm btn-secondary" style="margin-left:8px;" onclick="clearExitProduct()">Cambiar</button>';
        }
        if (search) { search.value = ''; search.style.display = 'none'; }
        if (results) { results.style.display = 'none'; results.innerHTML = ''; }
    };
    window.clearExitProduct = function () {
        var hid = document.getElementById('exitProductId');
        var sel = document.getElementById('exitProductSelected');
        var search = document.getElementById('exitProductSearch');
        if (hid) hid.value = '';
        if (sel) { sel.style.display = 'none'; sel.innerHTML = ''; }
        if (search) { search.style.display = ''; search.focus(); }
    };
    var timer = null;
    var input = document.getElementById('exitProductSearch');
    function render(list) {
        var box = document.getElementById('exitProductResults');
        if (!box) return;
        if (!list || !list.length) {
            box.style.display = '';
            box.innerHTML = '<div class="product-picker-row" style="cursor:default;color:var(--color-text-secondary);">Sin resultados</div>';
            return;
        }
        box.style.display = '';
        box.innerHTML = '';
        list.forEach(function (p) {
            var row = document.createElement('div');
            row.className = 'product-picker-row';
            row.innerHTML = '<span>' + esc(label(p)) + '</span><span style="color:var(--color-text-secondary);">stock ' + esc(p.stock) + '</span>';
            row.addEventListener('click', function () { setExitProduct(p); });
            box.appendChild(row);
        });
    }
    function run(autoExact) {
        var q = (input && input.value || '').trim();
        if (!q) { var box = document.getElementById('exitProductResults'); if (box) { box.style.display='none'; box.innerHTML=''; } return; }
        fetch(searchUrl + '&q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var list = (d && d.products) ? d.products : [];
                if (autoExact && list.length) {
                    var ql = q.toLowerCase();
                    var exact = list.find(function (p) {
                        return String(p.code || '').toLowerCase() === ql || String(p.barcode || '').toLowerCase() === ql;
                    }) || (list.length === 1 ? list[0] : null);
                    if (exact) { setExitProduct(exact); return; }
                }
                render(list);
            });
    }
    if (input) {
        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { run(false); }, 220); });
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); run(true); } });
    }
    var form = document.getElementById('stockExitForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            var hid = document.getElementById('exitProductId');
            if (!hid || !hid.value) {
                e.preventDefault(); e.stopImmediatePropagation();
                if (typeof showAlert === 'function') showAlert('Seleccione o escanee un producto', 'error');
            }
        }, true);
    }
    if (window.SoftNovaBarcode && SoftNovaBarcode.onScan) {
        SoftNovaBarcode.onScan(function (code, product) {
            var modal = document.getElementById('stockExitModal');
            if (!modal || modal.style.display === 'none' || !product) return false;
            setExitProduct(product);
            return true;
        });
    }
})();
</script>
<?php endif; ?>

<?php if ($canEditProduct): ?>
<!-- Modal Proveedor del producto -->
<div id="productSupplierModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:480px;">
        <div class="modal-header">
            <h3>Vincular proveedor</h3>
            <button type="button" class="modal-close" onclick="closeSupplierLink()">&times;</button>
        </div>
        <form method="POST" id="psForm" action="<?php echo $viewInstance->route('app/inventario'); ?>?action=supplier_link" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="product_id" id="psProductId">
            <p style="margin-top:0;font-weight:600;" id="psProductName"></p>
            <div class="form-group">
                <label>Proveedor *</label>
                <select name="supplier_id" class="form-control" required>
                    <option value="">Seleccione…</option>
                    <?php foreach ($suppliersCatalog ?? [] as $sup): ?>
                        <option value="<?php echo (int)$sup['id']; ?>"><?php echo htmlspecialchars($sup['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label>Código del proveedor <span class="field-tip" data-tip="SKU con el que el proveedor factura este producto. Permite reconocerlo al importar facturas.">?</span></label>
                    <input type="text" name="supplier_sku" class="form-control" maxlength="120">
                </div>
                <div class="form-group">
                    <label>Último costo</label>
                    <input type="text" inputmode="decimal" name="last_cost" class="form-control money-input" data-money="1" placeholder="0">
                </div>
                <div class="form-group">
                    <label>Días de entrega</label>
                    <input type="number" min="0" max="365" name="lead_time_days" class="form-control" placeholder="0">
                </div>
                <div class="form-group">
                    <label>Mínimo de pedido</label>
                    <input type="number" min="1" name="min_order_qty" class="form-control" placeholder="1">
                </div>
            </div>
            <div class="form-group">
                <label>Nombre en la factura del proveedor</label>
                <input type="text" name="supplier_product_name" class="form-control" maxlength="255" placeholder="Opcional">
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="is_primary" value="1" style="width:auto;">
                    Proveedor principal para reposición
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeSupplierLink()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Vincular</button>
            </div>
        </form>
        <form method="POST" id="psUnlinkForm" action="<?php echo $viewInstance->route('app/inventario'); ?>?action=supplier_unlink" style="display:none;">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="product_id">
            <input type="hidden" name="supplier_id">
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal Trazabilidad -->
<div id="detailModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:700px;max-height:85vh;overflow-y:auto;">
        <div class="modal-header">
            <h3>Trazabilidad del Producto</h3>
            <button onclick="document.getElementById('detailModal').style.display='none'" class="modal-close">&times;</button>
        </div>
        <div class="modal-body" id="detailContent"></div>
    </div>
</div>

<!-- Modal Carrito -->
<div id="cartModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:500px;">
        <div class="modal-header">
            <h3>🛒 Carrito de Compras</h3>
            <button onclick="closeCartModal()" class="modal-close">&times;</button>
        </div>
        <div id="cartContent"></div>
    </div>
</div>

<!-- Carrito Flotante -->
<div style="position:fixed;bottom:25px;right:25px;z-index:998;">
    <button onclick="showCartModal()"
            style="width:60px;height:60px;border-radius:50%;background:var(--bg-btn-primary);color:#fff;border:none;font-size:24px;cursor:pointer;box-shadow:0 8px 24px rgba(0,0,0,0.25);transition:transform 0.2s;"
            onmouseover="this.style.transform='scale(1.1)'"
            onmouseout="this.style.transform='scale(1)'">🛒</button>
    <span id="cartBadge"
          style="position:absolute;top:-6px;right:-6px;background:#DC2626;color:#fff;min-width:22px;height:22px;padding:0 5px;border-radius:11px;font-size:11px;display:none;align-items:center;justify-content:center;font-weight:700;z-index:999;pointer-events:none;line-height:22px;">0</span>
</div>

<!-- Modal Importar CSV -->
<div id="importCsvModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:520px;">
        <div class="modal-header">
            <h3>Importar CSV</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('importCsvModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $invBase; ?>?action=import_csv" enctype="multipart/form-data" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body">
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:14px;">
                    <a href="<?php echo $invBase; ?>?action=import_template" class="btn btn-primary" download>
                        Descargar plantilla CSV
                    </a>
                    <span style="font-size:12px;color:var(--color-text-secondary);">UTF-8 · separador <code>;</code> · con ejemplos</span>
                </div>
                <div class="alert alert-info" style="font-size:13px;margin-bottom:14px;">
                    <strong>Cómo usarla</strong>
                    <ol style="margin:8px 0 0 18px;padding:0;">
                        <li>Descargue la <strong>plantilla</strong> (botón de arriba).</li>
                        <li>Ábrala en Excel o Google Sheets. No borre la fila de encabezados.</li>
                        <li>Reemplace las filas de ejemplo por sus productos. Columna <strong>Nombre</strong> es obligatoria.</li>
                        <li>Columnas SKU y EAN: formato <strong>Texto</strong> (evita que Excel corte códigos largos).</li>
                        <li>Guarde como CSV y súbalo aquí. En Excel: <em>CSV UTF-8</em> o CSV delimitado por <code>;</code>.</li>
                    </ol>
                    <ul style="margin:10px 0 0 18px;padding:0;">
                        <li>Columnas: <code>SKU; EAN; Nombre; Tipo; Categoria; Costo; Precio; Precio_mayor; Stock; Min; Unidad; Estado; Ubicacion; Lote; Vence; Modo_stock</code></li>
                        <li><code>Precio_mayor</code>: precio al por mayor (opcional). <code>Unidad</code>: UND, Lts, Mt, etc.</li>
                        <li><strong>Misma SKU + distinto Lote/Vence</strong> = remesa del mismo producto (no cree otro ítem).</li>
                        <li>Si hay <code>Lote</code> o <code>Vence</code>, <code>Stock</code> es la cantidad de <em>esa remesa</em> (se suma, como una compra).</li>
                        <li>Sin lote/vence, <code>Stock</code> sincroniza la existencia total (<code>Modo_stock=absoluto</code>).</li>
                        <li><code>Modo_stock</code>: <code>entrada</code> (suma remesa) o <code>absoluto</code> (fijar total). Vacío: entrada si hay lote/vence; si no, absoluto.</li>
                        <li>Tipo: <code>product</code> o <code>service</code>. Estado: <code>active</code> o <code>inactive</code>.</li>
                        <li>Cada cambio de stock deja movimiento en Trazabilidad (<code>csv_import</code>) y, si aplica, traza de lote.</li>
                        <li>Máximo 5MB. Archivos &gt;200KB van a cola (Config → Procesar jobs).</li>
                    </ul>
                </div>
                <div class="form-group">
                    <label>Archivo CSV *</label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv,text/csv" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('importCsvModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Importar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Categorías -->
<div id="offerModal" class="modal-overlay" style="display:none;z-index:1200;">
    <div class="modal-content neumorphic" style="max-width:520px;">
        <div class="modal-header">
            <h3>Activar oferta</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('offerModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $invBase; ?>?action=offer_activate" data-ajax="true" id="offerForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="product_id" id="offerProductId" value="">
            <div class="modal-body">
                <p id="offerProductLabel" style="margin:0 0 12px;font-size:13px;color:var(--color-text-secondary);"></p>
                <div class="form-group">
                    <label>Precio original</label>
                    <input type="text" id="offerOriginalDisplay" class="form-control" readonly>
                    <input type="hidden" id="offerOriginalValue" value="0">
                </div>
                <div class="form-group">
                    <label>Descuento predeterminado</label>
                    <div id="offerPercentBtns" style="display:flex;flex-wrap:wrap;gap:6px;">
                        <?php foreach ($offerPercents as $pct): ?>
                            <button type="button" class="btn btn-sm btn-secondary offer-pct-btn" data-pct="<?php echo (int)$pct; ?>"><?php echo (int)$pct; ?>%</button>
                        <?php endforeach; ?>
                        <button type="button" class="btn btn-sm btn-secondary offer-pct-btn" data-pct="0">Manual</button>
                    </div>
                    <input type="hidden" name="percent" id="offerPercent" value="">
                </div>
                <div class="form-group">
                    <label>Precio de oferta (manual) *</label>
                    <input type="text" inputmode="decimal" name="offer_price" id="offerPrice" class="form-control money-input" data-money="1" required>
                    <small id="offerPreview" style="color:var(--color-text-secondary);"></small>
                </div>
                <div class="form-group">
                    <label>Inicio de la oferta</label>
                    <input type="datetime-local" name="starts_at" id="offerStartsAt" class="form-control">
                    <small style="color:var(--color-text-secondary);">Vacío = inicia ahora. Si elige una fecha futura, la oferta queda programada y el precio no cambia hasta esa hora.</small>
                </div>
                <div class="form-group">
                    <label>Duración *</label>
                    <select name="duration" id="offerDuration" class="form-control" required>
                        <?php foreach ($offerDurations as $code => $label): ?>
                            <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $code === '24h' ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:var(--color-text-secondary);">La duración se cuenta desde el inicio (ahora o la fecha programada).</small>
                </div>
                <div class="form-group" id="offerEndsWrap" style="display:none;">
                    <label>Fin de la oferta *</label>
                    <input type="datetime-local" name="ends_at" id="offerEndsAt" class="form-control">
                </div>
                <div class="form-group">
                    <label>Motivo de oferta *</label>
                    <select name="reason" id="offerReason" class="form-control" required>
                        <option value="">Seleccione…</option>
                        <?php foreach ($offerReasons as $code => $label): ?>
                            <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="offerReasonNoteWrap" style="display:none;">
                    <label>Detalle del motivo *</label>
                    <input type="text" name="reason_note" id="offerReasonNote" class="form-control" maxlength="180" placeholder="Describa el motivo…">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('offerModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-success" id="offerSubmitBtn">Activar / programar oferta</button>
            </div>
        </form>
    </div>
</div>

<div id="categoriesModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:520px;max-height:85vh;overflow-y:auto;">
        <div class="modal-header">
            <h3>Categorías de inventario</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('categoriesModal').style.display='none'">&times;</button>
        </div>
        <div class="modal-body">
            <?php if ($canCreateProduct): ?>
            <form method="POST" action="<?php echo $invBase; ?>?action=create_category" data-ajax="true" style="margin-bottom:16px;">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <div class="form-group">
                    <label>Nueva categoría *</label>
                    <input type="text" name="name" class="form-control" required maxlength="100" placeholder="Ej: Bebidas, Aseo, Repuestos">
                </div>
                <div class="form-group">
                    <label>Descripción</label>
                    <input type="text" name="description" class="form-control" maxlength="255" placeholder="Opcional">
                </div>
                <button type="submit" class="btn btn-primary">Agregar categoría</button>
            </form>
            <?php endif; ?>
            <?php if (empty($categories)): ?>
                <p style="color:var(--color-text-secondary);margin:0;">No hay categorías activas.</p>
            <?php else: ?>
                <div class="table-container"><table>
                    <thead><tr><th>Nombre</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($cat['name']); ?></td>
                            <td style="text-align:right;">
                                <?php if ($canDeleteProduct): ?>
                                <form method="POST" action="<?php echo $invBase; ?>?action=delete_category" data-ajax="true" style="display:inline;">
                                    <?php echo \SoftNova\Core\csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" data-confirm="¿Eliminar la categoría «<?php echo htmlspecialchars($cat['name'], ENT_QUOTES); ?>»? Los productos quedarán sin categoría.">Eliminar</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    window.invRouteCreate = '<?php echo $viewInstance->route('app/inventario'); ?>?action=create';
    window.invRouteEdit = '<?php echo $viewInstance->route('app/inventario'); ?>?action=edit';
    window.invRouteDetail = '<?php echo $viewInstance->route('app/inventario'); ?>?action=detail';
    window.invRouteBase = '<?php echo $viewInstance->route('app/inventario'); ?>';
    window.invRouteGenerateBarcode = '<?php echo $viewInstance->route('app/inventario'); ?>?action=generate_barcode';
    window.invRouteGenerateSku = '<?php echo $viewInstance->route('app/inventario'); ?>?action=generate_sku';
    window.invRouteOfferCancel = '<?php echo $viewInstance->route('app/inventario'); ?>?action=offer_cancel';
    window.invRouteVentas = '<?php echo $viewInstance->route('app/ventas'); ?>';
    window.invCanEdit = <?php echo $canEditProduct ? 'true' : 'false'; ?>;
    window.invCsrf = <?php echo json_encode(\SoftNova\Core\csrf_token()); ?>;
    window.invCurrency = <?php echo json_encode([
        'symbol' => $currency['symbol'] ?? '$',
        'decimals' => (int)($currency['decimals'] ?? 0),
        'decimal' => $currency['decimal'] ?? ',',
        'thousands' => $currency['thousands'] ?? '.',
    ]); ?>;
</script>
