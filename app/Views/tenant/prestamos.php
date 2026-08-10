<?php
$layout = 'tenant';
$title = 'Préstamos - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Préstamos de productos';
$loans = $loans ?? [];
$stockAlert = $stockAlert ?? ['count' => 0, 'debts' => [], 'summary_lines' => [], 'negative_count' => 0];
$products = $products ?? [];
$canCreate = $canCreate ?? false;
$canEdit = $canEdit ?? false;
$loanReasonsHtml = $loanReasonsHtml ?? '';
$base = $viewInstance->route('app/prestamos');
$searchUrl = $base . '?action=searchProducts';
?>
<?php echo flashMessage(); ?>

<div style="display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap;align-items:center;">
    <p style="margin:0;flex:1;min-width:220px;color:var(--color-text-secondary);font-size:13px;">
        Salida de mercancía que <strong>no es venta</strong>. La devolución es manual.
        Las ventas sin stock se reponen al ingresar compra; los préstamos no.
    </p>
    <?php if ($canCreate): ?>
    <button type="button" class="btn btn-primary neumorphic-btn" onclick="openLoanModal()">+ Salida por préstamo</button>
    <?php endif; ?>
    <a href="<?php echo $viewInstance->route('app/inventario'); ?>" class="btn btn-secondary">Ir a Inventario</a>
</div>

<?php if (($stockAlert['count'] ?? 0) > 0): ?>
<div class="alert alert-warning alert-stock-pending" style="margin-bottom:18px;">
    <strong>Atención inventario:</strong>
    <?php echo (int)($stockAlert['negative_count'] ?? 0); ?> producto(s) en stock negativo ·
    <?php echo (int)($stockAlert['loan_count'] ?? 0); ?> préstamo(s) abiertos ·
    <?php echo (int)($stockAlert['sale_debt_count'] ?? 0); ?> venta(s) sin stock pendiente(s).
    <ul>
        <?php foreach (array_slice($stockAlert['summary_lines'] ?? [], 0, 12) as $line): ?>
            <li><?php echo htmlspecialchars((string)$line); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="stats-grid" style="margin-bottom:18px;">
    <div class="stat-card neumorphic">
        <h4>Préstamos abiertos</h4>
        <div class="stat-value"><?php echo count($loans); ?></div>
    </div>
    <div class="stat-card neumorphic">
        <h4>Stock negativo</h4>
        <div class="stat-value" style="color:#DC2626;"><?php echo (int)($stockAlert['negative_count'] ?? 0); ?></div>
    </div>
    <div class="stat-card neumorphic">
        <h4>Ventas sin stock (deuda)</h4>
        <div class="stat-value" style="color:#B45309;"><?php echo (int)($stockAlert['sale_debt_count'] ?? 0); ?></div>
    </div>
</div>

