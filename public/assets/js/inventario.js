// Seri ERP - Módulo Inventario
var curSym = document.querySelector('meta[name="currency-symbol"]')?.content || '$';
var curDec = parseInt(document.querySelector('meta[name="currency-decimals"]')?.content || '0');

// Funciones del modal de producto
function openInventarioModal() {
    document.getElementById('modalTitle').textContent = 'Nuevo Producto';
    document.getElementById('productForm').action = window.invRouteCreate;
    document.getElementById('submitBtn').textContent = 'Crear';
    document.getElementById('statusGroup').style.display = 'none';
    ['prodId','prodCode','prodBarcode','prodName','prodDesc','prodNotes'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.value = '';
    });
    setProdUnit('UND');
    document.getElementById('prodType').value = 'product';
    document.getElementById('prodCat').value = '';
    var loc = document.getElementById('prodLocation');
    if (loc) loc.value = '';
    document.getElementById('prodPcompra').value = '';
    document.getElementById('prodPventa').value = '';
    var mayoreo = document.getElementById('prodPmayoreo');
    if (mayoreo) mayoreo.value = '';
    document.getElementById('prodStock').value = '0';
    document.getElementById('prodMinStock').value = '5';
    document.getElementById('prodMaxStock').value = '';
    document.getElementById('prodTrackLots').checked = false;
    document.getElementById('prodAlertDays').value = '30';
    document.getElementById('prodExpiry').value = '';
    document.getElementById('prodLotCode').value = '';
    var sup = document.getElementById('prodSupplier');
    if (sup) sup.value = '';
    var sku = document.getElementById('prodSupplierSku');
    if (sku) sku.value = '';
    onTypeChange();
    document.getElementById('productModal').style.display = 'flex';
}

/** Asegura que el select de unidad tenga el valor (incluye legado no listado). */
function setProdUnit(unit) {
    var el = document.getElementById('prodUnit');
    if (!el) return;
    var v = String(unit || 'UND').trim() || 'UND';
    var aliases = { UNIDAD: 'UNIDAD', UND: 'UND', LT: 'LTS', LTS: 'LTS', LITRO: 'LTS', LITROS: 'LTS', M: 'MT', MTS: 'MT', METRO: 'MT', METROS: 'MT' };
    var key = v.toUpperCase();
    if (aliases[key]) v = aliases[key];
    else v = key;
    var found = false;
    for (var i = 0; i < el.options.length; i++) {
        if (el.options[i].value === v) { found = true; break; }
    }
    if (!found) {
        var opt = document.createElement('option');
        opt.value = v;
        opt.textContent = v;
        el.appendChild(opt);
    }
    el.value = v;
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
    setProdUnit(d.unit || 'UND');
    document.getElementById('prodPcompra').value = window.SeriMoney ? SeriMoney.format(d.pcompra || 0) : (d.pcompra || 0);
    document.getElementById('prodPventa').value = window.SeriMoney ? SeriMoney.format(d.pventa || 0) : (d.pventa || 0);
    var mayoreo = document.getElementById('prodPmayoreo');
    if (mayoreo) mayoreo.value = window.SeriMoney ? SeriMoney.format(d.pmayoreo || 0) : (d.pmayoreo || 0);
    document.getElementById('prodStock').value = d.stock || 0;
    document.getElementById('prodMinStock').value = d.minstock || 5;
    document.getElementById('prodStatus').value = d.status || 'active';
    document.getElementById('prodMaxStock').value = d.maxstock || '';
    document.getElementById('prodTrackLots').checked = d.tracklots === '1';
    document.getElementById('prodAlertDays').value = d.alertdays || 30;
    // El lote solo se captura en entradas de mercancía, no al editar la ficha.
    document.getElementById('prodExpiry').value = '';
    document.getElementById('prodLotCode').value = '';
    var descEl = document.getElementById('prodDesc');
    if (descEl) descEl.value = d.desc || '';
    var notesEl = document.getElementById('prodNotes');
    if (notesEl) notesEl.value = d.notes || '';
    var sup = document.getElementById('prodSupplier');
    if (sup) sup.value = d.supplierId || '';
    var sku = document.getElementById('prodSupplierSku');
    if (sku) sku.value = d.supplierSku || '';
    onTypeChange();
    document.getElementById('productModal').style.display = 'flex';
}

