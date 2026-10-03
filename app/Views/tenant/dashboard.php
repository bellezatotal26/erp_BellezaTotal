<?php
$layout = 'tenant';
$title = 'Dashboard - Seri ERP';
$pageTitle = 'Dashboard';
$userName = $userName ?? 'Usuario';
$tenantName = $tenantName ?? 'Mi Empresa';
$stats = $stats ?? [];
$recentSales = $recentSales ?? [];
$openReceivables = $openReceivables ?? [];
$recentlyPaidSales = $recentlyPaidSales ?? [];
$receivablesError = $receivablesError ?? null;
$openPayables = $openPayables ?? [];
$payablesTotal = $payablesTotal ?? 0.0;
$payablesError = $payablesError ?? null;
$lowStockProducts = $lowStockProducts ?? [];
$visibleWidgets = $visibleWidgets ?? [];
$dashboardLayout = $dashboardLayout ?? [];
$widgetCatalog = $widgetCatalog ?? [];
$currency = $currency ?? ['symbol' => '$', 'decimals' => 2, 'thousands' => '.', 'decimal' => ','];
$GLOBALS['dash_currency'] = $currency;

function formatCurrencyDashboard(?float $amount): string {
    $c = $GLOBALS['dash_currency'] ?? ['symbol' => '$', 'decimals' => 2, 'thousands' => '.', 'decimal' => ','];
    return ($c['symbol'] ?? '$') . ' ' . number_format(
        $amount ?? 0,
        (int)($c['decimals'] ?? 2),
        $c['decimal'] ?? ',',
        $c['thousands'] ?? '.'
    );
}

/** @return array<int, array> */
function dashboardWidgetsByColumn(array $widgets): array
{
    $cols = [0 => [], 1 => []];
    foreach ($widgets as $w) {
        $col = (int)($w['column'] ?? 0);
        if ($col < 0 || $col > 1) {
            $col = 0;
        }
        $cols[$col][] = $w;
    }
    return $cols;
}

$boardWidgets = $boardWidgets ?? $visibleWidgets;
$cols = dashboardWidgetsByColumn($boardWidgets);
$saveUrl = $viewInstance->route('app/dashboard') . '?action=saveLayout';
$csrf = \SoftNova\Core\csrf_token();
?>

<div class="dashboard-topbar">
    <div>
        <h2 style="color: var(--color-accent-text, var(--color-primary)); margin:0;">Bienvenido, <?php echo htmlspecialchars($userName); ?></h2>
        <p style="color: var(--color-text-secondary); margin:6px 0 0;"><?php echo htmlspecialchars($tenantName); ?> · Seri ERP</p>
    </div>
    <button type="button" class="btn btn-secondary" id="screenOptionsToggle" aria-expanded="false">
        Opciones de pantalla
    </button>
</div>

<div id="screenOptionsPanel" class="screen-options-panel" hidden>
    <h4>Elementos de la pantalla</h4>
    <p class="screen-options-help">
        Marque las casillas para mostrar u ocultar bloques. Arrastre las cajas por su encabezado para reordenarlas o cambiarlas de columna. Los cambios se guardan automaticamente.
    </p>
    <div class="screen-options-grid" id="screenOptionsChecks">
        <?php
        usort($dashboardLayout, static function ($a, $b) {
            $c = ((int)($a['column'] ?? 0)) <=> ((int)($b['column'] ?? 0));
            return $c !== 0 ? $c : (((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)));
        });
        foreach ($dashboardLayout as $item):
            $meta = $widgetCatalog[$item['id']] ?? null;
            if (!$meta) {
                continue;
            }
            $canSee = \SoftNova\Core\TenantMiddleware::canAccess($meta['module'] ?? '');
            if (!$canSee) {
                continue;
            }
        ?>
            <label class="screen-options-item">
                <input type="checkbox"
                       class="dash-screen-check"
                       data-widget-id="<?php echo htmlspecialchars($item['id']); ?>"
                       <?php echo !empty($item['visible']) ? 'checked' : ''; ?>>
                <span><?php echo htmlspecialchars($meta['title']); ?></span>
            </label>
        <?php endforeach; ?>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
<?php endif; ?>

