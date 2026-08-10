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
    if (!modal || !idEl) {
        if (typeof showAlert === 'function') showAlert('No se pudo abrir el formulario de cancelación', 'error');
        return;
    }
    var saleId = parseInt(id, 10);
    if (!saleId || saleId < 1) {
        if (typeof showAlert === 'function') showAlert('ID de venta inválido', 'error');
        return;
    }
    idEl.value = String(saleId);
    if (invEl) invEl.textContent = invoice || ('#' + saleId);
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
    var idEl = document.getElementById('cancelSaleId');
    var saleId = idEl ? parseInt(idEl.value, 10) : 0;
    if (!saleId || saleId < 1) {
        if (typeof showAlert === 'function') showAlert('ID de venta inválido. Vuelva a abrir el modal.', 'error');
        return false;
    }
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
            var ticketUrl = d.ticket_pdf_url || '';
            var waUrl = d.whatsapp_url || '#';
            btn.href = waUrl;
            btn.onclick = function(ev) {
                if (typeof sharePdfToWhatsApp === 'function' && ticketUrl) {
                    ev.preventDefault();
                    sharePdfToWhatsApp({
                        pdfUrl: ticketUrl,
                        waLink: waUrl,
                        text: d.text || '',
                        title: 'Ticket de venta',
                        filename: 'Ticket_' + id + '.pdf'
                    });
                    return false;
                }
            };
            document.getElementById('shareModal').style.display = 'flex';
        });
}

function oversellLabel(code) {
    var map = { cambio: 'Cambio', prestamo: 'Préstamo', venta_pendiente: 'Venta pendiente', otro: 'Otro' };
    return map[code] || code;
}

var oversellPendingCb = null;
function requestOversellReason(productName, available, requested, onOk) {
    var modal = document.getElementById('oversellModal');
    var info = document.getElementById('oversellInfo');
    var sel = document.getElementById('oversellReason');
    var note = document.getElementById('oversellNote');
    var noteGroup = document.getElementById('oversellNoteGroup');
    var btn = document.getElementById('oversellConfirmBtn');
    if (!modal || !sel || !btn) {
        return showAlert('Configure el motivo de venta sin stock', 'error');
    }
    oversellPendingCb = onOk;
    if (info) {
        info.textContent = (productName || 'Producto') + ': disponible ' + available + ', se venden ' + requested
            + '. Indique por qué se entrega sin inventario.';
    }
    sel.value = '';
    if (note) note.value = '';
    if (noteGroup) noteGroup.style.display = 'none';
    modal.style.display = 'flex';
    if (!sel._oversellBound) {
        sel.addEventListener('change', function () {
            if (noteGroup) noteGroup.style.display = sel.value === 'otro' ? '' : 'none';
        });
        btn.addEventListener('click', function () {
            var reason = sel.value;
            var detail = note ? String(note.value || '').trim() : '';
            if (!reason) return showAlert('Seleccione un motivo', 'error');
            if (reason === 'otro' && detail === '') return showAlert('Escriba el detalle del motivo Otro', 'error');
            modal.style.display = 'none';
            var cb = oversellPendingCb;
            oversellPendingCb = null;
            if (typeof cb === 'function') cb(reason, detail);
        });
        sel._oversellBound = true;
    }
}