<div class="card neumorphic">
    <div class="card-header"><h3>Préstamos pendientes de devolución</h3></div>
    <div class="card-body">
        <?php if (empty($loans)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:24px;">No hay préstamos abiertos.</p>
        <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Producto</th>
                        <th>Stock actual</th>
                        <th>Pendiente</th>
                        <th>Motivo</th>
                        <th>A quién</th>
                        <th>Nota</th>
                        <?php if ($canEdit): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($loans as $loan): ?>
                    <tr>
                        <td><?php echo !empty($loan['created_at']) ? date('d/m/Y H:i', strtotime((string)$loan['created_at'])) : '—'; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars((string)($loan['product_name'] ?? '')); ?></strong>
                            <?php if (!empty($loan['product_code'])): ?>
                                <div style="font-size:12px;color:var(--color-text-secondary);"><?php echo htmlspecialchars((string)$loan['product_code']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:600;color:<?php echo ((int)($loan['product_stock'] ?? 0) < 0) ? '#DC2626' : 'inherit'; ?>;">
                            <?php echo (int)($loan['product_stock'] ?? 0); ?>
                        </td>
                        <td><span class="badge badge-warning"><?php echo (int)$loan['qty_remaining']; ?> / <?php echo (int)$loan['qty_owed']; ?></span></td>
                        <td><?php echo htmlspecialchars((string)($loan['reason_label'] ?? $loan['reason'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($loan['borrower'] ?? '—')); ?></td>
                        <td style="max-width:220px;font-size:13px;"><?php echo htmlspecialchars((string)($loan['note'] ?? '')); ?></td>
                        <?php if ($canEdit): ?>
                        <td class="table-actions">
                            <button type="button" class="btn btn-sm btn-success"
                                onclick="openReturnModal(<?php echo (int)$loan['id']; ?>, <?php echo (int)$loan['qty_remaining']; ?>, <?php echo htmlspecialchars(json_encode((string)($loan['product_name'] ?? ''), JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>, <?php echo (int)($loan['product_id'] ?? 0); ?>)">
                                Devolver
                            </button>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canCreate || $canEdit): ?>
<?php if ($canCreate): ?>
<div id="loanModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:520px;">
        <div class="modal-header">
            <h3>Salida por préstamo</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('loanModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $base; ?>?action=loan" data-ajax="true" id="loanForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body">
                <div class="form-group">
                    <label>Producto *</label>
                    <input type="hidden" name="product_id" id="loanProductId" required>
                    <input type="text" id="loanProductSearch" class="form-control" autocomplete="off"
                           placeholder="Buscar nombre, SKU o escanear código…"
                           data-barcode-input="true" data-product-picker="loan">
                    <div id="loanProductResults" class="product-picker-results" style="display:none;"></div>
                    <div id="loanProductSelected" class="product-picker-selected" style="display:none;"></div>
                    <small id="loanStockHint" style="color:var(--color-text-secondary);"></small>
                </div>
                <div class="form-group">
                    <label>Cantidad *</label>
                    <input type="number" name="quantity" id="loanQty" class="form-control" min="1" value="1" required>
                </div>
                <div class="form-group">
                    <label>Motivo *</label>
                    <select name="reason" id="loanReason" class="form-control" required>
                        <?php echo $loanReasonsHtml; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>A quién se presta *</label>
                    <input type="text" name="borrower" id="loanBorrower" class="form-control" maxlength="120" placeholder="Nombre de la persona o empresa" required>
                </div>
                <div class="form-group" id="loanNoteGroup">
                    <label>Nota / detalle <span id="loanNoteReq" style="display:none;">*</span></label>
                    <input type="text" name="note" id="loanNote" class="form-control" maxlength="180" placeholder="Detalle adicional">
                </div>
                <p style="font-size:12px;color:var(--color-text-secondary);margin:0;">
                    Si no hay stock suficiente, el inventario quedará en negativo y el préstamo seguirá pendiente hasta la devolución.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('loanModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Registrar salida</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div id="returnModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:520px;">
        <div class="modal-header">
            <h3>Devolver préstamo</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('returnModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $base; ?>?action=return" data-ajax="true" id="returnLoanForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="debt_id" id="returnDebtId">
            <input type="hidden" name="original_product_id" id="returnOriginalProductId" value="">
            <div class="modal-body">
                <p id="returnProductName" style="font-weight:600;margin-top:0;"></p>
                <div class="form-group">
                    <label>Cantidad a devolver *</label>
                    <input type="number" name="quantity" id="returnQty" class="form-control" min="1" required>
                    <small style="color:var(--color-text-secondary);">Máximo pendiente: <span id="returnMax">0</span></small>
                </div>
                <div class="form-group">
                    <label>¿Qué producto está devolviendo? *</label>
                    <div style="display:flex;flex-direction:column;gap:8px;margin-top:6px;">
                        <label style="display:flex;align-items:center;gap:8px;font-weight:500;cursor:pointer;">
                            <input type="radio" name="return_mode" id="returnModeSame" value="same" checked>
                            El mismo producto prestado
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;font-weight:500;cursor:pointer;">
                            <input type="radio" name="return_mode" id="returnModeDiff" value="different">
                            Otro producto diferente
                        </label>
                    </div>
                </div>
                <div class="form-group" id="returnAltProductWrap" style="display:none;">
                    <label>Producto que entrega *</label>
                    <input type="hidden" name="return_product_id" id="returnProductId" value="">
                    <input type="text" id="returnProductSearch" class="form-control" autocomplete="off"
                           placeholder="Buscar o escanear el producto que entrega…"
                           data-barcode-input="true" data-product-picker="return">
                    <div id="returnProductResults" class="product-picker-results" style="display:none;"></div>
                    <div id="returnProductSelected" class="product-picker-selected" style="display:none;"></div>
                    <small style="color:var(--color-text-secondary);">
                        El stock se sumará a este producto y el préstamo del original se cerrará (sustitución).
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('returnModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-success">Confirmar devolución</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
.product-picker-results {
    margin-top: 6px;
    border: 1px solid var(--color-border);
    border-radius: 8px;
    max-height: 220px;
    overflow: auto;
    background: var(--bg-card, #fff);
}
.product-picker-row {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 8px 10px;
    cursor: pointer;
    border-bottom: 1px solid var(--color-border);
    font-size: 13px;
}
.product-picker-row:last-child { border-bottom: 0; }
.product-picker-row:hover { background: var(--bg-input, #f8fafc); }
.product-picker-selected {
    margin-top: 8px;
    padding: 8px 10px;
    border-radius: 8px;
    background: var(--bg-input, #f1f5f9);
    font-size: 13px;
}
</style>

<script>
window.PRESTAMOS_SEARCH_URL = <?php echo json_encode($searchUrl); ?>;
window.PRESTAMOS_PRODUCTS_SEED = <?php echo json_encode(array_values(array_map(static function ($p) {
    return [
        'id' => (int)$p['id'],
        'code' => (string)($p['code'] ?? ''),
        'name' => (string)($p['name'] ?? ''),
        'stock' => (int)($p['stock'] ?? 0),
        'barcode' => (string)($p['barcode'] ?? ''),
    ];
}, $products)), JSON_UNESCAPED_UNICODE); ?>;

function escHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function productLabel(p) {
    return ((p.code ? p.code + ' · ' : '') + (p.name || '')).trim();
}

function setLoanProduct(p) {
    var hid = document.getElementById('loanProductId');
    var sel = document.getElementById('loanProductSelected');
    var search = document.getElementById('loanProductSearch');
    var results = document.getElementById('loanProductResults');
    if (!hid || !p) return;
    hid.value = String(p.id);
    hid.dataset.stock = String(p.stock != null ? p.stock : 0);
    if (sel) {
        sel.style.display = '';
        sel.innerHTML = '<strong>' + escHtml(productLabel(p)) + '</strong>'
            + ' <span style="color:var(--color-text-secondary);">· stock ' + escHtml(p.stock) + '</span>'
            + ' <button type="button" class="btn btn-sm btn-secondary" style="margin-left:8px;" onclick="clearLoanProduct()">Cambiar</button>';
    }
    if (search) { search.value = ''; search.style.display = 'none'; }
    if (results) { results.style.display = 'none'; results.innerHTML = ''; }
    updateLoanStockHint();
}

function clearLoanProduct() {
    var hid = document.getElementById('loanProductId');
    var sel = document.getElementById('loanProductSelected');
    var search = document.getElementById('loanProductSearch');
    if (hid) { hid.value = ''; delete hid.dataset.stock; }
    if (sel) { sel.style.display = 'none'; sel.innerHTML = ''; }
    if (search) { search.style.display = ''; search.focus(); }
    updateLoanStockHint();
}

function setReturnProduct(p) {
    var hid = document.getElementById('returnProductId');
    var sel = document.getElementById('returnProductSelected');
    var search = document.getElementById('returnProductSearch');
    var results = document.getElementById('returnProductResults');
    var orig = document.getElementById('returnOriginalProductId');
    if (!hid || !p) return;
    if (orig && String(orig.value) === String(p.id)) {
        if (typeof showAlert === 'function') showAlert('Elija un producto distinto al prestado', 'error');
        return;
    }
    hid.value = String(p.id);
    if (sel) {
        sel.style.display = '';
        sel.innerHTML = '<strong>' + escHtml(productLabel(p)) + '</strong>'
            + ' <button type="button" class="btn btn-sm btn-secondary" style="margin-left:8px;" onclick="clearReturnProduct()">Cambiar</button>';
    }
    if (search) { search.value = ''; search.style.display = 'none'; }
    if (results) { results.style.display = 'none'; results.innerHTML = ''; }
}

function clearReturnProduct() {
    var hid = document.getElementById('returnProductId');
    var sel = document.getElementById('returnProductSelected');
    var search = document.getElementById('returnProductSearch');
    if (hid) hid.value = '';
    if (sel) { sel.style.display = 'none'; sel.innerHTML = ''; }
    if (search) { search.style.display = ''; search.focus(); }
}

function renderPickerResults(containerId, list, onPick) {
    var box = document.getElementById(containerId);
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
        row.innerHTML = '<span>' + escHtml(productLabel(p)) + '</span>'
            + '<span style="color:var(--color-text-secondary);">stock ' + escHtml(p.stock) + '</span>';
        row.addEventListener('click', function () { onPick(p); });
        box.appendChild(row);
    });
}

function searchProductsLocal(q) {
    q = String(q || '').trim().toLowerCase();
    var seed = window.PRESTAMOS_PRODUCTS_SEED || [];
    if (!q) return [];
    return seed.filter(function (p) {
        return String(p.name || '').toLowerCase().indexOf(q) >= 0
            || String(p.code || '').toLowerCase().indexOf(q) >= 0
            || String(p.barcode || '').toLowerCase() === q;
    }).slice(0, 25);
}

function searchProductsRemote(q, cb) {
    var url = (window.PRESTAMOS_SEARCH_URL || '') + '&q=' + encodeURIComponent(q);
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            cb((d && d.products) ? d.products : searchProductsLocal(q));
        })
        .catch(function () { cb(searchProductsLocal(q)); });
}

function bindProductPicker(searchId, resultsId, onPick, excludeIdFn) {
    var input = document.getElementById(searchId);
    if (!input) return;
    var timer = null;
    function run(autoExact) {
        var q = (input.value || '').trim();
        if (q.length < 1) {
            var box = document.getElementById(resultsId);
            if (box) { box.style.display = 'none'; box.innerHTML = ''; }
            return;
        }
        searchProductsRemote(q, function (list) {
            var exclude = excludeIdFn ? excludeIdFn() : null;
            if (exclude) {
                list = (list || []).filter(function (p) { return String(p.id) !== String(exclude); });
            }
            if (autoExact && list && list.length) {
                var ql = q.toLowerCase();
                var exact = list.find(function (p) {
                    return String(p.code || '').toLowerCase() === ql
                        || String(p.barcode || '').toLowerCase() === ql;
                }) || (list.length === 1 ? list[0] : null);
                if (exact) {
                    onPick(exact);
                    return;
                }
            }
            renderPickerResults(resultsId, list, onPick);
        });
    }
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { run(false); }, 220);
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(timer);
            run(true);
        }
    });
}

function openLoanModal(preselectId) {
    var modal = document.getElementById('loanModal');
    if (!modal) return;
    clearLoanProduct();
    if (preselectId) {
        var seed = (window.PRESTAMOS_PRODUCTS_SEED || []).find(function (p) {
            return String(p.id) === String(preselectId);
        });
        if (seed) {
            setLoanProduct(seed);
        } else {
            searchProductsRemote(String(preselectId), function (list) {
                var hit = (list || []).find(function (p) { return String(p.id) === String(preselectId); });
                if (hit) setLoanProduct(hit);
            });
        }
    }
    modal.style.display = 'flex';
    var search = document.getElementById('loanProductSearch');
    if (search && search.style.display !== 'none') setTimeout(function () { search.focus(); }, 50);
}

function updateLoanStockHint() {
    var hid = document.getElementById('loanProductId');
    var hint = document.getElementById('loanStockHint');
    if (!hid || !hint) return;
    if (!hid.value) { hint.textContent = ''; return; }
    var stock = parseInt(hid.dataset.stock || '0', 10);
    var qty = parseInt((document.getElementById('loanQty') || {}).value || '1', 10) || 1;
    if (qty > Math.max(0, stock)) {
        hint.textContent = 'Stock actual: ' + stock + '. Esta salida dejará el inventario en ' + (stock - qty) + '.';
        hint.style.color = '#B45309';
    } else {
        hint.textContent = 'Stock actual: ' + stock;
        hint.style.color = '';
    }
}

function toggleReturnMode() {
    var diff = document.getElementById('returnModeDiff');
    var wrap = document.getElementById('returnAltProductWrap');
    var show = !!(diff && diff.checked);
    if (wrap) wrap.style.display = show ? '' : 'none';
    if (!show) clearReturnProduct();
    else {
        var search = document.getElementById('returnProductSearch');
        if (search && search.style.display !== 'none') setTimeout(function () { search.focus(); }, 50);
    }
}

function openReturnModal(id, maxQty, name, originalProductId) {
    document.getElementById('returnDebtId').value = id;
    document.getElementById('returnMax').textContent = maxQty;
    document.getElementById('returnQty').value = maxQty;
    document.getElementById('returnQty').max = maxQty;
    document.getElementById('returnProductName').textContent = 'Prestado: ' + (name || 'Producto');
    document.getElementById('returnOriginalProductId').value = originalProductId || '';
    var same = document.getElementById('returnModeSame');
    if (same) same.checked = true;
    clearReturnProduct();
    toggleReturnMode();
    document.getElementById('returnModal').style.display = 'flex';
}

(function(){
    bindProductPicker('loanProductSearch', 'loanProductResults', setLoanProduct, null);
    bindProductPicker('returnProductSearch', 'returnProductResults', setReturnProduct, function () {
        return (document.getElementById('returnOriginalProductId') || {}).value || null;
    });

    var qty = document.getElementById('loanQty');
    var reason = document.getElementById('loanReason');
    if (qty) qty.addEventListener('input', updateLoanStockHint);
    if (reason) reason.addEventListener('change', function(){
        var req = document.getElementById('loanNoteReq');
        var note = document.getElementById('loanNote');
        var isOther = reason.value === 'otro';
        if (req) req.style.display = isOther ? 'inline' : 'none';
        if (note) note.required = isOther;
    });
    var same = document.getElementById('returnModeSame');
    var diff = document.getElementById('returnModeDiff');
    if (same) same.addEventListener('change', toggleReturnMode);
    if (diff) diff.addEventListener('change', toggleReturnMode);

    var loanForm = document.getElementById('loanForm');
    if (loanForm) {
        loanForm.addEventListener('submit', function (e) {
            var hid = document.getElementById('loanProductId');
            if (!hid || !hid.value) {
                e.preventDefault();
                e.stopImmediatePropagation();
                if (typeof showAlert === 'function') showAlert('Seleccione o escanee un producto', 'error');
                return false;
            }
        }, true);
    }
    var form = document.getElementById('returnLoanForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            var diffMode = document.getElementById('returnModeDiff');
            var alt = document.getElementById('returnProductId');
            if (diffMode && diffMode.checked && alt && !alt.value) {
                e.preventDefault();
                e.stopImmediatePropagation();
                if (typeof showAlert === 'function') showAlert('Seleccione o escanee el producto que está devolviendo', 'error');
                return false;
            }
        }, true);
    }

    if (window.SoftNovaBarcode && typeof SoftNovaBarcode.onScan === 'function') {
        SoftNovaBarcode.onScan(function (code, product) {
            var loanOpen = document.getElementById('loanModal') && document.getElementById('loanModal').style.display !== 'none';
            var retOpen = document.getElementById('returnModal') && document.getElementById('returnModal').style.display !== 'none';
            if (!product) return false;
            if (loanOpen) {
                setLoanProduct(product);
                return true;
            }
            if (retOpen) {
                var diffMode = document.getElementById('returnModeDiff');
                if (diffMode && !diffMode.checked) {
                    diffMode.checked = true;
                    toggleReturnMode();
                }
                setReturnProduct(product);
                return true;
            }
            return false;
        });
    }

    var params = new URLSearchParams(window.location.search);
    var pid = params.get('product_id');
    if (pid && typeof openLoanModal === 'function') {
        openLoanModal(pid);
    }
})();
</script>
<?php endif; ?>