<div id="dashboardBoard" class="dashboard-board"
     data-save-url="<?php echo htmlspecialchars($saveUrl); ?>"
     data-csrf="<?php echo htmlspecialchars($csrf); ?>">
    <?php for ($col = 0; $col < 2; $col++): ?>
        <div class="dashboard-column" data-column="<?php echo $col; ?>">
            <?php
            $colHasVisible = false;
            foreach ($cols[$col] as $cw) {
                if (!empty($cw['visible'])) {
                    $colHasVisible = true;
                    break;
                }
            }
            ?>
            <?php if (!$colHasVisible): ?>
                <div class="dashboard-drop-placeholder">Arrastra aqui las cajas</div>
            <?php endif; ?>
            <?php foreach ($cols[$col] as $w): ?>
                <?php
                $wid = $w['id'];
                $title = $w['title'] ?? $wid;
                $isVisible = !empty($w['visible']);
                ?>
                <section class="dashboard-box neumorphic<?php echo $isVisible ? '' : ' is-screen-hidden'; ?>" data-widget-id="<?php echo htmlspecialchars($wid); ?>">
                    <header class="dashboard-box-header">
                        <span class="dashboard-box-handle" title="Arrastrar" aria-grabbed="false">⠿</span>
                        <h3><?php echo htmlspecialchars($title); ?></h3>
                        <div class="dashboard-box-tools">
                            <button type="button" class="dash-move-btn" data-dir="-1" title="Subir">▲</button>
                            <button type="button" class="dash-move-btn" data-dir="1" title="Bajar">▼</button>
                            <button type="button" class="dash-collapse-btn" title="Plegar">▾</button>
                        </div>
                    </header>
                    <div class="dashboard-box-body">
                        <?php if (($w['type'] ?? '') === 'kpi'): ?>
                            <?php
                            $kpiMap = [
                                'kpi_products' => ['value' => number_format($stats['total_products'] ?? 0), 'url' => $viewInstance->route('app/inventario'), 'color' => null],
                                'kpi_customers' => ['value' => number_format($stats['total_customers'] ?? 0), 'url' => $viewInstance->route('app/clientes'), 'color' => null],
                                'kpi_today_sales' => ['value' => formatCurrencyDashboard($stats['today_sales'] ?? 0), 'url' => $viewInstance->route('app/ventas'), 'color' => '#10B981'],
                                'kpi_total_sales' => ['value' => number_format($stats['total_sales'] ?? 0), 'url' => $viewInstance->route('app/ventas'), 'color' => null],
                            ];
                            $k = $kpiMap[$wid] ?? null;
                            ?>
                            <?php if ($k): ?>
                                <a href="<?php echo $k['url']; ?>" class="dashboard-kpi-link">
                                    <div class="stat-value" style="<?php echo $k['color'] ? 'color:' . $k['color'] . ';' : ''; ?>"><?php echo $k['value']; ?></div>
                                </a>
                            <?php endif; ?>
                        <?php elseif ($wid === 'receivables'): ?>
                            <?php if (!empty($receivablesError)): ?>
                                <p class="dashboard-empty" style="color:var(--dmc-DC2626, #DC2626);"><?php echo htmlspecialchars($receivablesError); ?></p>
                            <?php else: ?>
                            <?php
                            $rxTotalBalance = 0.0;
                            foreach ($openReceivables as $rxRow) {
                                $rxTotalBalance += (float)($rxRow['balance'] ?? 0);
                            }
                            ?>
                            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--color-border);">
                                <span style="font-size:13px;color:var(--color-text-secondary);">Total por cobrar</span>
                                <strong style="font-size:20px;color:var(--dmc-DC2626, #DC2626);"><?php echo formatCurrencyDashboard($rxTotalBalance); ?></strong>
                            </div>
                            <div style="margin-bottom:14px;">
                                <h4 style="margin:0 0 8px;font-size:13px;color:var(--color-text-secondary);">Pendientes de cobro</h4>
                                <?php if (empty($openReceivables)): ?>
                                    <p class="dashboard-empty">No hay saldos pendientes</p>
                                <?php else: ?>
                                    <div class="table-container">
                                        <table>
                                            <thead><tr><th>Factura</th><th>Cliente</th><th>Vence</th><th>Saldo</th><th>Estado</th></tr></thead>
                                            <tbody>
                                            <?php foreach ($openReceivables as $r): ?>
                                                <tr>
                                                    <td><a href="<?php echo $viewInstance->route('app/ventas'); ?>"><?php echo htmlspecialchars($r['invoice_number']); ?></a></td>
                                                    <td><?php echo htmlspecialchars($r['customer_name'] ?? 'Consumidor Final'); ?></td>
                                                    <td><?php echo !empty($r['due_date']) ? date('d/m/Y', strtotime($r['due_date'])) : '-'; ?></td>
                                                    <td style="color:var(--dmc-DC2626, #DC2626);font-weight:600;"><?php echo formatCurrencyDashboard((float)$r['balance']); ?></td>
                                                    <td>
                                                        <span class="badge <?php echo ($r['status'] ?? '') === 'partial' ? 'badge-warning' : 'badge-info'; ?>">
                                                            <?php echo ($r['status'] ?? '') === 'partial' ? 'Parcial' : 'Pendiente'; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h4 style="margin:0 0 8px;font-size:13px;color:var(--color-text-secondary);">Ultimas ventas saldadas</h4>
                                <?php if (empty($recentlyPaidSales)): ?>
                                    <p class="dashboard-empty">Aun no hay ventas saldadas</p>
                                <?php else: ?>
                                    <div class="table-container">
                                        <table>
                                            <thead><tr><th>Factura</th><th>Cliente</th><th>Total</th><th>Fecha</th></tr></thead>
                                            <tbody>
                                            <?php foreach ($recentlyPaidSales as $ps): ?>
                                                <tr>
                                                    <td><a href="<?php echo $viewInstance->route('app/ventas'); ?>"><?php echo htmlspecialchars($ps['invoice_number']); ?></a></td>
                                                    <td><?php echo htmlspecialchars($ps['customer_name'] ?? 'Consumidor Final'); ?></td>
                                                    <td style="color:#10B981;font-weight:600;"><?php echo formatCurrencyDashboard((float)$ps['total_amount']); ?></td>
                                                    <td><?php echo !empty($ps['paid_at']) ? date('d/m/Y', strtotime($ps['paid_at'])) : '-'; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="margin-top:10px;">
                                <a href="<?php echo $viewInstance->route('app/ventas'); ?>" class="btn btn-sm btn-secondary">Ver ventas</a>
                            </div>
                            <?php endif; ?>
                        <?php elseif ($wid === 'payables'): ?>
                            <?php if (!empty($payablesError)): ?>
                                <p class="dashboard-empty" style="color:var(--dmc-DC2626, #DC2626);"><?php echo htmlspecialchars($payablesError); ?></p>
                            <?php else: ?>
                            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--color-border);">
                                <span style="font-size:13px;color:var(--color-text-secondary);">Total por pagar</span>
                                <strong style="font-size:20px;color:var(--dmc-B45309, #B45309);"><?php echo formatCurrencyDashboard((float)$payablesTotal); ?></strong>
                            </div>
                            <div style="margin-bottom:8px;">
                                <h4 style="margin:0 0 8px;font-size:13px;color:var(--color-text-secondary);">Pendientes a proveedores</h4>
                                <?php if (empty($openPayables)): ?>
                                    <p class="dashboard-empty">No hay saldos por pagar</p>
                                <?php else: ?>
                                    <div class="table-container">
                                        <table>
                                            <thead><tr><th>Orden</th><th>Proveedor</th><th>Vence</th><th>Saldo</th><th>Estado</th></tr></thead>
                                            <tbody>
                                            <?php foreach ($openPayables as $po):
                                                $bal = (float)($po['balance'] ?? 0);
                                                $overdue = (int)($po['days_overdue'] ?? 0) > 0;
                                                $status = (string)($po['payment_status'] ?? 'pending');
                                            ?>
                                                <tr>
                                                    <td>
                                                        <a href="<?php echo $viewInstance->route('app/compras'); ?>">
                                                            <?php echo htmlspecialchars($po['order_number'] ?? ('#' . (int)($po['id'] ?? 0))); ?>
                                                        </a>
                                                        <?php if (!empty($po['invoice_number'])): ?>
                                                            <br><small><?php echo htmlspecialchars((string)$po['invoice_number']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($po['supplier_name'] ?? 'Proveedor'); ?></td>
                                                    <td>
                                                        <?php echo !empty($po['due_date']) ? date('d/m/Y', strtotime((string)$po['due_date'])) : '-'; ?>
                                                        <?php if ($overdue): ?>
                                                            <br><span class="badge badge-danger">Vencida</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="color:var(--dmc-B45309, #B45309);font-weight:600;"><?php echo formatCurrencyDashboard($bal); ?></td>
                                                    <td>
                                                        <span class="badge <?php echo $status === 'partial' ? 'badge-warning' : 'badge-info'; ?>">
                                                            <?php echo $status === 'partial' ? 'Parcial' : 'Pendiente'; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="margin-top:10px;">
                                <a href="<?php echo $viewInstance->route('app/compras'); ?>" class="btn btn-sm btn-secondary">Ver compras</a>
                            </div>
                            <?php endif; ?>
                        <?php elseif ($wid === 'recent_sales'): ?>
                            <?php if (empty($recentSales)): ?>
                                <p class="dashboard-empty">No hay ventas registradas aún</p>
                            <?php else: ?>
                                <div class="table-container">
                                    <table>
                                        <thead><tr><th>Factura</th><th>Cliente</th><th>Total</th><th>Estado</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($recentSales as $sale): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($sale['invoice_number']); ?></td>
                                                <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'Consumidor Final'); ?></td>
                                                <td><?php echo formatCurrencyDashboard($sale['total']); ?></td>
                                                <td>
                                                    <span class="badge <?php
                                                        echo $sale['payment_status'] === 'paid' ? 'badge-success'
                                                            : ($sale['payment_status'] === 'partial' ? 'badge-warning' : 'badge-info');
                                                    ?>">
                                                        <?php
                                                        echo $sale['payment_status'] === 'paid' ? 'Pagado'
                                                            : ($sale['payment_status'] === 'partial' ? 'Parcial' : 'Pendiente');
                                                        ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div style="margin-top:10px;">
                                    <a href="<?php echo $viewInstance->route('app/ventas'); ?>" class="btn btn-sm btn-secondary">Ver todas</a>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($wid === 'low_stock_list'): ?>
                            <?php if (empty($lowStockProducts)): ?>
                                <p class="dashboard-empty">Inventario en niveles saludables</p>
                            <?php else: ?>
                                <div class="table-container">
                                    <table>
                                        <thead><tr><th>Producto</th><th>Stock</th><th>Min.</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($lowStockProducts as $p): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($p['name']); ?></td>
                                                <td style="color:var(--dmc-DC2626, #DC2626);font-weight:600;"><?php echo (int)$p['stock']; ?></td>
                                                <td><?php echo (int)$p['min_stock']; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div style="margin-top:10px;">
                                    <a href="<?php echo $viewInstance->route('app/inventario'); ?>" class="btn btn-sm btn-secondary">Ver inventario</a>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($wid === 'today_sold_products'): ?>
                            <?php
                            $todaySoldProducts = $todaySoldProducts ?? [];
                            ?>
                            <p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 10px;">Más vendidos · hoy</p>
                            <?php if (empty($todaySoldProducts)): ?>
                                <p class="dashboard-empty">Sin ventas de productos hoy</p>
                            <?php else: ?>
                                <div class="table-container">
                                    <table>
                                        <thead><tr><th>#</th><th>Producto</th><th>Unid.</th><th>Ventas</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($todaySoldProducts as $i => $p): ?>
                                            <tr>
                                                <td><?php echo $i + 1; ?></td>
                                                <td><?php echo htmlspecialchars((string)($p['name'] ?? '')); ?></td>
                                                <td><span class="badge badge-success"><?php echo (int)($p['total_qty'] ?? 0); ?></span></td>
                                                <td><?php echo formatCurrencyDashboard($p['total_sales'] ?? 0); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div style="margin-top:10px;">
                                    <a href="<?php echo $viewInstance->route('app/reportes'); ?>" class="btn btn-sm btn-secondary">Ver reportes</a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endfor; ?>
