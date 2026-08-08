// Seri ERP - Módulo Inventario
var curSym = document.querySelector('meta[name="currency-symbol"]')?.content || '$';
var curDec = parseInt(document.querySelector('meta[name="currency-decimals"]')?.content || '0');

// Funciones del modal de producto
function openInventarioModal() {
    document.getElementById('modalTitle').textContent = 'Nuevo Producto';
    document.getElementById('productForm').action = window.invRouteCreate;
    document.getElementById('submitBtn').textContent = 'Crear';
    document.getElementById('statusGroup').style.display = 'none';
    ['prodId','prodCode','prodBarcode','prodName','prodUnit','prodDesc'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.value = '';
    });
    document.getElementById('prodType').value = 'product';
    document.getElementById('prodCat').value = '';
    var loc = document.getElementById('prodLocation');
    if (loc) loc.value = '';
    document.getElementById('prodPcompra').value = '';
    document.getElementById('prodPventa').value = '';
    document.getElementById('prodStock').value = '0';
    document.getElementById('prodMinStock').value = '5';
    document.getElementById('prodMaxStock').value = '';
    document.getElementById('prodTrackLots').checked = false;
    document.getElementById('prodAlertDays').value = '30';
    document.getElementById('prodExpiry').value = '';
    document.getElementById('prodLotCode').value = '';
    onTypeChange();
    document.getElementById('productModal').style.display = 'flex';
}

function editProduct(id) {
    var card = document.querySelector('.product-card[data-id="' + id + '"]');
    var d = card ? card.dataset : {};
    document.getElementById('modalTitle').textContent = 'Editar Producto';
    document.getElementById('productForm').action = window.invRouteEdit;
    document.getElementById('submitBtn').textContent = 'Guardar';
    document.getElementById('statusGroup').style.display = '';
    document.getElementById('prodId').value = d.id || '';
    document.getElementById('prodType').value = d.type || 'product';
    document.getElementById('prodCode').value = d.code || '';
    var barcodeEl = document.getElementById('prodBarcode');
    if (barcodeEl) barcodeEl.value = d.barcode || '';
    document.getElementById('prodName').value = d.name || '';
    document.getElementById('prodCat').value = d.cat || '';
    var loc = document.getElementById('prodLocation');
    if (loc) loc.value = d.location || '';
    document.getElementById('prodUnit').value = d.unit || 'UNIDAD';
    document.getElementById('prodPcompra').value = d.pcompra || 0;
    document.getElementById('prodPventa').value = d.pventa || 0;
    document.getElementById('prodStock').value = d.stock || 0;
    document.getElementById('prodMinStock').value = d.minstock || 5;
    document.getElementById('prodStatus').value = d.status || 'active';
    document.getElementById('prodMaxStock').value = d.maxstock || '';
    document.getElementById('prodTrackLots').checked = d.tracklots === '1';
    document.getElementById('prodAlertDays').value = d.alertdays || 30;
    // El lote solo se captura en entradas de mercancía, no al editar la ficha.
    document.getElementById('prodExpiry').value = '';
    document.getElementById('prodLotCode').value = '';
    onTypeChange();
    document.getElementById('productModal').style.display = 'flex';
}

function onTypeChange() {
    var isSvc = document.getElementById('prodType').value === 'service';
    document.getElementById('stockGroup').style.display = isSvc ? 'none' : '';
    document.getElementById('minStockGroup').style.display = isSvc ? 'none' : '';
    document.getElementById('maxStockGroup').style.display = isSvc ? 'none' : '';
    document.getElementById('trackLotsGroup').style.display = isSvc ? 'none' : '';
    onTrackLotsChange();
}

function onTrackLotsChange() {
    var isSvc = document.getElementById('prodType').value === 'service';
    var on = !isSvc && document.getElementById('prodTrackLots').checked;
    var isNew = !document.getElementById('prodId').value;
    document.querySelectorAll('.lot-field').forEach(function(el) {
        // El lote inicial solo tiene sentido al crear el producto con stock.
        var initialOnly = el.id === 'expiryGroup' || el.id === 'lotCodeGroup';
        el.style.display = (on && (isNew || !initialOnly)) ? '' : 'none';
    });
}

function closeInvModal() {
    document.getElementById('productModal').style.display = 'none';
}

function addStock(id) {
    var card = document.querySelector('.product-card[data-id="' + id + '"]');
    var d = card ? card.dataset : {};
    document.getElementById('stockProdId').value = id;
    document.getElementById('stockProdName').textContent = '📦 ' + (d.name || '');
    document.getElementById('stockModal').style.display = 'flex';
}

