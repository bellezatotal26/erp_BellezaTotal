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
$canDeleteProduct = \SoftNova\Core\TenantMiddleware::canDo('delete', 'inventario');
$canExportInv = \SoftNova\Core\TenantMiddleware::canDo('export', 'inventario');
$expirySummary = $expirySummary ?? [];
$expiryAlerts = (int)($expirySummary['expired_lots'] ?? 0) + (int)($expirySummary['expiring_lots'] ?? 0);

function fmtI(float $a, array $c): string
{
    return $c['symbol'] . ' ' . number_format($a, $c['decimals'], $c['decimal'] ?? ',', $c['thousands'] ?? '.');
}
?>
<meta name="currency-symbol" content="<?php echo $currency['symbol']; ?>">
<meta name="currency-decimals" content="<?php echo $currency['decimals']; ?>">
<?php echo flashMessage(); ?>

<div class="stats-grid">
    <div class="stat-card neumorphic"><h4>Productos</h4><div class="stat-value"><?php echo $totalProducts; ?></div></div>
    <div class="stat-card neumorphic"><h4>Servicios</h4><div class="stat-value" style="color:#3B82F6;"><?php echo $totalServices; ?></div></div>
    <div class="stat-card neumorphic"><h4>Stock Bajo</h4><div class="stat-value" style="color:<?php echo $lowStock > 0 ? '#DC2626' : '#10B981'; ?>;"><?php echo $lowStock; ?></div></div>
    <div class="stat-card neumorphic"><h4>Total Ítems</h4><div class="stat-value"><?php echo $totalProducts + $totalServices; ?></div></div>
    <a href="<?php echo $viewInstance->route('app/inventario'); ?>?action=vencimientos" class="stat-card neumorphic" style="text-decoration:none;color:inherit;">
        <h4>Por vencer / vencidos</h4>
        <div class="stat-value" style="color:<?php echo $expiryAlerts > 0 ? '#DC2626' : '#10B981'; ?>;"><?php echo $expiryAlerts; ?></div>
        <p style="font-size:12px;color:var(--color-text-secondary);margin:4px 0 0;">Ver control de vencimientos</p>
    </a>
</div>

