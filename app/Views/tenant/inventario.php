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
$needBarcode = true;
$pageScripts = ['inventario.js'];
$channelFilter = $channelFilter ?? '';
$catalogStatuses = $catalogStatuses ?? [];
$filters = $filters ?? ['q' => '', 'sort' => 'name', 'dir' => 'asc', 'query' => []];
$canCreateProduct = \SoftNova\Core\TenantMiddleware::canDo('create', 'inventario');
$canEditProduct = \SoftNova\Core\TenantMiddleware::canDo('edit', 'inventario');
$canDeleteProduct = \SoftNova\Core\TenantMiddleware::canDo('delete', 'inventario');
$canExportInv = \SoftNova\Core\TenantMiddleware::canDo('export', 'inventario');

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
</div>

<?php
$invBase = $viewInstance->route('app/inventario');
$sortOptions = [
    'name-asc' => 'Nombre (A-Z)',
    'name-desc' => 'Nombre (Z-A)',
    'price-asc' => 'Precio (menor a mayor)',
    'price-desc' => 'Precio (mayor a menor)',
    'stock-asc' => 'Stock (menor a mayor)',
    'stock-desc' => 'Stock (mayor a menor)',
    'created-desc' => 'Más recientes',
    'created-asc' => 'Más antiguos',
];
$currentSortKey = ($filters['sort'] ?? 'name') . '-' . ($filters['dir'] ?? 'asc');
if (!isset($sortOptions[$currentSortKey])) {
    $currentSortKey = 'name-asc';
}
$exportQuery = http_build_query(array_merge(['action' => 'export'], $filters['query'] ?? []));
?>
<form method="GET" action="<?php echo $invBase; ?>" style="margin-bottom:20px;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;flex:1;min-width:200px;">
            <label style="font-size:12px;">Buscar</label>
            <input type="text" name="q" value="<?php echo htmlspecialchars($filters['q'] ?? ''); ?>" class="form-control" placeholder="Nombre, código de barras o descripción...">
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
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Canal</label>
            <select name="channel" class="form-control" style="width:auto;">
                <option value="">Todos</option>
                <?php foreach (\SoftNova\Core\product_channels() as $ck => $cl): ?>
                    <option value="<?php echo htmlspecialchars($ck); ?>" <?php echo $channelFilter === $ck ? 'selected' : ''; ?>><?php echo htmlspecialchars($cl); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Ordenar por</label>
            <select name="_sortkey" class="form-control" style="width:auto;" onchange="var p=this.value.split('-');this.form.sort.value=p[0];this.form.dir.value=p[1];">
                <?php foreach ($sortOptions as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php echo $currentSortKey === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($filters['sort'] ?? 'name'); ?>">
        <input type="hidden" name="dir" value="<?php echo htmlspecialchars($filters['dir'] ?? 'asc'); ?>">
        <div style="display:flex;gap:6px;">
            <button type="submit" class="btn btn-primary" title="Aplicar filtros">Filtrar</button>
            <a href="<?php echo $invBase; ?>" class="btn btn-secondary" title="Limpiar filtros">Limpiar</a>
        </div>
    </div>
</form>

<div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center;">
    <?php if ($canCreateProduct): ?>
        <button onclick="openInventarioModal()" class="btn btn-primary neumorphic-btn" title="Nuevo producto">+ Nuevo Producto</button>
    <?php endif; ?>
    <a href="<?php echo $invBase; ?>?action=traceability" class="btn btn-secondary" title="Trazabilidad">Trazabilidad</a>
    <a href="<?php echo $viewInstance->route('app/compras'); ?>" class="btn btn-secondary" title="Compras">Compras</a>
    <?php if ($canExportInv): ?>
        <a href="<?php echo $invBase . '?' . $exportQuery; ?>" class="btn btn-secondary" title="Exportar CSV">Exportar CSV</a>
    <?php endif; ?>
    <?php if ($canCreateProduct): ?>
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('importCsvModal').style.display='flex'" title="Importar CSV">Importar CSV</button>
    <?php endif; ?>
    <?php
    $hasContabilidad = \SoftNova\Core\TenantMiddleware::canAccess('contabilidad');
    $wooSt = $catalogStatuses['woocommerce'] ?? [];
    $mlSt = $catalogStatuses['mercadolibre'] ?? [];
    ?>
    <?php if ($canCreateProduct): ?>
        <?php if ($hasContabilidad): ?>
            <a class="btn btn-secondary" href="<?php echo $viewInstance->route('app/contabilidad'); ?>?tab=integrations&amp;provider=woocommerce"
               title="Configurar WooCommerce">WooCommerce</a>
            <a class="btn btn-secondary" href="<?php echo $viewInstance->route('app/contabilidad'); ?>?tab=integrations&amp;provider=mercadolibre"
               title="Configurar Mercado Libre">Mercado Libre</a>
        <?php endif; ?>
        <?php foreach (['woocommerce' => $wooSt, 'mercadolibre' => $mlSt] as $code => $st): ?>
            <?php if (!empty($st['enabled']) && !empty($st['configured'])): ?>
                <form method="POST" action="<?php echo $invBase; ?>?action=import_catalog" data-ajax="true" style="display:inline;">
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

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;">
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
                 data-cat="<?php echo $p['category_id'] ?? ''; ?>"
                 data-unit="<?php echo htmlspecialchars($p['unit'] ?? 'UNIDAD'); ?>"
                 data-pcompra="<?php echo $p['purchase_price'] ?? 0; ?>"
                 data-pventa="<?php echo $p['sale_price'] ?? 0; ?>"
                 data-stock="<?php echo $p['stock'] ?? 0; ?>"
                 data-minstock="<?php echo $p['min_stock'] ?? 5; ?>"
                 data-status="<?php echo $p['status'] ?? 'active'; ?>"
                 data-type="<?php echo $p['product_type'] ?? 'product'; ?>">
                <div style="display:flex;align-items:flex-start;gap:18px;">
                    <div style="width:90px;height:90px;border-radius:14px;overflow:hidden;flex-shrink:0;background:var(--bg-input);display:flex;align-items:center;justify-content:center;border:2px solid var(--color-border);box-shadow:0 2px 8px rgba(0,0,0,0.06);">
                        <?php if (!empty($p['image'])): ?>
                            <img src="<?php echo htmlspecialchars($p['image']); ?>"
                                 style="width:100%;height:100%;object-fit:cover;cursor:pointer;"
                                 onclick="openImageLightbox('<?php echo htmlspecialchars($p['image']); ?>','<?php echo htmlspecialchars(addslashes($p['name'])); ?>')"
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
                            <div style="font-size:11px;color:var(--color-text-secondary);margin:3px 0;">Cód: <?php echo htmlspecialchars($p['code']); ?></div>
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
        <?php
        $pagination = $pagination ?? null;
        $paginationBaseUrl = $viewInstance->route('app/inventario');
        $paginationQuery = $filters['query'] ?? [];
        echo '<div style="grid-column:1/-1;">';
        $viewInstance->partial('pagination', compact('pagination', 'paginationBaseUrl', 'paginationQuery'));
        echo '</div>';
        ?>
    <?php endif; ?>
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
                    <label>Código / SKU <span class="field-tip" data-tip="Código de barras o SKU (texto). Debe coincidir con el escaneado por la pistola. Sin límite práctico (hasta 255 caracteres).">?</span></label>
                    <input type="text" name="code" id="prodCode" class="form-control" placeholder="Código de barras / SKU" maxlength="255" autocomplete="off" inputmode="text">
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
                        <li>Columnas: <code>SKU; Nombre; Tipo; Categoria; Costo; Precio; Stock; Min; Unidad; Estado</code></li>
                        <li>La columna de nombre puede llamarse <code>Nombre</code>, <code>Producto</code> o <code>Name</code> (obligatoria).</li>
                        <li>El SKU / código de barras se guarda <strong>exactamente</strong> como texto (EAN-13 largos incluidos). No lo convierta a número en Excel.</li>
                        <li>En Excel: seleccione la columna SKU → Formato de celdas → <strong>Texto</strong> antes de pegar o guardar el CSV.</li>
                        <li>Si el SKU existe se actualiza; si no, se crea (SKU vacío genera uno).</li>
                        <li>Tipo: <code>product</code> o <code>service</code>. Estado: <code>active</code> o <code>inactive</code>.</li>
                        <li>Si la categoría no existe, se crea automáticamente.</li>
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

<script>
    window.invRouteCreate = '<?php echo $viewInstance->route('app/inventario'); ?>?action=create';
    window.invRouteEdit = '<?php echo $viewInstance->route('app/inventario'); ?>?action=edit';
    window.invRouteDetail = '<?php echo $viewInstance->route('app/inventario'); ?>?action=detail';
    window.invRouteVentas = '<?php echo $viewInstance->route('app/ventas'); ?>';
</script>