function viewDetail(id) {
    fetch(window.invRouteDetail + '&id=' + id, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.error) return showAlert(d.error, 'error');
        var p = d.product, m = d.movements || [], s = d.lastSales || [];
        var isSvc = p.product_type === 'service';
        var h = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:15px;">';
        h += '<div><strong>Nombre:</strong> ' + esc(p.name) + '</div>';
        h += '<div><strong>Tipo:</strong> ' + (isSvc ? 'Servicio' : 'Producto') + '</div>';
        h += '<div><strong>Código:</strong> ' + esc(p.code || '-') + '</div>';
        h += '<div><strong>Categoría:</strong> ' + esc(p.category_name || '-') + '</div>';
        h += '<div><strong>Ubicación:</strong> ' + esc(p.location || '-') + '</div>';
        h += '<div><strong>P. Venta:</strong> $' + parseFloat(p.sale_price).toFixed(0) + '</div>';
        h += '<div><strong>Stock:</strong> ' + (isSvc ? 'N/A' : p.stock) + '</div>';
        h += '<div><strong>Creado:</strong> ' + (p.created_at || '').substr(0, 10) + '</div>';
        h += '<div><strong>Última Venta:</strong> ' + ((p.last_sale_date || '').substr(0, 10) || 'Nunca') + '</div>';
        h += '</div>';
        h += '<h4>📊 Movimientos (' + m.length + ')</h4>';
        if (m.length === 0) {
            h += '<p style="text-align:center;color:var(--color-text-secondary);">Sin movimientos</p>';
        } else {
            h += '<table><thead><tr><th>Fecha</th><th>Tipo</th><th>Cant</th><th>Notas</th></tr></thead><tbody>';
            m.forEach(function(x) {
                var t = x.type === 'in' ? 'Entrada' : (x.type === 'out' ? 'Salida' : 'Ajuste');
                var bc = x.type === 'in' ? 'badge-success' : (x.type === 'out' ? 'badge-danger' : 'badge-warning');
                h += '<tr><td>' + (x.created_at || '').substr(0, 16) + '</td>';
                h += '<td><span class="badge ' + bc + '">' + t + '</span></td>';
                h += '<td>' + x.quantity + '</td><td>' + esc(x.notes || '-') + '</td></tr>';
            });
            h += '</tbody></table>';
        }
        var sups = d.suppliers || [];
        h += '<h4 style="margin-top:15px;">🚚 Proveedores (' + sups.length + ')</h4>';
        if (!sups.length) {
            h += '<p style="text-align:center;color:var(--color-text-secondary);">Sin proveedores vinculados</p>';
        } else {
            h += '<table><thead><tr><th>Proveedor</th><th>SKU proveedor</th><th>Último costo</th><th>Entrega</th><th>Mín. pedido</th><th></th></tr></thead><tbody>';
            sups.forEach(function(s) {
                h += '<tr><td>' + esc(s.supplier_name) + (s.is_primary === '1' || s.is_primary === 1 ? ' <span class="badge badge-success">Principal</span>' : '') + '</td>';
                h += '<td>' + esc(s.supplier_sku || '—') + '</td>';
                h += '<td>' + curSym + ' ' + parseFloat(s.last_cost || 0).toFixed(curDec) + '</td>';
                h += '<td>' + (parseInt(s.lead_time_days, 10) || 0) + ' d</td>';
                h += '<td>' + (parseInt(s.min_order_qty, 10) || 1) + '</td>';
                h += '<td><button type="button" class="btn btn-sm btn-danger" onclick="unlinkSupplier(' + p.id + ',' + s.supplier_id + ')">Quitar</button></td></tr>';
            });
            h += '</tbody></table>';
        }
        if (window.invCanEdit) {
            h += '<button type="button" class="btn btn-sm btn-secondary" style="margin-top:8px;" onclick="openSupplierLink(' + p.id + ', ' + JSON.stringify(esc(p.name)) + ')">Vincular proveedor</button>';
        }

        var lots = d.lots || [];
        if (lots.length) {
            h += '<h4 style="margin-top:15px;">🏷️ Lotes (' + lots.length + ')</h4>';
            h += '<table><thead><tr><th>Lote</th><th>Vence</th><th>Cant</th><th>Costo</th><th>Estado</th></tr></thead><tbody>';
            lots.forEach(function(l) {
                var dias = l.days_left === null ? null : parseInt(l.days_left, 10);
                var color = dias === null ? '' : (dias < 0 ? '#DC2626' : (dias <= 30 ? '#F59E0B' : ''));
                h += '<tr><td>' + esc(l.lot_code || '-') + '</td>';
                h += '<td' + (color ? ' style="color:' + color + ';font-weight:600;"' : '') + '>' + (l.expiry_date || '—') + '</td>';
                h += '<td>' + l.quantity + '</td>';
                h += '<td>' + curSym + ' ' + parseFloat(l.unit_cost || 0).toFixed(curDec) + '</td>';
                h += '<td>' + esc(l.status || '-') + '</td></tr>';
            });
            h += '</tbody></table>';
        }
        h += '<h4 style="margin-top:15px;">🛒 Últimas Ventas</h4>';
        if (s.length === 0) {
            h += '<p style="text-align:center;color:var(--color-text-secondary);">Sin ventas</p>';
        } else {
            h += '<table><thead><tr><th>Factura</th><th>Fecha</th><th>Cant</th></tr></thead><tbody>';
            s.forEach(function(x) {
                h += '<tr><td>' + esc(x.invoice_number) + '</td>';
                h += '<td>' + (x.sale_date || '').substr(0, 16) + '</td>';
                h += '<td>' + x.quantity + '</td></tr>';
            });
            h += '</tbody></table>';
        }
        document.getElementById('detailContent').innerHTML = h;
        document.getElementById('detailModal').style.display = 'flex';
    });
}

