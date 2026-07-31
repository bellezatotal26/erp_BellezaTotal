// Seri ERP - Módulo Cotizaciones
'use strict';

var quoteItems = [];
var curSym = document.querySelector('meta[name="currency-symbol"]')?.content || '$';
var curDec = parseInt(document.querySelector('meta[name="currency-decimals"]')?.content || '0');

function addQuoteProduct() {
    var sel = document.getElementById('productSelect');
    var qty = parseInt(document.getElementById('productQty').value) || 1;
    if (!sel.value) return;
    var o = sel.options[sel.selectedIndex];
    var id = o.value, name = o.dataset.name, price = parseFloat(o.dataset.price);
    var ex = quoteItems.find(function(i) { return i.product_id == id; });
    if (ex) { ex.quantity += qty; ex.subtotal = ex.quantity * price; }
    else { quoteItems.push({ product_id: id, product_name: name, quantity: qty, unit_price: price, subtotal: qty * price }); }
    document.getElementById('productQty').value = 1;
    renderQuoteItems();
}

function removeQuoteItem(idx) { quoteItems.splice(idx, 1); renderQuoteItems(); }

function renderQuoteItems() {
    var tb = document.querySelector('#itemsTable tbody'), tbl = document.getElementById('itemsTable'), t = 0;
    tb.innerHTML = '';
    if (quoteItems.length === 0) { tbl.style.display = 'none'; return; }
    tbl.style.display = '';
    quoteItems.forEach(function(it, i) {
        t += it.subtotal;
        tb.innerHTML += '<tr><td>' + esc(it.product_name) + '</td><td>' + it.quantity + '</td><td>' + curSym + ' ' + it.unit_price.toFixed(curDec) + '</td><td>' + curSym + ' ' + it.subtotal.toFixed(curDec) + '</td><td><button type="button" class="btn btn-sm btn-danger" onclick="removeQuoteItem(' + i + ')">X</button></td></tr>';
    });
    document.getElementById('quoteTotal').textContent = curSym + ' ' + t.toFixed(curDec);
    document.getElementById('submitQuoteBtn').textContent = 'Crear Cotización (' + curSym + ' ' + t.toFixed(curDec) + ')';
}

function prepareQuoteItems() {
    if (quoteItems.length === 0) { showAlert('Agregue al menos un producto', 'error'); return false; }
    var f = document.querySelector('#quoteModal form');
    f.querySelectorAll('.quote-item-input').forEach(function(e) { e.remove(); });
    quoteItems.forEach(function(it, i) {
        for (var k in it) {
            var inp = document.createElement('input'); inp.type = 'hidden';
            inp.name = 'items[' + i + '][' + k + ']'; inp.value = it[k]; inp.className = 'quote-item-input';
            f.appendChild(inp);
        }
    });
    return true;
}

function openQuoteModal() {
    quoteItems = [];
    renderQuoteItems();
    if (typeof resetCustomerCombobox === 'function') resetCustomerCombobox();
    if (typeof initCustomerCombobox === 'function') initCustomerCombobox();
    document.getElementById('quoteModal').style.display = 'flex';
}
function closeQuoteModal() { document.getElementById('quoteModal').style.display = 'none'; }

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
            if (newId && typeof setSelectedCustomer === 'function') {
                setSelectedCustomer(String(newId), newName.trim());
            }
            document.getElementById('quickCustomerModal').style.display = 'none';
            form.reset();
        } else {
            showAlert(d.message || 'Error al crear cliente', 'error');
        }
    })
    .catch(function() {
        showAlert('Error de conexión', 'error');
    });
    return false;
}

function openConvertModal(id, total) {
    document.getElementById('convertQuoteId').value = id;
    document.getElementById('convertInfo').textContent = 'Total a convertir: ' + curSym + ' ' + total.toFixed(curDec);
    document.getElementById('convertModal').style.display = 'flex';
}

function toggleConvertCredit() {
    var s = document.querySelector('#convertModal select[name="payment_type"]').value === 'credit';
    document.getElementById('convertInitialGroup').style.display = s ? 'block' : 'none';
}

function viewQuoteDetail(id) {
    fetch(window.quoteRouteDetail + '&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.error) return;
        var q = d.quote, items = d.items || [];
        var h = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:15px;">';
        h += '<div><strong>Número:</strong> ' + esc(q.quote_number) + '</div>';
        h += '<div><strong>Fecha:</strong> ' + (q.quote_date || '').substr(0, 10) + '</div>';
        h += '<div><strong>Cliente:</strong> ' + esc(q.customer_name || 'General') + '</div>';
        h += '<div><strong>Válido hasta:</strong> ' + (q.valid_until || '').substr(0, 10) + '</div>';
        h += '<div><strong>Estado:</strong> ' + q.status + '</div>';
        h += '<div><strong>Total:</strong> <span style="color:#10B981;font-size:18px;">' + curSym + ' ' + parseFloat(q.total).toFixed(curDec) + '</span></div>';
        h += '</div><h4>Productos</h4><table><thead><tr><th>Producto</th><th>Cant</th><th>P.Unit</th><th>Subtotal</th></tr></thead><tbody>';
        items.forEach(function(i) { h += '<tr><td>' + esc(i.product_name) + '</td><td>' + i.quantity + '</td><td>' + curSym + ' ' + parseFloat(i.unit_price).toFixed(curDec) + '</td><td>' + curSym + ' ' + parseFloat(i.subtotal).toFixed(curDec) + '</td></tr>'; });
        h += '</tbody></table>';
        document.getElementById('quoteDetailContent').innerHTML = h;
        document.getElementById('quoteDetailModal').style.display = 'flex';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var scan = document.getElementById('quoteBarcodeScan');
    if (!scan) return;
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
            var opt = null;
            Array.prototype.some.call(sel.options, function(o) {
                if (String(o.value) === String(p.id)) { opt = o; return true; }
                return false;
            });
            if (!opt) {
                opt = document.createElement('option');
                opt.value = p.id;
                opt.setAttribute('data-price', p.sale_price);
                opt.setAttribute('data-name', p.name);
                opt.setAttribute('data-code', p.code || code);
                opt.textContent = (p.code ? p.code + ' — ' : '') + p.name;
                sel.appendChild(opt);
            }
            sel.value = opt.value;
            var qty = document.getElementById('productQty');
            if (qty) qty.value = '1';
            addQuoteProduct();
        });
    });
});
