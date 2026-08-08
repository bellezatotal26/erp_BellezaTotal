// Seri ERP - Módulo Ventas
var saleItems = [];
var curSym = document.querySelector('meta[name="currency-symbol"]')?.content || '$';
var curDec = parseInt(document.querySelector('meta[name="currency-decimals"]')?.content || '0');

function openCancelSaleModal(id, invoice) {
    var modal = document.getElementById('cancelSaleModal');
    var idEl = document.getElementById('cancelSaleId');
    var invEl = document.getElementById('cancelSaleInvoice');
    var sel = document.getElementById('cancelReasonSelect');
    var other = document.getElementById('cancelReasonOther');
    if (!modal || !idEl) return;
    idEl.value = String(id);
    if (invEl) invEl.textContent = invoice || ('#' + id);
    if (sel) sel.value = '';
    if (other) other.value = '';
    modal.style.display = 'flex';
    if (window.SoftNovaCustomSelect && sel) SoftNovaCustomSelect.bind(sel);
}

function closeCancelSaleModal() {
    var modal = document.getElementById('cancelSaleModal');
    if (modal) modal.style.display = 'none';
}

function prepareCancelSale() {
    var sel = document.getElementById('cancelReasonSelect');
    var other = document.getElementById('cancelReasonOther');
    if (!sel || !sel.value) {
        if (typeof showAlert === 'function') showAlert('Seleccione el motivo de eliminación', 'warning');
        return false;
    }
    if (window.SoftNovaCustomSelect && SoftNovaCustomSelect.isOther(sel.value)) {
        if (typeof showAlert === 'function') showAlert('Complete la opción "Otro" o elija un motivo de la lista', 'warning');
        return false;
    }
    if (other) other.value = '';
    return true;
}

function toggleCredit() {
    var s = document.getElementById('paymentType').value === 'credit';
    document.getElementById('initialPaymentGroup').style.display = s ? 'block' : 'none';
    var terms = document.getElementById('paymentTerms');
    if (terms && s && terms.value === 'cash') {
        terms.value = 'net_30';
        onPaymentTermsChange();
    }
}

function onPaymentTermsChange() {
    var terms = document.getElementById('paymentTerms');
    var saleDate = document.getElementById('saleDate');
    var due = document.getElementById('dueDate');
    if (!terms || !saleDate || !due) return;
    var base = saleDate.value || new Date().toISOString().slice(0,10);
    var d = new Date(base + 'T12:00:00');
    if (terms.value === 'cash') { /* same day */ }
    else if (terms.value === 'net_15') d.setDate(d.getDate() + 15);
    else if (terms.value === 'net_30') d.setDate(d.getDate() + 30);
    else if (terms.value === 'overdue') d.setDate(d.getDate() - 1);
    due.value = d.toISOString().slice(0,10);
}

function shareSale(id) {
    fetch(window.invRouteVentasShare + '&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) return showAlert(d.message || 'Error', 'error');
            document.getElementById('shareSaleId').value = id;
            document.getElementById('shareEmail').value = d.customer_email || '';
            var btn = document.getElementById('shareWhatsappBtn');
            btn.href = d.whatsapp_url;
            document.getElementById('shareModal').style.display = 'flex';
        });
}

function addProduct(ev) {
    var sel = document.getElementById('productSelect');
    var qty = parseInt(document.getElementById('productQty').value) || 1;
    if (!sel.value) return;
    var o = sel.options[sel.selectedIndex];
    var id = o.value, name = o.dataset.name, price = parseFloat(o.dataset.price), stock = parseInt(o.dataset.stock, 10);
    if (isNaN(stock)) stock = 0;
    if (qty > stock) return showAlert('Stock insuficiente. Disponible: ' + stock, 'error');
    var ex = saleItems.find(function(i) { return i.product_id == id; });
    if (ex) {
        if (ex.quantity + qty > stock) return showAlert('Stock insuficiente. Disponible: ' + stock + ' (ya lleva ' + ex.quantity + ' en la venta)', 'error');
        ex.quantity += qty;
        ex.subtotal = ex.quantity * price;
        ex.stock = stock;
    }
    else { saleItems.push({ product_id: id, product_name: name, quantity: qty, unit_price: price, subtotal: qty * price, stock: stock }); }
    document.getElementById('productQty').value = 1;
    renderItems();
    updateStockHint();
    var remaining = stock - (ex ? ex.quantity : qty);
    var level = remaining <= 0 ? 'error' : (remaining <= 5 ? 'warning' : 'info');
    showAlert(name + ' agregado. Restante en bodega: ' + remaining, level);
    if (typeof animateAddToCart === 'function') {
        var fromEl = (ev && ev.target) ? ev.target : document.getElementById('addProductBtn');
        animateAddToCart(fromEl && fromEl.closest ? (fromEl.closest('.btn') || fromEl) : fromEl);
    }
}