function openSupplierLink(productId, productName) {
    document.getElementById('psProductId').value = productId;
    document.getElementById('psProductName').textContent = productName;
    document.getElementById('psForm').reset();
    document.getElementById('psProductId').value = productId;
    document.getElementById('productSupplierModal').style.display = 'flex';
}

function closeSupplierLink() {
    document.getElementById('productSupplierModal').style.display = 'none';
}

function unlinkSupplier(productId, supplierId) {
    if (!confirm('¿Quitar este proveedor del producto?')) return;
    var form = document.getElementById('psUnlinkForm');
    form.querySelector('[name="product_id"]').value = productId;
    form.querySelector('[name="supplier_id"]').value = supplierId;
    form.submit();
}

function openImageLightbox(src, name) {
    var o = document.createElement('div');
    o.className = 'modal-overlay';
    o.style.display = 'flex';
    o.style.zIndex = '9999';
    o.onclick = function() { o.remove(); };
    o.innerHTML = '<div style="position:relative;max-width:80vw;max-height:80vh;" onclick="event.stopPropagation()">' +
        '<img src="' + src + '" style="max-width:100%;max-height:80vh;border-radius:16px;box-shadow:0 25px 50px rgba(0,0,0,0.3);">' +
        '<div style="position:absolute;bottom:-40px;left:0;right:0;text-align:center;color:#fff;font-size:16px;font-weight:600;">' + esc(name) + '</div>' +
        '<button onclick="this.parentElement.parentElement.remove()" style="position:absolute;top:-15px;right:-15px;width:36px;height:36px;background:#DC2626;color:#fff;border:none;border-radius:50%;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,0.3);">\u2715</button></div>';
    document.body.appendChild(o);
}

// ============================================
// Carrito de Compras
// ============================================
var cart = JSON.parse(localStorage.getItem('eva_cart') || '[]');

function addToCart(id, name, price, evt) {
    var ex = cart.find(function(i) { return i.id == id; });
    if (ex) { ex.qty++; }
    else { cart.push({ id: id, name: name, price: price, qty: 1 }); }
    saveCart();
    showAlert(name + ' agregado al carrito', 'success');
    var fromEl = (evt && evt.currentTarget) ? evt.currentTarget : (typeof event !== 'undefined' ? event.target : null);
    if (typeof animateAddToCart === 'function') {
        animateAddToCart(fromEl && fromEl.closest ? (fromEl.closest('.btn') || fromEl) : fromEl);
    }
}

function saveCart() {
    localStorage.setItem('eva_cart', JSON.stringify(cart));
    updateCartBadge();
}

function updateCartBadge() {
    var b = document.getElementById('cartBadge');
    if (!b) return;
    var total = cart.reduce(function(a, i) { return a + i.qty; }, 0);
    b.textContent = total;
    b.style.display = cart.length ? 'flex' : 'none';
}

function clearCart() {
    cart = [];
    saveCart();
}

