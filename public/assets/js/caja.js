// Seri ERP - Caja + POS rápido
'use strict';

(function () {
    var panel = document.getElementById('posPanel');
    if (!panel) return;

    var searchUrl = panel.getAttribute('data-search-url');
    var saleUrl = panel.getAttribute('data-sale-url');
    var csrf = panel.getAttribute('data-csrf');
    var symbol = panel.getAttribute('data-symbol') || '$';
    var decimals = parseInt(panel.getAttribute('data-decimals') || '0', 10);
    var prefix = panel.getAttribute('data-prefix') || 'FAC-';

    var OFFLINE_KEY = 'seri_pos_pending_sales';

    function loadPendingQueue() {
        try {
            var raw = localStorage.getItem(OFFLINE_KEY);
            var arr = raw ? JSON.parse(raw) : [];
            return Array.isArray(arr) ? arr : [];
        } catch (e) {
            return [];
        }
    }

    function savePendingQueue(arr) {
        localStorage.setItem(OFFLINE_KEY, JSON.stringify(arr || []));
        updateOfflineBanner();
    }

    function updateOfflineBanner() {
        var banner = document.getElementById('posOfflineBanner');
        if (!banner) return;
        var n = loadPendingQueue().length;
        if (n <= 0) {
            banner.hidden = true;
            banner.style.display = 'none';
            banner.textContent = '';
            return;
        }
        banner.hidden = false;
        banner.style.display = 'block';
        banner.textContent = 'Hay ' + n + ' venta(s) pendiente(s) de sincronizar (sin red). Se reintentarán al volver online.';
    }

    function enqueueOfflineSale(payload) {
        var q = loadPendingQueue();
        q.push({
            id: 'p' + Date.now() + '_' + Math.random().toString(36).slice(2, 8),
            at: new Date().toISOString(),
            payload: payload
        });
        savePendingQueue(q);
    }

    function formDataToObject(fd) {
        var obj = {};
        fd.forEach(function (value, key) {
            if (Object.prototype.hasOwnProperty.call(obj, key)) {
                if (!Array.isArray(obj[key])) obj[key] = [obj[key]];
                obj[key].push(value);
            } else {
                obj[key] = value;
            }
        });
        return obj;
    }

    function objectToFormData(obj) {
        var fd = new FormData();
        Object.keys(obj || {}).forEach(function (key) {
            var val = obj[key];
            if (Array.isArray(val)) {
                val.forEach(function (v) { fd.append(key, v); });
            } else {
                fd.append(key, val);
            }
        });
        return fd;
    }

    function syncPendingSales() {
        if (!navigator.onLine) {
            updateOfflineBanner();
            return;
        }
        var q = loadPendingQueue();
        if (!q.length) {
            updateOfflineBanner();
            return;
        }
        var remaining = [];
        var chain = Promise.resolve();
        q.forEach(function (entry) {
            chain = chain.then(function () {
                var fd = objectToFormData(entry.payload || {});
                if (!fd.has('csrf_token') && csrf) fd.append('csrf_token', csrf);
                return fetch(saleUrl, {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                }).then(function (r) { return r.json(); }).then(function (d) {
                    if (!d || !d.success) {
                        remaining.push(entry);
                    }
                }).catch(function () {
                    remaining.push(entry);
                });
            });
        });
        chain.then(function () {
            savePendingQueue(remaining);
            if (remaining.length < q.length && typeof showAlert === 'function') {
                var ok = q.length - remaining.length;
                showAlert(ok + ' venta(s) offline sincronizada(s)', 'success');
            }
        });
    }

    updateOfflineBanner();
    window.addEventListener('online', syncPendingSales);
    setTimeout(syncPendingSales, 1200);

    var elSearch = document.getElementById('posProductSearch');
    var elResults = document.getElementById('posSearchResults');
    var elBody = document.getElementById('posItemsBody');
    var elTotal = document.getElementById('posTotal');
    var elCount = document.getElementById('posItemCount');
    var elPayBtn = document.getElementById('posPayBtn');
    var elPayBar = document.getElementById('posPayBar');
    var elClock = document.getElementById('posClock');
    var elClear = document.getElementById('posSearchClear');
    var elDiscount = document.getElementById('posDiscountPercent');
    var elSubLine = document.getElementById('posSubtotalLine');
    var elDiscLine = document.getElementById('posDiscountLine');
    var elSubtotal = document.getElementById('posSubtotal');
    var elDiscAmt = document.getElementById('posDiscountAmt');

    var elInvoice = document.getElementById('posInvoicePreview');
    var elPayType = document.getElementById('posPaymentType');
    var elTermsGroup = document.getElementById('posTermsGroup');
    var elInitialGroup = document.getElementById('posInitialGroup');
    var elInitialPay = document.getElementById('posInitialPayment');
    var elPayTerms = document.getElementById('posPaymentTerms');

    function money(n) {
        var x = Number(n) || 0;
        return symbol + ' ' + x.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function previewInvoice() {
        if (!elInvoice) return;
        var d = new Date();
        var ymd = d.getFullYear() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0');
        elInvoice.textContent = prefix + ymd + '-····';
    }
    previewInvoice();

    function discountPercent() {
        var p = elDiscount ? parseFloat(elDiscount.value) : 0;
        if (isNaN(p) || p < 0) p = 0;
        if (p > 100) p = 100;
        return p;
    }

    function togglePosCredit() {
        var credit = elPayType && elPayType.value === 'credit';
        if (elTermsGroup) elTermsGroup.style.display = credit ? '' : 'none';
        if (elInitialGroup) elInitialGroup.style.display = credit ? '' : 'none';
    }
    if (elPayType) elPayType.addEventListener('change', togglePosCredit);
    togglePosCredit();

    function initPosCustomerCombobox() {
        var input = document.getElementById('posCustomerSearch');
        var hidden = document.getElementById('posCustomer');
        var list = document.getElementById('posCustomerList');
        if (!input || !hidden || !list || input._posReady) return;
        input._posReady = true;
        input.value = 'Cliente general';
        input.addEventListener('focus', function () {
            list.hidden = false;
            filterPosCustomers('');
        });
        input.addEventListener('input', function () {
            list.hidden = false;
            filterPosCustomers(input.value);
        });
        list.addEventListener('click', function (e) {
            var li = e.target.closest('.combobox-option');
            if (!li) return;
            hidden.value = li.getAttribute('data-id') || '';
            input.value = li.getAttribute('data-label') || 'Cliente general';
            list.hidden = true;
        });
        document.addEventListener('click', function (e) {
            var wrap = document.getElementById('posCustomerCombobox');
            if (wrap && !wrap.contains(e.target)) list.hidden = true;
        });
        function filterPosCustomers(q) {
            q = (q || '').toLowerCase().trim();
            Array.prototype.forEach.call(list.querySelectorAll('.combobox-option'), function (li) {
                var s = (li.getAttribute('data-search') || '').toLowerCase();
                li.style.display = (!q || s.indexOf(q) >= 0) ? '' : 'none';
            });
        }
    }
    initPosCustomerCombobox();

    window.setPosCustomer = function (id, label) {
        var hidden = document.getElementById('posCustomer');
        var input = document.getElementById('posCustomerSearch');
        var list = document.getElementById('posCustomerList');
        if (hidden) hidden.value = id || '';
        if (input) input.value = label || 'Cliente general';
        if (list && id) {
            var exists = list.querySelector('.combobox-option[data-id="' + id + '"]');
            if (!exists) {
                var li = document.createElement('li');
                li.className = 'combobox-option';
                li.setAttribute('data-id', String(id));
                li.setAttribute('data-label', label);
                li.setAttribute('data-search', (label || '').toLowerCase());
                li.textContent = label;
                list.appendChild(li);
            }
        }
    };

    function tick() {
        if (!elClock) return;
        elClock.textContent = new Date().toLocaleTimeString('es-CO', { hour: 'numeric', minute: '2-digit' });
    }
    tick();
    setInterval(tick, 30000);

    function cartQty(productId) {
        var row = items.find(function (i) { return String(i.product_id) === String(productId); });
        return row ? row.quantity : 0;
    }

    function addProduct(p, qty) {
        qty = qty || 1;
        var stock = parseInt(p.stock, 10);
        if (isNaN(stock)) stock = 0;
        var inCart = cartQty(p.id);
        if (inCart + qty > stock) {
            if (typeof showAlert === 'function') showAlert('Stock insuficiente. Disponible: ' + stock, 'error');
            return false;
        }
        var existing = items.find(function (i) { return String(i.product_id) === String(p.id); });
        if (existing) {
            existing.quantity += qty;
            existing.subtotal = existing.quantity * existing.unit_price;
            existing.stock = stock;
        } else {
            items.push({
                product_id: p.id,
                product_name: p.name,
                code: p.code || '',
                quantity: qty,
                unit_price: parseFloat(p.sale_price) || 0,
                subtotal: (parseFloat(p.sale_price) || 0) * qty,
                stock: stock
            });
        }
        renderCart();
        return true;
    }

    function bumpQty(idx, delta) {
        var row = items[idx];
        if (!row) return;
        var next = row.quantity + delta;
        if (next <= 0) {
            items.splice(idx, 1);
        } else if (next > row.stock) {
            if (typeof showAlert === 'function') showAlert('Stock insuficiente. Disponible: ' + row.stock, 'error');
            return;
        } else {
            row.quantity = next;
            row.subtotal = row.quantity * row.unit_price;
        }
        renderCart();
    }

    function setQty(idx, value) {
        var row = items[idx];
        if (!row) return;
        var q = parseInt(value, 10);
        if (isNaN(q) || q < 1) q = 1;
        if (q > row.stock) {
            q = row.stock;
            if (typeof showAlert === 'function') showAlert('Stock máximo: ' + row.stock, 'error');
        }
        row.quantity = q;
        row.subtotal = row.quantity * row.unit_price;
        renderCart();
    }

    function renderCart() {
        var subtotal = 0;
        var count = 0;
        if (!items.length) {
            elBody.innerHTML = '<tr class="pos-empty-row"><td colspan="3">Escanee o busque productos a la derecha</td></tr>';
            elPayBtn.disabled = true;
            elPayBar.hidden = true;
        } else {
            elBody.innerHTML = '';
            items.forEach(function (it, idx) {
                subtotal += it.subtotal;
                count += it.quantity;
                var label = (it.code ? it.code + ' — ' : '') + it.product_name +
                    ' Valor und ' + money(it.unit_price) + ' Total ' + money(it.subtotal);
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td><input type="number" min="1" class="pos-qty" data-idx="' + idx + '" value="' + it.quantity + '"></td>' +
                    '<td><div class="pos-prod-label">' + esc(label) + '</div></td>' +
                    '<td class="pos-actions">' +
                    '<button type="button" class="pos-btn-plus" data-idx="' + idx + '" title="Sumar">+1</button>' +
                    '<button type="button" class="pos-btn-del" data-idx="' + idx + '" title="Quitar">&times;</button>' +
                    '</td>';
                elBody.appendChild(tr);
            });
            elPayBtn.disabled = false;
            elPayBtn.textContent = 'Listo';
        }

        var pct = discountPercent();
        var discAmt = subtotal * (pct / 100);
        var total = Math.max(0, subtotal - discAmt);

        if (elSubLine && elDiscLine && elSubtotal && elDiscAmt) {
            if (pct > 0 && subtotal > 0) {
                elSubLine.style.display = '';
                elDiscLine.style.display = '';
                elSubtotal.textContent = money(subtotal);
                elDiscAmt.textContent = '-' + money(discAmt) + ' (' + pct + '%)';
            } else {
                elSubLine.style.display = 'none';
                elDiscLine.style.display = 'none';
            }
        }

        elTotal.textContent = money(total);
        elCount.textContent = String(count);
        if (!items.length) {
            elPayBtn.textContent = 'Listo';
        }
    }

    function renderResults(list, q) {
        if (!list.length) {
            elResults.innerHTML = '<p class="pos-search-hint">Sin resultados para “' + esc(q) + '”</p>';
            return;
        }
        elResults.innerHTML = '';
        list.forEach(function (p) {
            var row = document.createElement('div');
            row.className = 'pos-result-row';
            row.innerHTML =
                '<div class="pos-result-info">' +
                '<div class="pos-result-title">' + esc((p.code ? p.code + ' — ' : '') + p.name) + '</div>' +
                '<div class="pos-result-meta">Stock: ' + p.stock + ' · ' + money(p.sale_price) + '</div>' +
                '</div>' +
                '<button type="button" class="pos-result-add" title="Agregar">+</button>';
            row.querySelector('.pos-result-add').addEventListener('click', function () {
                if (addProduct(p, 1) && typeof showAlert === 'function') {
                    showAlert(p.name + ' agregado', 'success');
                }
                elSearch.focus();
            });
            elResults.appendChild(row);
        });
    }

    function search(q, opts) {
        opts = opts || {};
        q = (q || '').trim();
        lastQuery = q;
        if (!q) {
            elResults.innerHTML = '<p class="pos-search-hint">Escriba o escanee un código para encontrar productos</p>';
            return;
        }
        fetch(searchUrl + '&q=' + encodeURIComponent(q), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (q !== lastQuery) return;
                var products = (d && d.products) ? d.products : [];
                if (opts.autoAddExact) {
                    var exact = products.find(function (p) {
                        return String(p.code || '').toLowerCase() === q.toLowerCase();
                    });
                    if (exact) {
                        if (addProduct(exact, 1)) {
                            elSearch.value = '';
                            elResults.innerHTML = '<p class="pos-search-hint">Agregado: ' + esc(exact.name) + '. Escanee el siguiente…</p>';
                            if (typeof showAlert === 'function') showAlert(exact.name + ' agregado', 'success');
                        }
                        return;
                    }
                    if (products.length === 1) {
                        if (addProduct(products[0], 1)) {
                            elSearch.value = '';
                            elResults.innerHTML = '<p class="pos-search-hint">Agregado: ' + esc(products[0].name) + '. Escanee el siguiente…</p>';
                        }
                        return;
                    }
                }
                renderResults(products, q);
            })
            .catch(function () {
                elResults.innerHTML = '<p class="pos-search-hint">Error al buscar. Intente de nuevo.</p>';
            });
    }

    elSearch.addEventListener('input', function () {
        clearTimeout(searchTimer);
        var q = elSearch.value;
        searchTimer = setTimeout(function () { search(q, { autoAddExact: false }); }, 220);
    });

    elSearch.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(searchTimer);
            search(elSearch.value, { autoAddExact: true });
        }
    });

    if (elClear) {
        elClear.addEventListener('click', function () {
            elSearch.value = '';
            elResults.innerHTML = '<p class="pos-search-hint">Escriba o escanee un código para encontrar productos</p>';
            elSearch.focus();
        });
    }

    if (elDiscount) {
        elDiscount.addEventListener('input', renderCart);
        elDiscount.addEventListener('change', function () {
            var p = discountPercent();
            elDiscount.value = String(p);
            renderCart();
        });
    }

    elBody.addEventListener('click', function (e) {
        var plus = e.target.closest('.pos-btn-plus');
        var del = e.target.closest('.pos-btn-del');
        if (plus) bumpQty(parseInt(plus.getAttribute('data-idx'), 10), 1);
        if (del) {
            var i = parseInt(del.getAttribute('data-idx'), 10);
            items.splice(i, 1);
            renderCart();
        }
    });

    elBody.addEventListener('change', function (e) {
        var inp = e.target.closest('.pos-qty');
        if (!inp) return;
        setQty(parseInt(inp.getAttribute('data-idx'), 10), inp.value);
    });

    elPayBtn.addEventListener('click', function () {
        if (!items.length) return;
        elPayBar.hidden = false;
    });

    document.getElementById('posCancelPay').addEventListener('click', function () {
        elPayBar.hidden = true;
    });

    document.getElementById('posConfirmPay').addEventListener('click', function () {
        if (paying || !items.length) return;
        paying = true;
        var btn = document.getElementById('posConfirmPay');
        btn.disabled = true;

        var fd = new FormData();
        fd.append('csrf_token', csrf);
        var customerId = document.getElementById('posCustomer').value;
        if (customerId) fd.append('customer_id', customerId);
        var payType = (elPayType && elPayType.value) || 'full';
        fd.append('payment_method', document.getElementById('posPayMethod').value || 'cash');
        fd.append('payment_type', payType);
        fd.append('document_type', 'invoice');
        fd.append('payment_terms', payType === 'credit'
            ? ((elPayTerms && elPayTerms.value) || 'net_30')
            : 'cash');
        fd.append('sale_date', new Date().toISOString().slice(0, 10));
        if (payType === 'credit' && elInitialPay) {
            fd.append('initial_payment', elInitialPay.value || '0');
        }
        fd.append('notes', 'Venta rápida desde caja');
        var pct = discountPercent();
        if (pct > 0) fd.append('discount_percent', String(pct));

        items.forEach(function (it, i) {
            fd.append('items[' + i + '][product_id]', it.product_id);
            fd.append('items[' + i + '][quantity]', it.quantity);
            fd.append('items[' + i + '][unit_price]', it.unit_price);
            fd.append('items[' + i + '][product_name]', it.product_name);
            fd.append('items[' + i + '][subtotal]', it.subtotal);
        });

        fetch(saleUrl, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                paying = false;
                btn.disabled = false;
                if (!d || !d.success) {
                    if (typeof showAlert === 'function') showAlert((d && d.message) || 'No se pudo registrar la venta', 'error');
                    return;
                }
                var msg = d.message || 'Venta registrada';
                var m = msg.match(/(FAC-|REM-|COT-)[A-Z0-9\-]+/i);
                if (m && elInvoice) elInvoice.textContent = m[0];
                if (typeof showAlert === 'function') showAlert(msg, 'success');
                items = [];
                if (elDiscount) elDiscount.value = '0';
                if (elPayType) elPayType.value = 'full';
                togglePosCredit();
                renderCart();
                elPayBar.hidden = true;
                elSearch.value = '';
                elSearch.focus();
                setTimeout(function () { window.location.reload(); }, 900);
            })
            .catch(function () {
                paying = false;
                btn.disabled = false;
                // Offline-lite: guardar en cola local y reintentar al volver online
                enqueueOfflineSale(formDataToObject(fd));
                items = [];
                if (elDiscount) elDiscount.value = '0';
                if (elPayType) elPayType.value = 'full';
                togglePosCredit();
                renderCart();
                elPayBar.hidden = true;
                if (typeof showAlert === 'function') {
                    showAlert('Sin conexión: venta guardada en cola local. Se sincronizará al volver online.', 'warning');
                }
            });
    });

    // Últimos productos (click)
    elResults.addEventListener('click', function (e) {
        var row = e.target.closest('.pos-recent-product');
        var addBtn = e.target.closest('.pos-result-add');
        if (!row && !addBtn) return;
        var target = row || (addBtn && addBtn.closest('.pos-recent-product'));
        if (!target || !target.classList.contains('pos-recent-product')) return;
        e.preventDefault();
        var p = {
            id: target.getAttribute('data-id'),
            name: target.getAttribute('data-name'),
            code: target.getAttribute('data-code') || '',
            sale_price: parseFloat(target.getAttribute('data-price') || '0'),
            stock: parseInt(target.getAttribute('data-stock') || '0', 10)
        };
        if (addProduct(p, 1) && typeof showAlert === 'function') showAlert(p.name + ' agregado', 'success');
    });

    // Cerrar menú gasto al click fuera
    document.addEventListener('click', function (e) {
        var menu = document.getElementById('posGastoDropdown');
        var btn = document.getElementById('posGastoMenuBtn');
        if (!menu || !btn) return;
        if (!menu.contains(e.target) && e.target !== btn && !btn.contains(e.target)) {
            menu.style.display = 'none';
        }
    });

    var pref = document.getElementById('posPrefijo');
    if (pref) pref.textContent = prefix + '…';

    window.SoftNovaPos = {
        addProduct: addProduct,
        hasPanel: true
    };

    if (window.SoftNovaBarcode && typeof SoftNovaBarcode.onScan === 'function') {
        SoftNovaBarcode.onScan(function (code, product) {
            if (!product) return false;
            var ok = addProduct(product, 1);
            if (ok && typeof showAlert === 'function') showAlert(product.name + ' agregado', 'success');
            return true;
        }, true);
    }

    renderCart();
    elSearch.focus();
})();