function addProduct(ev) {
    var sel = document.getElementById('productSelect');
    var qty = parseInt(document.getElementById('productQty').value) || 1;
    if (!sel.value) return;
    var o = sel.options[sel.selectedIndex];
    var id = o.value, name = o.dataset.name, price = parseFloat(o.dataset.price), stock = parseInt(o.dataset.stock, 10);
    var isService = (o.dataset.type || '') === 'service';
    var openItem = o.dataset.openItem === '1' || (typeof isOpenItemProduct === 'function' && isOpenItemProduct({
        code: o.dataset.code || '',
        is_open_item: o.dataset.openItem
    }));
    if (isNaN(stock)) stock = 0;

    if (openItem) {
        if (typeof requestOpenItemDetails !== 'function') {
            showAlert('Actualice app.js para vender el comodín', 'error');
            return;
        }
        requestOpenItemDetails(function (customName, customPrice) {
            saleItems.push({
                product_id: id,
                product_name: customName,
                quantity: qty,
                unit_price: customPrice,
                subtotal: qty * customPrice,
                stock: 0,
                product_type: 'service',
                is_open_item: true,
                stock_oversell_reason: '',
                stock_oversell_note: ''
            });
            document.getElementById('productQty').value = 1;
            renderItems();
            updateStockHint();
            showAlert(customName + ' agregado (comodín · sin stock)', 'info');
            if (typeof animateAddToCart === 'function') {
                var fromEl = (ev && ev.target) ? ev.target : document.getElementById('addProductBtn');
                animateAddToCart(fromEl && fromEl.closest ? (fromEl.closest('.btn') || fromEl) : fromEl);
            }
        });
        return;
    }

    var ex = saleItems.find(function(i) { return i.product_id == id && !i.is_open_item; });
    var nextQty = (ex ? ex.quantity : 0) + qty;

    function commit(reason, note) {
        if (ex) {
            ex.quantity += qty;
            ex.subtotal = ex.quantity * ex.unit_price;
            ex.stock = stock;
            if (reason) {
                ex.stock_oversell_reason = reason;
                ex.stock_oversell_note = note || '';
            }
        } else {
            saleItems.push({
                product_id: id,
                product_name: name,
                quantity: qty,
                unit_price: price,
                subtotal: qty * price,
                stock: stock,
                product_type: o.dataset.type || 'product',
                stock_oversell_reason: reason || '',
                stock_oversell_note: note || ''
            });
        }
        document.getElementById('productQty').value = 1;
        renderItems();
        updateStockHint();
        var remaining = stock - (ex ? ex.quantity : qty);
        var level = remaining < 0 ? 'warning' : (remaining <= 0 ? 'error' : (remaining <= 5 ? 'warning' : 'info'));
        showAlert(name + ' agregado. Restante en bodega: ' + remaining, level);
        if (typeof animateAddToCart === 'function') {
            var fromEl = (ev && ev.target) ? ev.target : document.getElementById('addProductBtn');
            animateAddToCart(fromEl && fromEl.closest ? (fromEl.closest('.btn') || fromEl) : fromEl);
        }
    }

    if (!isService && nextQty > Math.max(0, stock)) {
        requestOversellReason(name, stock, nextQty, commit);
        return;
    }
    commit('', '');
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

function setSaleItemPrice(idx, value) {
    var it = saleItems[idx];
    if (!it) return;
    var price = parseFloat(String(value).replace(',', '.'));
    if (isNaN(price) || price < 0) price = 0;
    it.unit_price = Math.round(price * 100) / 100;
    it.subtotal = it.quantity * it.unit_price;
    renderItems();
}

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
        var remColor = remaining < 0 ? '#B45309' : (remaining <= 0 ? '#DC2626' : (remaining <= 5 ? '#B45309' : '#059669'));
        var oversellBadge = it.stock_oversell_reason
            ? ' <span class="badge badge-warning">Sin stock · ' + esc(oversellLabel(it.stock_oversell_reason)) + '</span>'
            : '';
        if (it.is_open_item) {
            oversellBadge += ' <span class="badge badge-info">Comodín</span>';
        }
        var stockCell = it.is_open_item
            ? '<td style="color:var(--color-text-secondary);">—</td>'
            : '<td style="font-weight:600;color:' + remColor + ';">' + remaining + ' <small style="font-weight:400;color:var(--color-text-secondary);">/ ' + stock + '</small></td>';
        tb.innerHTML += '<tr>' +
            '<td>' + esc(it.product_name) + oversellBadge + '</td>' +
            '<td>' + it.quantity + '</td>' +
            '<td><input type="number" min="0" step="any" class="form-control" style="width:110px;padding:4px 8px;" value="' + it.unit_price + '" onchange="setSaleItemPrice(' + i + ', this.value)" title="Editar precio de venta"></td>' +
            stockCell +
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

function openEditPaymentMethodModal(id, invoice, currentMethod) {
    var modal = document.getElementById('editPaymentMethodModal');
    var idEl = document.getElementById('editPaySaleId');
    var invEl = document.getElementById('editPayInvoice');
    var sel = document.getElementById('editPayMethodSelect');
    var mixedToggle = document.getElementById('editPayMixedToggle');
    var isMixedHidden = document.getElementById('editPayIsMixed');
    var singleWrap = document.getElementById('editPaySingleWrap');
    var mixedWrap = document.getElementById('editPayMixedWrap');
    var rowsWrap = document.getElementById('editPayMixedRows');
    var targetEl = document.getElementById('editPayTarget');
    var form = document.getElementById('editPayMethodForm');
    if (!modal || !idEl || !sel) {
        if (typeof showAlert === 'function') showAlert('No se pudo abrir el editor de medio de pago', 'error');
        return;
    }
    idEl.value = String(id);
    if (invEl) invEl.textContent = invoice || ('#' + id);
    var base = (window.invRouteVentasDetail || '').replace('action=detail', 'action=payment_splits');
    if (!base || base.indexOf('payment_splits') < 0) {
        base = (window.location.pathname.indexOf('/app/caja') >= 0
            ? (window.cajaBase || '/app/caja')
            : '/app/ventas') + '?action=payment_splits';
    }
    // Prefer ventas route for payment_splits
    var splitsUrl = (typeof window.invRouteVentasShare === 'string'
        ? window.invRouteVentasShare.replace('action=share', 'action=payment_splits')
        : '') || ((window.cajaBase || '/app/ventas').replace(/\/app\/caja.*/, '/app/ventas') + '?action=payment_splits');
    if (window.invRouteVentasDetail) {
        splitsUrl = window.invRouteVentasDetail.replace('action=detail', 'action=payment_splits');
    }

    function optsHtml(selected) {
        var tpl = document.getElementById('editPayMethodOptionsTpl') || document.getElementById('posPayMethodOptionsTpl');
        var html = tpl ? tpl.innerHTML : sel.innerHTML;
        if (!selected) return html;
        return html.replace(
            new RegExp('value="' + String(selected).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '"', 'i'),
            'value="' + selected + '" selected'
        );
    }

    var editPayTarget = 0;
    var editMixedSyncing = false;

    function syncEditMixedRemainder(changedInput) {
        if (editMixedSyncing || !rowsWrap) return;
        var inputs = rowsWrap.querySelectorAll('.edit-pay-amount');
        if (inputs.length < 2) {
            updateMixedSummary();
            return;
        }
        var need = editPayTarget;
        editMixedSyncing = true;
        if (inputs.length === 2) {
            var edited = (changedInput === inputs[1]) ? inputs[1] : inputs[0];
            var other = edited === inputs[0] ? inputs[1] : inputs[0];
            var val = parseFloat(edited.value) || 0;
            if (val < 0) val = 0;
            if (val > need) val = need;
            var rest = Math.round(Math.max(0, need - val) * 100) / 100;
            edited.value = String(Math.round(val * 100) / 100);
            other.value = String(rest);
        } else {
            var last = inputs[inputs.length - 1];
            var sum = 0;
            var i;
            if (changedInput !== last) {
                for (i = 0; i < inputs.length - 1; i++) sum += parseFloat(inputs[i].value) || 0;
                last.value = String(Math.round(Math.max(0, need - sum) * 100) / 100);
            } else {
                for (i = 1; i < inputs.length; i++) sum += parseFloat(inputs[i].value) || 0;
                inputs[0].value = String(Math.round(Math.max(0, need - sum) * 100) / 100);
            }
        }
        editMixedSyncing = false;
        updateMixedSummary();
    }

    function addMixedRow(method, amount) {
        if (!rowsWrap) return;
        var row = document.createElement('div');
        row.className = 'edit-pay-mixed-row';
        row.style.cssText = 'display:flex;gap:8px;align-items:end;margin-bottom:8px;flex-wrap:wrap;';
        row.innerHTML =
            '<div class="form-group" style="flex:1;min-width:140px;margin:0;">' +
            '<label style="font-size:12px;">Método</label>' +
            '<select class="form-control edit-pay-method" name="payment_splits[' + rowsWrap.children.length + '][method]">' +
            optsHtml(method || 'cash') + '</select></div>' +
            '<div class="form-group" style="width:120px;margin:0;">' +
            '<label style="font-size:12px;">Monto</label>' +
            '<input type="number" class="form-control edit-pay-amount" name="payment_splits[' + (rowsWrap.children.length) + '][amount]" min="0" step="0.01" value="' + (amount != null ? amount : '') + '"></div>' +
            '<button type="button" class="btn btn-sm btn-danger edit-pay-remove" title="Quitar">&times;</button>';
        rowsWrap.appendChild(row);
        reindexMixedRows();
        updateMixedSummary();
    }

    function reindexMixedRows() {
        if (!rowsWrap) return;
        Array.prototype.forEach.call(rowsWrap.children, function (row, i) {
            var m = row.querySelector('.edit-pay-method');
            var a = row.querySelector('.edit-pay-amount');
            if (m) m.name = 'payment_splits[' + i + '][method]';
            if (a) a.name = 'payment_splits[' + i + '][amount]';
        });
    }

    function updateMixedSummary() {
        var sum = 0;
        if (rowsWrap) {
            rowsWrap.querySelectorAll('.edit-pay-amount').forEach(function (inp) {
                sum += parseFloat(inp.value) || 0;
            });
        }
        var el = document.getElementById('editPayMixedSummary');
        if (el) {
            var diff = Math.round((editPayTarget - sum) * 100) / 100;
            el.textContent = 'Asignado: ' + sum.toFixed(2) + ' / ' + editPayTarget.toFixed(2)
                + (Math.abs(diff) > 0.02 ? ' (faltan ' + diff.toFixed(2) + ')' : ' ✓');
            el.style.color = Math.abs(diff) > 0.02 ? '#B45309' : '#059669';
        }
    }

    function setMixedMode(on) {
        if (isMixedHidden) isMixedHidden.value = on ? '1' : '0';
        if (mixedToggle) mixedToggle.checked = !!on;
        if (singleWrap) singleWrap.style.display = on ? 'none' : '';
        if (mixedWrap) mixedWrap.style.display = on ? '' : 'none';
        if (sel) {
            sel.required = !on;
            sel.disabled = !!on;
        }
        if (rowsWrap) {
            rowsWrap.querySelectorAll('select, input').forEach(function (el) {
                el.disabled = !on;
            });
        }
    }

    fetch(splitsUrl + '&id=' + encodeURIComponent(id), {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        var splits = (d && d.splits) ? d.splits : [];
        if (d && d.paid_target != null) {
            editPayTarget = parseFloat(d.paid_target) || 0;
        } else if (splits.length) {
            editPayTarget = splits.reduce(function (acc, s) { return acc + (parseFloat(s.amount) || 0); }, 0);
        } else {
            editPayTarget = (d && d.payment_status === 'paid' && d.total != null) ? parseFloat(d.total) : 0;
        }
        if (editPayTarget <= 0.009) {
            if (typeof showAlert === 'function') {
                showAlert('No hay pagos registrados para editar en esta venta', 'error');
            }
            return;
        }
        if (targetEl) targetEl.textContent = (window.curSym || '$') + ' ' + editPayTarget.toFixed(window.curDec || 2);
        var method = (d && d.payment_method) || currentMethod || 'cash';
        var isMixed = method === 'mixed' || splits.length > 1;
        setMixedMode(isMixed);
        if (rowsWrap) rowsWrap.innerHTML = '';
        if (isMixed) {
            if (!splits.length) splits = [{ method: 'cash', amount: editPayTarget }];
            splits.forEach(function (s) { addMixedRow(s.method, s.amount); });
        } else {
            var m = splits[0] ? splits[0].method : method;
            if (sel.querySelector('option[value="' + String(m).replace(/"/g, '') + '"]')) sel.value = m;
            if (window.SoftNovaCustomSelect) SoftNovaCustomSelect.bind(sel);
        }
        modal.style.display = 'flex';
    })
    .catch(function () {
        editPayTarget = 0;
        if (targetEl) targetEl.textContent = '—';
        setMixedMode(String(currentMethod || '') === 'mixed');
        if (sel.querySelector('option[value="' + String(currentMethod || 'cash').replace(/"/g, '') + '"]')) {
            sel.value = currentMethod || 'cash';
        }
        modal.style.display = 'flex';
    });

    if (mixedToggle && !mixedToggle._bound) {
        mixedToggle._bound = true;
        mixedToggle.addEventListener('change', function () {
            setMixedMode(mixedToggle.checked);
            if (mixedToggle.checked && rowsWrap && !rowsWrap.children.length) {
                addMixedRow('cash', editPayTarget || '');
            }
        });
    }
    var addBtn = document.getElementById('editPayMixedAdd');
    if (addBtn && !addBtn._bound) {
        addBtn._bound = true;
        addBtn.addEventListener('click', function () {
            var sum = 0;
            if (rowsWrap) {
                rowsWrap.querySelectorAll('.edit-pay-amount').forEach(function (inp) {
                    sum += parseFloat(inp.value) || 0;
                });
            }
            var left = Math.round((editPayTarget - sum) * 100) / 100;
            addMixedRow('transfer', left > 0 ? left : '');
        });
    }
    if (rowsWrap && !rowsWrap._bound) {
        rowsWrap._bound = true;
        rowsWrap.addEventListener('click', function (e) {
            var btn = e.target.closest('.edit-pay-remove');
            if (!btn) return;
            var row = btn.closest('.edit-pay-mixed-row');
            if (row && rowsWrap.children.length > 1) {
                row.remove();
                reindexMixedRows();
                updateMixedSummary();
            }
        });
        rowsWrap.addEventListener('input', function (e) {
            if (e.target && e.target.classList.contains('edit-pay-amount')) {
                syncEditMixedRemainder(e.target);
            }
        });
    }
    if (form && !form._boundSubmit) {
        form._boundSubmit = true;
        form.addEventListener('submit', function () {
            if (isMixedHidden) isMixedHidden.value = (mixedToggle && mixedToggle.checked) ? '1' : '0';
            reindexMixedRows();
        });
    }
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
            h += '<h4>Pagos / abonos</h4><table><thead><tr><th>Fecha</th><th>Monto</th><th>Método</th><th>Notas</th></tr></thead><tbody>';
            d.payments.forEach(function(p) {
                h += '<tr><td>' + (p.payment_date || '').substr(0, 16) + '</td><td>' + curSym + ' ' + parseFloat(p.amount).toFixed(curDec) + '</td><td>' + esc(p.payment_method) + '</td><td>' + esc(p.notes || '') + '</td></tr>';
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