function showCartModal() {
    if (cart.length === 0) {
        showAlert('Carrito vacío', 'warning');
        return;
    }
    var h = '<div style="max-height:50vh;overflow-y:auto;">';
    h += '<table style="width:100%;"><thead><tr><th>Producto</th><th>Precio</th><th>Cant</th><th>Subtotal</th><th></th></tr></thead><tbody>';
    var total = 0;
    cart.forEach(function(it, i) {
        var sub = it.price * it.qty;
        total += sub;
        h += '<tr>';
        h += '<td>' + esc(it.name) + '</td>';
        h += '<td>' + curSym + ' ' + it.price.toFixed(curDec) + '</td>';
        h += '<td><input type="number" value="' + it.qty + '" min="1" style="width:60px;" onchange="updateCartQty(' + i + ',this.value)"></td>';
        h += '<td>' + curSym + ' ' + sub.toFixed(curDec) + '</td>';
        h += '<td><button class="btn btn-sm btn-danger" onclick="removeCartItem(' + i + ')">✕</button></td>';
        h += '</tr>';
    });
    h += '</tbody></table></div>';
    h += '<p style="margin-top:15px;font-size:18px;font-weight:700;text-align:right;">Total: ' + curSym + ' ' + total.toFixed(curDec) + '</p>';
    h += '<div style="display:flex;gap:10px;margin-top:15px;">';
    h += '<button class="btn btn-secondary" onclick="clearCart();closeCartModal();">Vaciar</button>';
    h += '<button class="btn btn-secondary" onclick="closeCartModal()">Cerrar</button>';
    h += '<button class="btn btn-primary" style="flex:1;" onclick="checkoutCart()">Realizar Venta</button>';
    h += '</div>';
    document.getElementById('cartContent').innerHTML = h;
    document.getElementById('cartModal').style.display = 'flex';
}

function closeCartModal() {
    document.getElementById('cartModal').style.display = 'none';
}

function updateCartQty(i, q) {
    var n = parseInt(q);
    if (n < 1) return;
    cart[i].qty = n;
    saveCart();
    showCartModal();
}

function removeCartItem(i) {
    cart.splice(i, 1);
    saveCart();
    if (cart.length === 0) { closeCartModal(); }
    else { showCartModal(); }
}

function checkoutCart() {
    closeCartModal();
    window.location = window.invRouteVentas + '?fromCart=1';
}

document.addEventListener('DOMContentLoaded', function() {
    updateCartBadge();
    var savedView = 'grid';
    try {
        savedView = localStorage.getItem('seri_inv_view_mode') || 'grid';
    } catch (e) {}
    if (typeof setInventarioView === 'function') {
        setInventarioView(savedView === 'list' ? 'list' : 'grid', true);
    }
    initInvListColumnFilters();
});

function setInventarioView(mode, skipSave) {
    var catalog = document.getElementById('invCatalog');
    if (!catalog) return;
    var isList = mode === 'list';
    catalog.classList.toggle('inv-catalog--list', isList);
    catalog.classList.toggle('inv-catalog--grid', !isList);
    var gridBtn = document.getElementById('invViewGridBtn');
    var listBtn = document.getElementById('invViewListBtn');
    if (gridBtn) gridBtn.classList.toggle('is-active', !isList);
    if (listBtn) listBtn.classList.toggle('is-active', isList);
    if (!skipSave) {
        try { localStorage.setItem('seri_inv_view_mode', isList ? 'list' : 'grid'); } catch (e) {}
    }
}

function initInvListColumnFilters() {
    var table = document.getElementById('invListTable');
    if (!table || table._filtersReady) return;
    table._filtersReady = true;
    var filters = table.querySelectorAll('.inv-col-filter');
    Array.prototype.forEach.call(filters, function (el) {
        el.addEventListener('input', applyInvListFilters);
        el.addEventListener('change', applyInvListFilters);
        el.addEventListener('keyup', applyInvListFilters);
    });
}

function clearInvListFilters() {
    var table = document.getElementById('invListTable');
    if (!table) return;
    Array.prototype.forEach.call(table.querySelectorAll('.inv-col-filter'), function (el) {
        el.value = '';
    });
    applyInvListFilters();
}

function applyInvListFilters() {
    var table = document.getElementById('invListTable');
    var body = document.getElementById('invListBody');
    if (!table || !body) return;

    var vals = {};
    Array.prototype.forEach.call(table.querySelectorAll('.inv-col-filter'), function (el) {
        var col = el.getAttribute('data-col');
        vals[col] = String(el.value || '').toLowerCase().trim();
    });

    var rows = body.querySelectorAll('.inv-list-row');
    var visible = 0;
    Array.prototype.forEach.call(rows, function (row) {
        var ok = true;
        Object.keys(vals).forEach(function (col) {
            if (!ok || !vals[col]) return;
            var cell = String(row.getAttribute('data-' + col) || '').toLowerCase();
            if (col === 'type') {
                if (cell !== vals[col]) ok = false;
                return;
            }
            if (cell.indexOf(vals[col]) === -1) ok = false;
        });
        row.style.display = ok ? '' : 'none';
        if (ok) visible++;
    });

    var countEl = document.getElementById('invListFilterCount');
    if (countEl) countEl.textContent = visible + ' / ' + rows.length + ' filas';
    var emptyEl = document.getElementById('invListEmpty');
    if (emptyEl) emptyEl.hidden = visible > 0;
}