/** Reserva el siguiente EAN de la secuencia (Configuración → Preferencias). */
function generateProductEan() {
    var input = document.getElementById('prodBarcode');
    if (!input) return;
    if (input.value && String(input.value).trim() !== '') {
        if (!confirm('El campo EAN ya tiene un valor. ¿Reemplazarlo con el siguiente de la secuencia?')) {
            return;
        }
    }
    var fd = new FormData();
    fd.append('csrf_token', window.invCsrf || '');
    fetch(window.invRouteGenerateBarcode || (window.invRouteBase + '?action=generate_barcode'), {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d || !d.success || !d.barcode) {
            throw new Error((d && d.message) || 'No se pudo generar el EAN');
        }
        input.value = d.barcode;
        if (typeof showAlert === 'function') showAlert(d.message || ('EAN ' + d.barcode), 'success');
    })
    .catch(function(err) {
        if (typeof showAlert === 'function') showAlert(err.message || 'Error al generar EAN', 'error');
    });
}

/** Reserva el siguiente SKU de la secuencia (Configuración → Preferencias). */
function generateProductSku() {
    var input = document.getElementById('prodCode');
    if (!input) return;
    if (input.value && String(input.value).trim() !== '') {
        if (!confirm('El campo SKU ya tiene un valor. ¿Reemplazarlo con el siguiente de la secuencia?')) {
            return;
        }
    }
    var fd = new FormData();
    fd.append('csrf_token', window.invCsrf || '');
    fetch(window.invRouteGenerateSku || (window.invRouteBase + '?action=generate_sku'), {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d || !d.success || !d.sku) {
            throw new Error((d && d.message) || 'No se pudo generar el SKU');
        }
        input.value = d.sku;
        if (typeof showAlert === 'function') showAlert(d.message || ('SKU ' + d.sku), 'success');
    })
    .catch(function(err) {
        if (typeof showAlert === 'function') showAlert(err.message || 'Error al generar SKU', 'error');
    });
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
    openAddLotForProduct(id, {});
}

/** Abre ingreso de stock/remesa. Usado también cuando se intenta crear un SKU duplicado. */
function openAddLotForProduct(id, prefill) {
    prefill = prefill || {};
    var card = document.querySelector('.product-card[data-id="' + id + '"]');
    var d = card ? card.dataset : {};
    document.getElementById('stockProdId').value = id;
    var labelName = d.name || (prefill.name || '');
    document.getElementById('stockProdName').textContent = (labelName ? (labelName + ' · ') : '') + 'Lote N';
    var debtAlert = document.getElementById('stockDebtAlert');
    var debtQty = parseInt(d.debt || '0', 10) || 0;
    if (debtAlert) {
        if (debtQty > 0) {
            debtAlert.style.display = 'block';
            debtAlert.innerHTML = 'Hay <strong>' + debtQty + '</strong> uds vendidas sin stock (préstamo/cambio/pendiente). '
                + 'Al ingresar mercancía se cubrirá esa deuda automáticamente y verá el detalle en el mensaje de confirmación.';
        } else {
            debtAlert.style.display = 'none';
            debtAlert.textContent = '';
        }
    }
    var tracks = d.tracklots === '1' || !!prefill.force_lots;
    var hint = document.getElementById('stockLotHint');
    var expiry = document.getElementById('stockExpiryDate');
    if (hint) hint.style.display = tracks ? 'block' : 'none';
    if (expiry) {
        expiry.required = !!tracks;
        expiry.value = prefill.expiry_date || '';
    }
    var lotCode = document.getElementById('stockLotCode');
    if (lotCode) lotCode.value = prefill.lot_code || '';
    var qty = document.querySelector('#stockModal input[name="quantity"]');
    if (qty && prefill.quantity) {
        qty.value = prefill.quantity;
    }
    document.getElementById('stockModal').style.display = 'flex';
    if (!tracks && !d.tracklots) {
        // Sin card en DOM (p.ej. otra página de resultados): el backend activará lotes si hay expiry.
    }
}