function printClosing(id) {
    var row = (typeof event !== 'undefined' && event.target) ? event.target.closest('tr') : null;
    if (!row) return;
    var cells = row.querySelectorAll('td');
    var printWin = window.open('', '_blank', 'width=400,height=600');
    printWin.document.write('<!DOCTYPE html><html><head><title>Cierre de Caja</title>' +
        '<style>body{font-family:Arial;padding:20px;font-size:12px;}h2{text-align:center;}table{width:100%;border-collapse:collapse;}td{padding:6px;border-bottom:1px solid #ddd;}.right{text-align:right;}</style></head>' +
        '<body><h2>Cierre de Caja</h2><table>' +
        '<tr><td>Apertura:</td><td class="right">' + cells[0].textContent + '</td></tr>' +
        '<tr><td>Cierre:</td><td class="right">' + cells[1].textContent + '</td></tr>' +
        '<tr><td>Usuario:</td><td>' + cells[2].textContent + '</td></tr>' +
        '<tr><td>Monto Inicial:</td><td class="right">' + cells[3].textContent + '</td></tr>' +
        '<tr><td>Monto Final:</td><td class="right">' + cells[4].textContent + '</td></tr>' +
        '<tr><td>Diferencia:</td><td class="right">' + cells[5].textContent + '</td></tr></table></body></html>');
    printWin.document.close();
    setTimeout(function () { printWin.print(); }, 300);
}
