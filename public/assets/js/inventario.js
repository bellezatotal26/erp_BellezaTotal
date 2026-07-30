// Seri ERP - Módulo Inventario
var curSym = document.querySelector('meta[name="currency-symbol"]')?.content || '$';
var curDec = parseInt(document.querySelector('meta[name="currency-decimals"]')?.content || '0');

// Funciones del modal de producto
function openInventarioModal() {
    document.getElementById('modalTitle').textContent = 'Nuevo Producto';
    document.getElementById('productForm').action = window.invRouteCreate;
    document.getElementById('submitBtn').textContent = 'Crear';
    document.getElementById('statusGroup').style.display = 'none';
    ['prodId','prodCode','prodName','prodUnit','prodDesc'].forEach(function(id) {
        document.getElementById(id).value = '';
    });
    document.getElementById('prodType').value = 'product';
    document.getElementById('prodCat').value = '';
    document.getElementById('prodPcompra').value = '';
    document.getElementById('prodPventa').value = '';
    document.getElementById('prodStock').value = '0';
    document.getElementById('prodMinStock').value = '5';
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
    document.getElementById('prodName').value = d.name || '';
    document.getElementById('prodCat').value = d.cat || '';
    document.getElementById('prodUnit').value = d.unit || 'UNIDAD';
    document.getElementById('prodPcompra').value = d.pcompra || 0;
    document.getElementById('prodPventa').value = d.pventa || 0;
    document.getElementById('prodStock').value = d.stock || 0;
    document.getElementById('prodMinStock').value = d.minstock || 5;
    document.getElementById('prodStatus').value = d.status || 'active';
    onTypeChange();
    document.getElementById('productModal').style.display = 'flex';
}

function onTypeChange() {
    var isSvc = document.getElementById('prodType').value === 'service';
    document.getElementById('stockGroup').style.display = isSvc ? 'none' : '';
    document.getElementById('minStockGroup').style.display = isSvc ? 'none' : '';
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
});