window.openAddLotForProduct = openAddLotForProduct;

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
        if (p.notes) {
            h += '<p style="margin:0 0 12px;padding:8px 10px;background:var(--bg-input);border-radius:8px;font-size:13px;"><strong>Notas:</strong> ' + esc(p.notes) + '</p>';
        }
        var debts = d.stock_debts || [];
        if (debts.length) {
            h += '<h4>Deuda de stock (vendido sin inventario)</h4><ul style="margin:0 0 12px;padding-left:18px;">';
            debts.forEach(function(x) {
                h += '<li>' + x.qty_remaining + ' uds · ' + esc(x.reason || '') +
                    (x.invoice_number ? (' · ' + esc(x.invoice_number)) : '') +
                    (x.note ? (' — ' + esc(x.note)) : '') + '</li>';
            });
            h += '</ul>';
        }
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
        if (p.track_lots == 1 || lots.length) {
            h += '<h4 style="margin-top:15px;">Lotes / remesas (' + lots.length + ')</h4>';
            h += '<p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 8px;">Cada remesa con distinta caducidad es un lote. La caja vende solo lotes activos (FEFO).</p>';
            if (!lots.length) {
                h += '<p style="color:var(--color-text-secondary);">Sin lotes registrados. Active el control y registre una entrada con fecha de vencimiento.</p>';
            } else {
                h += '<table><thead><tr><th>Lote</th><th>Vence</th><th>Días</th><th>Cant</th><th>Costo</th><th>Estado</th></tr></thead><tbody>';
                lots.forEach(function(l) {
                    var dias = (l.days_left === null || l.days_left === undefined || l.days_left === '') ? null : parseInt(l.days_left, 10);
                    var color = '';
                    var diasTxt = '—';
                    if (dias !== null && !isNaN(dias)) {
                        diasTxt = String(dias);
                        color = dias < 0 ? '#DC2626' : (dias <= 30 ? '#F59E0B' : '#16A34A');
                    }
                    var st = String(l.status || '-');
                    var stLabel = st === 'active' ? 'Activo' : (st === 'expired' ? 'Vencido' : (st === 'blocked' ? 'Bloqueado' : (st === 'depleted' ? 'Agotado' : st)));
                    h += '<tr><td>' + esc(l.lot_code || '-') + '</td>';
                    h += '<td' + (color ? ' style="color:' + color + ';font-weight:600;"' : '') + '>' + (l.expiry_date || '—') + '</td>';
                    h += '<td' + (color ? ' style="color:' + color + ';font-weight:600;"' : '') + '>' + diasTxt + '</td>';
                    h += '<td>' + l.quantity + '</td>';
                    h += '<td>' + curSym + ' ' + parseFloat(l.unit_cost || 0).toFixed(curDec) + '</td>';
                    h += '<td>' + esc(stLabel) + '</td></tr>';
                });
                h += '</tbody></table>';
                h += '<p style="margin-top:8px;"><a href="' + (window.invRouteBase || '') + '?action=vencimientos">Ir a control de vencimientos</a></p>';
            }
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
    updateInvBulkState();
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
    updateInvBulkState();
}

/** IDs seleccionados (únicos; grid y lista comparten el mismo producto). */
function getInvSelectedIds() {
    var ids = {};
    document.querySelectorAll('.inv-product-check:checked').forEach(function (cb) {
        var id = parseInt(cb.value, 10);
        if (id > 0) ids[id] = true;
    });
    return Object.keys(ids).map(function (k) { return parseInt(k, 10); });
}

function syncInvChecks(id, checked) {
    document.querySelectorAll('.inv-product-check').forEach(function (cb) {
        if (String(cb.value) === String(id)) cb.checked = !!checked;
    });
}

function updateInvBulkState() {
    var ids = getInvSelectedIds();
    var countEl = document.getElementById('invBulkCount');
    var btn = document.getElementById('invBulkDeleteBtn');
    if (countEl) countEl.textContent = String(ids.length);
    if (btn) btn.disabled = ids.length === 0;

    var visibleChecks = [];
    var catalog = document.getElementById('invCatalog');
    var isList = catalog && catalog.classList.contains('inv-catalog--list');
    if (isList) {
        document.querySelectorAll('#invListBody .inv-list-row').forEach(function (row) {
            if (row.style.display === 'none') return;
            var cb = row.querySelector('.inv-product-check');
            if (cb) visibleChecks.push(cb);
        });
    } else {
        document.querySelectorAll('.inv-products-grid .inv-product-check').forEach(function (cb) {
            visibleChecks.push(cb);
        });
    }
    var allChecked = visibleChecks.length > 0 && visibleChecks.every(function (cb) { return cb.checked; });
    var selectAll = document.getElementById('invSelectAll');
    var selectAllList = document.getElementById('invSelectAllList');
    if (selectAll) selectAll.checked = allChecked;
    if (selectAllList) selectAllList.checked = allChecked;
}