function updateStockHint() {
    var hint = document.getElementById('productStockHint');
    var sel = document.getElementById('productSelect');
    if (!hint || !sel) return;
    if (!sel.value) { hint.textContent = ''; return; }
    var o = sel.options[sel.selectedIndex];
    var stock = parseInt(o.dataset.stock, 10) || 0;
    var id = o.value;
    var inSale = 0;
    var ex = saleItems.find(function(i) { return String(i.product_id) === String(id); });
    if (ex) inSale = ex.quantity;
    var remaining = stock - inSale;
    hint.innerHTML = 'Stock disponible: <strong>' + stock + '</strong>' +
        (inSale > 0 ? ' · En esta venta: <strong>' + inSale + '</strong> · Restante: <strong style="color:' + (remaining <= 0 ? '#DC2626' : '#059669') + ';">' + remaining + '</strong>' : '');
}

function removeItem(idx) { saleItems.splice(idx, 1); renderItems(); updateStockHint(); }

function renderItems() {
    var tb = document.querySelector('#itemsTable tbody');
    var tbl = document.getElementById('itemsTable');
    var t = 0;
    tb.innerHTML = '';
    if (saleItems.length === 0) { tbl.style.display = 'none'; return; }
    tbl.style.display = '';
    saleItems.forEach(function(it, i) {
        t += it.subtotal;
        var stock = parseInt(it.stock, 10);
        if (isNaN(stock)) {
            var opt = document.querySelector('#productSelect option[value="' + it.product_id + '"]');
            stock = opt ? (parseInt(opt.dataset.stock, 10) || 0) : 0;
            it.stock = stock;
        }
        var remaining = stock - it.quantity;
        var remColor = remaining <= 0 ? '#DC2626' : (remaining <= 5 ? '#B45309' : '#059669');
        tb.innerHTML += '<tr>' +
            '<td>' + esc(it.product_name) + '</td>' +
            '<td>' + it.quantity + '</td>' +
            '<td>' + curSym + ' ' + it.unit_price.toFixed(curDec) + '</td>' +
            '<td style="font-weight:600;color:' + remColor + ';">' + remaining + ' <small style="font-weight:400;color:var(--color-text-secondary);">/ ' + stock + '</small></td>' +
            '<td>' + curSym + ' ' + it.subtotal.toFixed(curDec) + '</td>' +
            '<td><button type="button" class="btn btn-sm btn-danger" onclick="removeItem(' + i + ')">X</button></td>' +
            '</tr>';
    });
    document.getElementById('saleTotal').textContent = curSym + ' ' + t.toFixed(curDec);
    document.getElementById('submitSaleBtn').textContent = 'Completar Venta (' + curSym + ' ' + t.toFixed(curDec) + ')';
}

function prepareSaleItems() {
    if (saleItems.length === 0) { showAlert('Agregue al menos un producto', 'error'); return false; }
    var f = document.querySelector('#saleModal form');
    f.querySelectorAll('.sale-item-input').forEach(function(e) { e.remove(); });
    saleItems.forEach(function(it, i) {
        for (var k in it) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'items[' + i + '][' + k + ']';
            inp.value = it[k];
            inp.className = 'sale-item-input';
            f.appendChild(inp);
        }
    });
    return true;
}

function openSaleModal() {
    saleItems = [];
    var fromCart = window.location.search.indexOf('fromCart=1') > -1;
    if (fromCart) {
        var cartData = JSON.parse(localStorage.getItem('eva_cart') || '[]');
        cartData.forEach(function(it) {
            var opt = document.querySelector('#productSelect option[value="' + it.id + '"]');
            var stock = opt ? (parseInt(opt.dataset.stock, 10) || 0) : 0;
            saleItems.push({ product_id: it.id, product_name: it.name, quantity: it.qty, unit_price: it.price, subtotal: it.price * it.qty, stock: stock });
        });
        localStorage.removeItem('eva_cart');
    }
    renderItems();
    updateStockHint();
    toggleCredit();
    resetCustomerCombobox();
    initCustomerCombobox();
    var sel = document.getElementById('productSelect');
    if (sel && !sel._stockHintBound) {
        sel.addEventListener('change', updateStockHint);
        sel._stockHintBound = true;
    }
    document.getElementById('saleModal').style.display = 'flex';
}

function closeSaleModal() { document.getElementById('saleModal').style.display = 'none'; }

function quickAddCustomer() { document.getElementById('quickCustomerModal').style.display = 'flex'; }

function closeQuickCustomer() { document.getElementById('quickCustomerModal').style.display = 'none'; }

