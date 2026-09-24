// Seri ERP - Módulo Cotizaciones
'use strict';

var quoteItems = [];
var curSym = document.querySelector('meta[name="currency-symbol"]')?.content || '$';
var curDec = parseInt(document.querySelector('meta[name="currency-decimals"]')?.content || '0', 10);
var quoteTaxEnabled = (document.querySelector('meta[name="quote-tax-enabled"]')?.content || '0') === '1';
var quoteTaxRate = parseFloat(document.querySelector('meta[name="quote-tax-rate"]')?.content || '0') || 0;

function money(n) {
    return curSym + ' ' + (Number(n) || 0).toFixed(curDec);
}

function lineTaxRate(opt) {
    if (!quoteTaxEnabled) return 0;
    var raw = opt && opt.dataset ? opt.dataset.tax : '';
    if (raw !== '' && raw != null && !isNaN(parseFloat(raw))) {
        return Math.max(0, parseFloat(raw));
    }
    return Math.max(0, quoteTaxRate);
}

function addQuoteProduct() {
    var sel = document.getElementById('productSelect');
    var qty = parseInt(document.getElementById('productQty').value, 10) || 1;
    if (!sel.value) return;
    var o = sel.options[sel.selectedIndex];
    var id = o.value, name = o.dataset.name, price = parseFloat(o.dataset.price);
    var taxRate = lineTaxRate(o);
    var ex = quoteItems.find(function(i) { return i.product_id == id; });
    if (ex) {
        ex.quantity += qty;
        ex.subtotal = ex.quantity * price;
        ex.tax_rate = taxRate;
    } else {
        quoteItems.push({
            product_id: id,
            product_name: name,
            quantity: qty,
            unit_price: price,
            subtotal: qty * price,
            tax_rate: taxRate
        });
    }
    document.getElementById('productQty').value = 1;
    renderQuoteItems();
}

function removeQuoteItem(idx) { quoteItems.splice(idx, 1); renderQuoteItems(); }

function renderQuoteItems() {
    var tb = document.querySelector('#itemsTable tbody');
    var tbl = document.getElementById('itemsTable');
    var subtotal = 0;
    var tax = 0;
    tb.innerHTML = '';
    if (quoteItems.length === 0) { tbl.style.display = 'none'; return; }
    tbl.style.display = '';
    quoteItems.forEach(function(it, i) {
        subtotal += it.subtotal;
        tax += it.subtotal * ((it.tax_rate || 0) / 100);
        tb.innerHTML += '<tr><td>' + esc(it.product_name) + '</td><td>' + it.quantity + '</td><td>' + money(it.unit_price) + '</td><td>' + money(it.subtotal) + '</td><td><button type="button" class="btn btn-sm btn-danger" onclick="removeQuoteItem(' + i + ')">X</button></td></tr>';
    });
    tax = Math.round(tax * 100) / 100;
    var total = subtotal + tax;
    var subEl = document.getElementById('quoteSubtotal');
    var taxEl = document.getElementById('quoteTax');
    if (subEl) subEl.textContent = money(subtotal);
    if (taxEl) taxEl.textContent = money(tax);
    document.getElementById('quoteTotal').textContent = money(total);
    document.getElementById('submitQuoteBtn').textContent = 'Crear Cotización (' + money(total) + ')';
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
    document.getElementById('quoteModal').style.display = 'flex';
}

function closeQuoteModal() {
    document.getElementById('quoteModal').style.display = 'none';
}

function openConvertModal(id, total) {
    document.getElementById('convertQuoteId').value = id;
    document.getElementById('convertInfo').textContent = 'Total a convertir: ' + money(total);
    document.getElementById('convertModal').style.display = 'flex';
}

function viewQuoteDetail(id) {
    var base = window.quoteRouteDetail || ((window.location.pathname || '/app/cotizaciones') + '?action=detail');
    var url = base + (base.indexOf('?') >= 0 ? '&' : '?') + 'id=' + id;
    // quoteRouteDetail already includes ?action=detail
    if (window.quoteRouteDetail) {
        url = window.quoteRouteDetail + (window.quoteRouteDetail.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(id);
    }
    fetch(url, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.error) { showAlert(d.error, 'error'); return; }
        var q = d.quote, items = d.items || [];
        var h = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">';
        h += '<div><strong>Número:</strong> ' + esc(q.quote_number) + '</div>';
        h += '<div><strong>Cliente:</strong> ' + esc(q.customer_name || 'General') + '</div>';
        h += '<div><strong>Válida hasta:</strong> ' + esc((q.valid_until || '—').toString().substr(0, 10)) + '</div>';
        h += '<div><strong>Estado:</strong> ' + esc(q.status || '') + '</div>';
        h += '<div><strong>Subtotal:</strong> ' + money(q.subtotal) + '</div>';
        h += '<div><strong>IVA:</strong> ' + money(q.tax) + '</div>';
        h += '<div><strong>Total:</strong> <span style="color:#10B981;font-size:18px;">' + money(q.total) + '</span></div>';
        h += '</div><h4>Productos</h4><table><thead><tr><th>Producto</th><th>Cant</th><th>P.Unit</th><th>Subtotal</th></tr></thead><tbody>';
        items.forEach(function(i) {
            h += '<tr><td>' + esc(i.product_name) + '</td><td>' + i.quantity + '</td><td>' + money(i.unit_price) + '</td><td>' + money(i.subtotal) + '</td></tr>';
        });
        h += '</tbody></table>';
        if (q.notes) h += '<p style="margin-top:12px;color:var(--color-text-secondary);"><strong>Notas:</strong> ' + esc(q.notes) + '</p>';
        var body = document.getElementById('quoteDetailContent');
        if (body) { body.innerHTML = h; document.getElementById('quoteDetailModal').style.display = 'flex'; }
    })
    .catch(function() { if (typeof showAlert === 'function') showAlert('No se pudo cargar el detalle', 'error'); });
}

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

document.addEventListener('DOMContentLoaded', function() {
    var scan = document.getElementById('quoteBarcodeScan');
    if (!scan) return;
    scan.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        var code = (scan.value || '').trim();
        scan.value = '';
        if (!code) return;
        var sel = document.getElementById('productSelect');
        if (!sel) return;
        for (var i = 0; i < sel.options.length; i++) {
            var opt = sel.options[i];
            if ((opt.dataset.code || '') === code || String(opt.value) === code) {
                sel.selectedIndex = i;
                addQuoteProduct();
                return;
            }
        }
        if (typeof showAlert === 'function') showAlert('Producto no encontrado: ' + code, 'warning');
    });
});