function setInvSelectAll(checked) {
    var catalog = document.getElementById('invCatalog');
    var isList = catalog && catalog.classList.contains('inv-catalog--list');
    var seen = {};
    if (isList) {
        document.querySelectorAll('#invListBody .inv-list-row').forEach(function (row) {
            if (row.style.display === 'none') return;
            var cb = row.querySelector('.inv-product-check');
            if (!cb) return;
            cb.checked = !!checked;
            seen[cb.value] = true;
        });
    } else {
        document.querySelectorAll('.inv-products-grid .inv-product-check').forEach(function (cb) {
            cb.checked = !!checked;
            seen[cb.value] = true;
        });
    }
    // Sincronizar el otro modo de vista
    Object.keys(seen).forEach(function (id) {
        syncInvChecks(id, checked);
    });
    updateInvBulkState();
}

function submitInvBulkDelete() {
    var ids = getInvSelectedIds();
    if (!ids.length) return;
    var msg = ids.length === 1
        ? '¿Desactivar 1 producto seleccionado?'
        : '¿Desactivar ' + ids.length + ' productos seleccionados?';
    var run = function () {
        var form = document.getElementById('invBulkDeleteForm');
        var wrap = document.getElementById('invBulkIds');
        if (!form || !wrap) return;
        wrap.innerHTML = '';
        ids.forEach(function (id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ids[]';
            input.value = String(id);
            wrap.appendChild(input);
        });
        if (typeof handleAjaxSubmit === 'function') {
            handleAjaxSubmit(form);
        } else {
            form.submit();
        }
    };
    if (typeof showConfirmModal === 'function') {
        showConfirmModal(msg, run);
    } else if (window.confirm(msg)) {
        run();
    }
}

function initInvBulkSelection() {
    if (document.body._invBulkReady) return;
    document.body._invBulkReady = true;
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (!t) return;
        if (t.classList && t.classList.contains('inv-product-check')) {
            syncInvChecks(t.value, t.checked);
            updateInvBulkState();
            return;
        }
        if (t.id === 'invSelectAll' || t.id === 'invSelectAllList') {
            setInvSelectAll(t.checked);
        }
    });
    var btn = document.getElementById('invBulkDeleteBtn');
    if (btn) btn.addEventListener('click', submitInvBulkDelete);
    updateInvBulkState();
}

document.addEventListener('DOMContentLoaded', function () {
    initInvBulkSelection();
    initOfferModal();
});

function fmtOfferMoney(n) {
    if (window.SeriMoney && typeof SeriMoney.format === 'function') {
        var c = window.invCurrency || {};
        return (c.symbol || '$') + ' ' + SeriMoney.format(n);
    }
    var c = window.invCurrency || {};
    var decimals = parseInt(c.decimals, 10);
    if (isNaN(decimals)) decimals = 0;
    var num = Number(n) || 0;
    var parts = num.toFixed(decimals).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, c.thousands || '.');
    return (c.symbol || '$') + ' ' + (decimals > 0 ? parts.join(c.decimal || ',') : parts[0]);
}

function moneyVal(elOrRaw) {
    var raw = (elOrRaw && elOrRaw.value !== undefined) ? elOrRaw.value : elOrRaw;
    if (window.SeriMoney && typeof SeriMoney.parse === 'function') {
        return SeriMoney.parse(raw);
    }
    return parseFloat(raw) || 0;
}

function openOfferModal(id) {
    var card = document.querySelector('.product-card[data-id="' + id + '"]');
    var d = card ? card.dataset : {};
    var original = parseFloat(d.offer === '1' && d.offerOriginal ? d.offerOriginal : (d.pventa || 0)) || 0;
    if (original <= 0) {
        if (typeof showAlert === 'function') showAlert('El producto necesita precio de venta mayor a 0', 'error');
        return;
    }
    document.getElementById('offerProductId').value = id;
    document.getElementById('offerProductLabel').textContent =
        (d.code ? d.code + ' · ' : '') + (d.name || ('Producto #' + id));
    document.getElementById('offerOriginalValue').value = String(original);
    document.getElementById('offerOriginalDisplay').value = fmtOfferMoney(original);
    document.getElementById('offerPercent').value = '';
    var priceEl = document.getElementById('offerPrice');
    var priceVal = d.offer === '1' && d.offerPrice ? d.offerPrice : '';
    priceEl.value = priceVal !== '' && window.SeriMoney ? SeriMoney.format(priceVal) : priceVal;
    document.getElementById('offerStartsAt').value = '';
    document.getElementById('offerDuration').value = '24h';
    document.getElementById('offerEndsAt').value = '';
    document.getElementById('offerEndsWrap').style.display = 'none';
    document.getElementById('offerReason').value = d.offerReason || '';
    document.getElementById('offerReasonNote').value = '';
    document.getElementById('offerReasonNoteWrap').style.display = 'none';
    document.querySelectorAll('.offer-pct-btn').forEach(function (b) { b.classList.remove('btn-primary'); b.classList.add('btn-secondary'); });
    updateOfferPreview();
    document.getElementById('offerModal').style.display = 'flex';
}