function submitQuickCustomer(form) {
    var fd = new FormData(form);
    fetch(form.action, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            showAlert(d.message, 'success');
            var newId = d.data ? d.data.id : null;
            var firstName = fd.get('first_name') || '';
            var lastName = fd.get('last_name') || '';
            var newName = (d.data && d.data.name) ? d.data.name : ((firstName + ' ' + lastName).trim() || 'Nuevo Cliente');
            if (newId) {
                if (typeof setSelectedCustomer === 'function' && document.getElementById('customerId')) {
                    setSelectedCustomer(String(newId), newName.trim());
                }
                if (typeof setPosCustomer === 'function') {
                    setPosCustomer(String(newId), newName.trim());
                } else {
                    var posSel = document.getElementById('posCustomer');
                    if (posSel && posSel.tagName === 'SELECT') {
                        var opt = document.createElement('option');
                        opt.value = String(newId);
                        opt.textContent = newName.trim();
                        opt.selected = true;
                        posSel.appendChild(opt);
                        posSel.value = String(newId);
                    }
                }
            }
            document.getElementById('quickCustomerModal').style.display = 'none';
            form.reset();
        } else {
            showAlert(d.message, 'error');
        }
    })
    .catch(function() {
        showAlert('Error de conexión', 'error');
    });
    return false;
}

function openPaymentModal(id, total, paid) {
    document.getElementById('paySaleId').value = id;
    document.getElementById('payAmount').value = (total - paid).toFixed(curDec);
    document.getElementById('payInfo').textContent = 'Total: ' + curSym + ' ' + total.toFixed(curDec) + ' | Pagado: ' + curSym + ' ' + paid.toFixed(curDec) + ' | Pendiente: ' + curSym + ' ' + (total - paid).toFixed(curDec);
    document.getElementById('paymentModal').style.display = 'flex';
}

function viewDetail(id) {
    fetch(window.invRouteVentasDetail + '&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.error) return;
        var s = d.sale;
        var h = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:15px;">';
        h += '<div><strong>Factura:</strong> ' + esc(s.invoice_number) + '</div>';
        h += '<div><strong>Fecha:</strong> ' + (s.sale_date || '').substr(0, 16) + '</div>';
        h += '<div><strong>Cliente:</strong> ' + esc(s.customer_name || 'General') + '</div>';
        h += '<div><strong>Estado:</strong> ' + s.payment_status + '</div>';
        h += '<div><strong>Total:</strong> <span style="color:#10B981;font-size:18px;">' + curSym + ' ' + parseFloat(s.total).toFixed(curDec) + '</span></div>';
        h += '</div>';
        if (d.items) {
            h += '<h4>Productos</h4><table><thead><tr><th>Producto</th><th>Cant</th><th>P.Unit</th><th>Subtotal</th></tr></thead><tbody>';
            d.items.forEach(function(i) {
                h += '<tr><td>' + esc(i.product_name) + '</td><td>' + i.quantity + '</td><td>' + curSym + ' ' + parseFloat(i.unit_price).toFixed(curDec) + '</td><td>' + curSym + ' ' + parseFloat(i.subtotal).toFixed(curDec) + '</td></tr>';
            });
            h += '</tbody></table>';
        }
        if (d.payments && d.payments.length > 0) {
            h += '<h4>Abonos</h4><table><thead><tr><th>Fecha</th><th>Monto</th><th>Método</th></tr></thead><tbody>';
            d.payments.forEach(function(p) {
                h += '<tr><td>' + (p.payment_date || '').substr(0, 16) + '</td><td>' + curSym + ' ' + parseFloat(p.amount).toFixed(curDec) + '</td><td>' + esc(p.payment_method) + '</td></tr>';
            });
            h += '</tbody></table>';
        }
        document.getElementById('saleDetailContent').innerHTML = h;
        document.getElementById('saleDetailModal').style.display = 'flex';
    });
}

function printInvoice(id) {
    viewDetail(id);
    setTimeout(function() { window.print(); }, 500);
}

document.addEventListener('DOMContentLoaded', function() {
    initCustomerCombobox();
    if (window.location.search.indexOf('fromCart=1') > -1) {
        openSaleModal();
    }
    var scan = document.getElementById('saleBarcodeScan');
    if (scan) {
        scan.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            var code = (scan.value || '').trim();
            if (!code || !window.SoftNovaBarcode) return;
            SoftNovaBarcode.lookup(code).then(function(d) {
                scan.value = '';
                if (!d || !d.success || !d.product) {
                    showAlert('Producto no encontrado: ' + code, 'warning');
                    return;
                }
                var p = d.product;
                var sel = document.getElementById('productSelect');
                if (!sel) return;
                var opt = sel.querySelector('option[value="' + p.id + '"]');
                if (!opt) {
                    opt = document.createElement('option');
                    opt.value = p.id;
                    opt.setAttribute('data-price', p.sale_price);
                    opt.setAttribute('data-name', p.name);
                    opt.setAttribute('data-stock', p.stock);
                    opt.setAttribute('data-code', p.code || code);
                    opt.textContent = (p.code ? p.code + ' — ' : '') + p.name;
                    sel.appendChild(opt);
                }
                sel.value = opt.value;
                var qty = document.getElementById('productQty');
                if (qty) qty.value = '1';
                addProduct({ target: document.getElementById('addProductBtn') || sel });
            });
        });
    }
});