</div>

<?php if (empty($visibleWidgets)): ?>
    <div class="card neumorphic" style="margin-top:16px;">
        <div class="card-body" style="text-align:center;padding:28px;">
            <p style="color:var(--color-text-secondary);margin-bottom:10px;">No hay bloques visibles. Abra <strong>Opciones de pantalla</strong> para activarlos.</p>
        </div>
    </div>
<?php endif; ?>

<script>
(function() {
    var board = document.getElementById('dashboardBoard');
    if (!board) return;

    var saveUrl = board.getAttribute('data-save-url');
    var csrf = board.getAttribute('data-csrf');
    var saveTimer = null;
    var pendingReload = false;

    var toggleBtn = document.getElementById('screenOptionsToggle');
    var panel = document.getElementById('screenOptionsPanel');
    if (toggleBtn && panel) {
        toggleBtn.addEventListener('click', function() {
            var open = panel.hasAttribute('hidden');
            if (open) {
                panel.removeAttribute('hidden');
                toggleBtn.setAttribute('aria-expanded', 'true');
                toggleBtn.classList.add('btn-primary');
                toggleBtn.classList.remove('btn-secondary');
            } else {
                panel.setAttribute('hidden', '');
                toggleBtn.setAttribute('aria-expanded', 'false');
                toggleBtn.classList.remove('btn-primary');
                toggleBtn.classList.add('btn-secondary');
            }
        });
    }

    function collectLayout() {
        var layout = [];
        var checks = {};
        document.querySelectorAll('.dash-screen-check').forEach(function(cb) {
            checks[cb.getAttribute('data-widget-id')] = !!cb.checked;
        });

        // Widgets visibles en el tablero (orden real)
        document.querySelectorAll('.dashboard-column').forEach(function(col) {
            var column = parseInt(col.getAttribute('data-column') || '0', 10);
            var order = 0;
            col.querySelectorAll('.dashboard-box').forEach(function(box) {
                var id = box.getAttribute('data-widget-id');
                layout.push({
                    id: id,
                    visible: checks.hasOwnProperty(id) ? checks[id] : true,
                    order: order++,
                    column: column
                });
            });
        });

        // Widgets que solo están en checkboxes (activar o dejar ocultos)
        Object.keys(checks).forEach(function(id) {
            var exists = layout.some(function(x) { return x.id === id; });
            if (!exists) {
                layout.push({
                    id: id,
                    visible: !!checks[id],
                    order: layout.length,
                    column: 0
                });
            } else {
                layout.forEach(function(x) {
                    if (x.id === id) x.visible = !!checks[id];
                });
            }
        });

        return layout;
    }

    function refreshPlaceholders() {
        document.querySelectorAll('.dashboard-column').forEach(function(col) {
            var hasBox = !!col.querySelector('.dashboard-box:not(.is-screen-hidden)');
            var ph = col.querySelector('.dashboard-drop-placeholder');
            if (!hasBox && !ph) {
                var div = document.createElement('div');
                div.className = 'dashboard-drop-placeholder';
                div.textContent = 'Arrastra aqui las cajas';
                col.appendChild(div);
            } else if (hasBox && ph) {
                ph.remove();
            }
        });
    }

    function toggleWidgetVisibility(widgetId, visible) {
        var box = board.querySelector('.dashboard-box[data-widget-id="' + widgetId + '"]');
        if (!box) return;
        box.classList.toggle('is-screen-hidden', !visible);
        refreshPlaceholders();
    }

    function saveLayout(reloadAfter) {
        var payload = {
            csrf_token: csrf,
            layout: collectLayout()
        };
        fetch(saveUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf
            },
            body: JSON.stringify(payload)
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) {
                if (typeof showAlert === 'function') showAlert(d.message || 'No se pudo guardar', 'error');
                return;
            }
            if (reloadAfter) {
                if (typeof showAlert === 'function') showAlert(d.message || 'Guardado', 'success');
                setTimeout(function() { window.location.reload(); }, 450);
            }
        })
        .catch(function() {
            if (typeof showAlert === 'function') showAlert('Error de conexion al guardar', 'error');
        });
    }

    function scheduleSave(reloadAfter) {
        pendingReload = pendingReload || !!reloadAfter;
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function() {
            var shouldReload = pendingReload;
            pendingReload = false;
            saveLayout(shouldReload);
        }, 250);
    }

    // Checkboxes: mostrar/ocultar al instante, guardar sin recargar
    document.querySelectorAll('.dash-screen-check').forEach(function(cb) {
        cb.addEventListener('change', function() {
            toggleWidgetVisibility(cb.getAttribute('data-widget-id'), cb.checked);
            scheduleSave(false);
        });
    });

    // Collapse + botones subir/bajar
    board.addEventListener('click', function(e) {
        var collapse = e.target.closest('.dash-collapse-btn');
        if (collapse) {
            var box = collapse.closest('.dashboard-box');
            if (box) box.classList.toggle('is-collapsed');
            return;
        }
        var move = e.target.closest('.dash-move-btn');
        if (move) {
            var box = move.closest('.dashboard-box');
            var dir = parseInt(move.getAttribute('data-dir') || '0', 10);
            if (!box) return;
            if (dir < 0 && box.previousElementSibling && box.previousElementSibling.classList.contains('dashboard-box')) {
                box.parentNode.insertBefore(box, box.previousElementSibling);
            } else if (dir > 0 && box.nextElementSibling && box.nextElementSibling.classList.contains('dashboard-box')) {
                box.parentNode.insertBefore(box.nextElementSibling, box);
            }
            refreshPlaceholders();
            scheduleSave(false);
        }
    });

    // Arrastre fluido con pointer (se ve la caja mientras se mueve)
    var dragState = null;

    function startPointerDrag(e) {
        var handle = e.target.closest('.dashboard-box-handle');
        if (!handle || e.button !== 0) return;
        var box = handle.closest('.dashboard-box');
        if (!box) return;
        e.preventDefault();

        var rect = box.getBoundingClientRect();
        var placeholder = document.createElement('div');
        placeholder.className = 'dashboard-drag-placeholder';
        placeholder.style.height = rect.height + 'px';

        var ghost = box.cloneNode(true);
        ghost.classList.add('dashboard-drag-ghost');
        ghost.style.width = rect.width + 'px';
        ghost.style.height = rect.height + 'px';
        ghost.style.left = rect.left + 'px';
        ghost.style.top = rect.top + 'px';
        document.body.appendChild(ghost);

        box.classList.add('is-dragging-source');
        box.parentNode.insertBefore(placeholder, box);

        dragState = {
            box: box,
            placeholder: placeholder,
            ghost: ghost,
            offsetX: e.clientX - rect.left,
            offsetY: e.clientY - rect.top,
            pointerId: e.pointerId
        };

        handle.setAttribute('aria-grabbed', 'true');
        board.classList.add('is-reordering');
        try { handle.setPointerCapture(e.pointerId); } catch (err) {}
    }

    function movePointerDrag(e) {
        if (!dragState) return;
        e.preventDefault();
        var x = e.clientX - dragState.offsetX;
        var y = e.clientY - dragState.offsetY;
        dragState.ghost.style.transform = 'translate(' + (x - parseFloat(dragState.ghost.style.left)) + 'px, ' + (y - parseFloat(dragState.ghost.style.top)) + 'px)';
        // Posición absoluta directa (más fluido)
        dragState.ghost.style.left = x + 'px';
        dragState.ghost.style.top = y + 'px';
        dragState.ghost.style.transform = 'none';

        var el = document.elementFromPoint(e.clientX, e.clientY);
        var col = el ? el.closest('.dashboard-column') : null;
        if (!col) {
            // Buscar columna más cercana por X
            var cols = board.querySelectorAll('.dashboard-column');
            var best = null;
            var bestDist = Infinity;
            cols.forEach(function(c) {
                var r = c.getBoundingClientRect();
                var cx = r.left + r.width / 2;
                var d = Math.abs(e.clientX - cx);
                if (d < bestDist) { bestDist = d; best = c; }
            });
            col = best;
        }
        if (!col) return;

        document.querySelectorAll('.dashboard-column').forEach(function(c) {
            c.classList.toggle('is-drop-target', c === col);
        });

        var after = getDragAfterElement(col, e.clientY, dragState.placeholder);
        if (after == null) {
            col.appendChild(dragState.placeholder);
        } else {
            col.insertBefore(dragState.placeholder, after);
        }
        var ph = col.querySelector('.dashboard-drop-placeholder');
        if (ph) ph.remove();
    }

    function endPointerDrag(e) {
        if (!dragState) return;
        var box = dragState.box;
        var placeholder = dragState.placeholder;
        var ghost = dragState.ghost;
        var handle = box.querySelector('.dashboard-box-handle');

        if (placeholder && placeholder.parentNode) {
            placeholder.parentNode.insertBefore(box, placeholder);
            placeholder.remove();
        }
        box.classList.remove('is-dragging-source');
        if (ghost && ghost.parentNode) ghost.remove();
        if (handle) {
            handle.setAttribute('aria-grabbed', 'false');
            try { handle.releasePointerCapture(dragState.pointerId); } catch (err) {}
        }
        dragState = null;
        board.classList.remove('is-reordering');
        document.querySelectorAll('.dashboard-column').forEach(function(c) { c.classList.remove('is-drop-target'); });
        refreshPlaceholders();
        scheduleSave(false);
    }

    board.addEventListener('pointerdown', startPointerDrag);
    board.addEventListener('pointermove', movePointerDrag);
    board.addEventListener('pointerup', endPointerDrag);
    board.addEventListener('pointercancel', endPointerDrag);

    function getDragAfterElement(container, y, placeholder) {
        var els = [].slice.call(container.querySelectorAll('.dashboard-box:not(.is-dragging-source)'));
        var closest = { offset: Number.NEGATIVE_INFINITY, element: null };
        els.forEach(function(child) {
            if (child === placeholder) return;
            var box = child.getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) {
                closest = { offset: offset, element: child };
            }
        });
        // También considerar el placeholder si ya está en otra posición
        return closest.element;
    }

    refreshPlaceholders();
})();
</script>