function updateOfferPreview() {
    var original = parseFloat(document.getElementById('offerOriginalValue').value) || 0;
    var price = moneyVal(document.getElementById('offerPrice'));
    var el = document.getElementById('offerPreview');
    if (!el) return;
    if (original > 0 && price > 0 && price < original) {
        var pct = ((original - price) / original) * 100;
        el.textContent = 'Descuento: ' + pct.toFixed(1) + '% · Ahorro: ' + fmtOfferMoney(original - price);
    } else {
        el.textContent = '';
    }
}

function applyOfferPercent(pct) {
    var original = parseFloat(document.getElementById('offerOriginalValue').value) || 0;
    pct = parseFloat(pct) || 0;
    document.getElementById('offerPercent').value = pct > 0 ? String(pct) : '';
    document.querySelectorAll('.offer-pct-btn').forEach(function (b) {
        var active = parseFloat(b.getAttribute('data-pct')) === pct;
        b.classList.toggle('btn-primary', active);
        b.classList.toggle('btn-secondary', !active);
    });
    if (pct > 0 && original > 0) {
        var price = Math.round(original * (1 - pct / 100) * 100) / 100;
        var priceEl = document.getElementById('offerPrice');
        priceEl.value = window.SeriMoney ? SeriMoney.format(price) : String(price);
    }
    updateOfferPreview();
}

function initOfferModal() {
    var btns = document.getElementById('offerPercentBtns');
    if (btns && !btns._bound) {
        btns.addEventListener('click', function (e) {
            var t = e.target.closest('.offer-pct-btn');
            if (!t) return;
            applyOfferPercent(t.getAttribute('data-pct'));
        });
        btns._bound = true;
    }
    var price = document.getElementById('offerPrice');
    if (price && !price._bound) {
        price.addEventListener('input', function () {
            document.getElementById('offerPercent').value = '';
            document.querySelectorAll('.offer-pct-btn').forEach(function (b) {
                var isManual = parseFloat(b.getAttribute('data-pct')) === 0;
                b.classList.toggle('btn-primary', isManual);
                b.classList.toggle('btn-secondary', !isManual);
            });
            updateOfferPreview();
        });
        price._bound = true;
    }
    var dur = document.getElementById('offerDuration');
    if (dur && !dur._bound) {
        dur.addEventListener('change', function () {
            document.getElementById('offerEndsWrap').style.display = dur.value === 'custom' ? '' : 'none';
        });
        dur._bound = true;
    }
    var reason = document.getElementById('offerReason');
    if (reason && !reason._bound) {
        reason.addEventListener('change', function () {
            document.getElementById('offerReasonNoteWrap').style.display = reason.value === 'otro' ? '' : 'none';
        });
        reason._bound = true;
    }
}

function cancelOffer(id) {
    if (!confirm('¿Cancelar la oferta y restaurar el precio original?')) return;
    var fd = new FormData();
    fd.append('product_id', String(id));
    if (window.invCsrf) {
        fd.append('_token', window.invCsrf);
        fd.append('csrf_token', window.invCsrf);
    }
    fetch(window.invRouteOfferCancel || (window.invRouteEdit || '').replace('action=edit', 'action=offer_cancel'), {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        credentials: 'same-origin'
    }).then(function (r) { return r.json(); }).then(function (d) {
        if (d && d.success) {
            if (typeof showAlert === 'function') showAlert(d.message || 'Oferta cancelada', 'success');
            setTimeout(function () { window.location.reload(); }, 600);
        } else if (typeof showAlert === 'function') {
            showAlert((d && d.message) || 'No se pudo cancelar', 'error');
        }
    }).catch(function () {
        if (typeof showAlert === 'function') showAlert('Error de red', 'error');
    });
}