<?php
$invBase = $viewInstance->route('app/inventario');
$exportQuery = http_build_query(array_merge(['action' => 'export'], $filters['query'] ?? []));
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
            <?php endif; ?>
            <?php if ($canCreateProduct): ?>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('importCsvModal').style.display='flex'">Importar CSV</button>
            <?php endif; ?>
            <a href="<?php echo $viewInstance->route('app/compras'); ?>?action=reposicion" class="btn btn-secondary">Reposición</a>
            <a href="<?php echo $viewInstance->route('app/compras'); ?>" class="btn btn-secondary">Compras</a>
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
                 style="border-left:5px solid <?php echo $isService ? '#3B82F6' : 'var(--color-primary)'; ?>;padding:20px;"
                 data-id="<?php echo $p['id']; ?>"
                 data-name="<?php echo htmlspecialchars($p['name']); ?>"
                 data-code="<?php echo htmlspecialchars($p['code'] ?? ''); ?>"
                 data-barcode="<?php echo htmlspecialchars($p['barcode'] ?? '', ENT_QUOTES); ?>"
                 data-cat="<?php echo $p['category_id'] ?? ''; ?>"
                 data-location="<?php echo htmlspecialchars($p['location'] ?? '', ENT_QUOTES); ?>"
                 data-unit="<?php echo htmlspecialchars($p['unit'] ?? 'UNIDAD'); ?>"
                 data-pcompra="<?php echo $p['purchase_price'] ?? 0; ?>"
                 data-pventa="<?php echo $p['sale_price'] ?? 0; ?>"
                 data-stock="<?php echo $p['stock'] ?? 0; ?>"
                 data-minstock="<?php echo $p['min_stock'] ?? 5; ?>"
                 data-maxstock="<?php echo $p['max_stock'] ?? ''; ?>"
                 data-tracklots="<?php echo !empty($p['track_lots']) ? '1' : '0'; ?>"
                 data-alertdays="<?php echo $p['expiry_alert_days'] ?? 30; ?>"
                 data-status="<?php echo $p['status'] ?? 'active'; ?>"
                 data-type="<?php echo $p['product_type'] ?? 'product'; ?>">
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
                            <strong style="color:#10B981;"><?php echo fmtI($p['sale_price'], $currency); ?></strong>
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
                                <?php if (!$isService): ?>
                                    <button onclick="addStock(<?php echo $p['id']; ?>)" class="btn btn-sm btn-success" title="Agregar stock"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg></button>
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
                    $codeVal = (string)($p['code'] ?? '');
                    $nameVal = (string)($p['name'] ?? '');
                    $catVal = (string)($p['category_name'] ?? '');
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
                        data-code="<?php echo htmlspecialchars(mb_strtolower($codeVal)); ?>"
                        data-name="<?php echo htmlspecialchars(mb_strtolower($nameVal)); ?>"
                        data-type="<?php echo $typeKey; ?>"
                        data-category="<?php echo htmlspecialchars(mb_strtolower($catVal)); ?>"
                        data-stock="<?php echo htmlspecialchars($stockVal); ?>"
                        data-expiry="<?php echo htmlspecialchars($expiryVal); ?>"
                        data-purchase="<?php echo htmlspecialchars($purchaseVal); ?>"
                        data-sale="<?php echo htmlspecialchars($saleVal); ?>">
                        <td class="inv-td-code"><?php echo $codeVal !== '' ? htmlspecialchars($codeVal) : '—'; ?></td>
                        <td class="inv-td-name"><?php echo htmlspecialchars($nameVal); ?></td>
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
                        <td class="inv-td-price"><?php echo fmtI((float)$p['sale_price'], $currency); ?></td>
                        <td class="table-actions">
                            <?php if ($isService || ($p['stock'] ?? 0) > 0): ?>
                                <button type="button" onclick="addToCart(<?php echo (int)$p['id']; ?>,'<?php echo htmlspecialchars(addslashes($p['name'])); ?>',<?php echo (float)$p['sale_price']; ?>, event)" class="btn btn-sm btn-info" title="Carrito">+</button>
                            <?php endif; ?>
                            <?php if ($canEditProduct || $canCreateProduct): ?>
                                <button type="button" onclick="viewDetail(<?php echo (int)$p['id']; ?>)" class="btn btn-sm btn-info" title="Detalle">i</button>
                            <?php endif; ?>
                            <?php if ($canEditProduct): ?>
                                <button type="button" onclick="editProduct(<?php echo (int)$p['id']; ?>)" class="btn btn-sm btn-secondary" title="Editar">Editar</button>
                                <?php if (!$isService): ?>
                                    <button type="button" onclick="addStock(<?php echo (int)$p['id']; ?>)" class="btn btn-sm btn-success" title="Stock">Stock</button>
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
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                <div class="form-group">
                    <label>Tipo * <span class="field-tip" data-tip="Producto consume stock; Servicio no maneja inventario físico.">?</span></label>
                    <select name="product_type" id="prodType" class="form-control" onchange="onTypeChange()">
                        <option value="product">📦 Producto</option>
                        <option value="service">🔧 Servicio</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Código / SKU <span class="field-tip" data-tip="Referencia interna del producto (SKU). Distinto del código de barras EAN.">?</span></label>
                    <input type="text" name="code" id="prodCode" class="form-control" placeholder="SKU interno" maxlength="255" autocomplete="off" inputmode="text">
                </div>
                <div class="form-group">
                    <label>Código de barras (EAN) <span class="field-tip" data-tip="Código EAN/UPC de la pistola. Se busca aparte del SKU en ventas y compras.">?</span></label>
                    <input type="text" name="barcode" id="prodBarcode" class="form-control" placeholder="EAN / UPC" maxlength="64" autocomplete="off" inputmode="text">
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
                    <label>Unidad <span class="field-tip" data-tip="Unidad de medida (UNIDAD, KG, LT, etc.).">?</span></label>
                    <input type="text" name="unit" id="prodUnit" class="form-control" placeholder="UNIDAD">
                </div>
                <div class="form-group">
                    <label>Precio Compra <span class="field-tip" data-tip="Costo de adquisición. Sirve para margen y reportes.">?</span></label>
                    <input type="number" step="0.01" name="purchase_price" id="prodPcompra" class="form-control" placeholder="0">
                </div>
                <div class="form-group">
                    <label>Precio Venta * <span class="field-tip" data-tip="Precio al público que se usará en ventas.">?</span></label>
                    <input type="number" step="0.01" name="sale_price" id="prodPventa" required class="form-control" placeholder="0">
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
                        Controlar lotes y fecha de vencimiento
                        <span class="field-tip" data-tip="Al activarlo, cada entrada crea un lote y las ventas descargan primero el lote que vence antes (FEFO).">?</span>
                    </label>
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
            <div class="form-group">
                <label>Descripción <span class="field-tip" data-tip="Detalle adicional del producto o servicio.">?</span></label>
                <textarea name="description" id="prodDesc" rows="2" class="form-control"></textarea>
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
            <h3>Ajuste de stock</h3>
            <button onclick="document.getElementById('stockModal').style.display='none'" class="modal-close">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('app/inventario'); ?>?action=add_stock" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="stockProdId">
            <p id="stockProdName" style="margin-bottom:10px;font-weight:600;"></p>
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
                    <label>Vence <span class="field-tip" data-tip="Al indicar una fecha se crea un lote y el producto pasa a control FEFO.">?</span></label>
                    <input type="date" name="expiry_date" class="form-control">
                </div>
                <div class="form-group">
                    <label>Código de lote</label>
                    <input type="text" name="lot_code" class="form-control" maxlength="60" placeholder="Automático">
                </div>
            </div>
            <div class="form-group">
                <label>Notas</label>
                <input type="text" name="notes" class="form-control" placeholder="Ej: Conteo físico / corrección">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('stockModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-success">Agregar Stock</button>
            </div>
        </form>
    </div>
</div>

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
                    <input type="number" step="0.01" min="0" name="last_cost" class="form-control" placeholder="0">
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
                <div class="alert alert-info" style="font-size:13px;margin-bottom:14px;">
                    <strong>Instrucciones</strong>
                    <ul style="margin:8px 0 0 18px;padding:0;">
                        <li>Use el mismo formato que <strong>Exportar CSV</strong> (separador <code>;</code>, UTF-8). También acepta comas o tabulador.</li>
                        <li>Columnas: <code>SKU; Nombre; Tipo; Categoria; Costo; Precio; Stock; Min; Unidad; Estado; Ubicacion</code></li>
                        <li>La columna de nombre puede llamarse <code>Nombre</code>, <code>Producto</code> o <code>Name</code> (obligatoria).</li>
                        <li>El SKU / código de barras se guarda <strong>exactamente</strong> como texto (EAN-13 largos incluidos). No lo convierta a número en Excel.</li>
                        <li>En Excel: seleccione la columna SKU → Formato de celdas → <strong>Texto</strong> antes de pegar o guardar el CSV.</li>
                        <li>Si el SKU existe se actualiza; si no, se crea (SKU vacío genera uno).</li>
                        <li>Tipo: <code>product</code> o <code>service</code>. Estado: <code>active</code> o <code>inactive</code>.</li>
                        <li>Si la categoría no existe, se crea automáticamente.</li>
                        <li>Los cambios de stock por importación quedan en <strong>Trazabilidad</strong> (origen: Importación CSV). La exportación también deja huella.</li>
                        <li>Archivos grandes (&gt;200KB) se procesan en cola (Config → Procesar jobs).</li>
                        <li>Máximo 5MB.</li>
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
    window.invRouteVentas = '<?php echo $viewInstance->route('app/ventas'); ?>';
    window.invCanEdit = <?php echo $canEditProduct ? 'true' : 'false'; ?>;
</script>
