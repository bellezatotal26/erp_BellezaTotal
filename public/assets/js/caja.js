// Seri ERP - Caja + POS rápido
'use strict';

(function () {
    var panel = document.getElementById('posPanel');
    if (!panel) return;

    var searchUrl = panel.getAttribute('data-search-url');
    var catalogUrl = panel.getAttribute('data-catalog-url');
    var saleUrl = panel.getAttribute('data-sale-url');
    var syncUrl = panel.getAttribute('data-sync-url');
    var csrf = panel.getAttribute('data-csrf');
    var symbol = panel.getAttribute('data-symbol') || '$';
    var decimals = parseInt(panel.getAttribute('data-decimals') || '0', 10);
    var prefix = panel.getAttribute('data-prefix') || 'FAC-';
    var Offline = window.SeriPosOffline || null;

    function currentCsrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return (meta && meta.getAttribute('content')) || csrf || '';
    }

    function updateOfflineBanner() {
        var banner = document.getElementById('posOfflineBanner');
        var net = document.getElementById('posNetworkBanner');
        var conflictBanner = document.getElementById('posConflictBanner');
        var conflictText = document.getElementById('posConflictBannerText');
        var n = Offline ? Offline.loadQueue().length : 0;
        var c = Offline ? Offline.loadConflicts().length : 0;
        var online = Offline ? Offline.isOnline() : navigator.onLine !== false;

        if (banner) {
            if (n <= 0) {
                banner.hidden = true;
                banner.style.display = 'none';
                banner.textContent = '';
            } else {
                banner.hidden = false;
                banner.style.display = 'block';
                banner.textContent = 'Hay ' + n + ' venta(s) pendiente(s) de sincronizar. Se reintentarán al volver online (sync por lotes).';
            }
        }
        if (net) {
            if (online) {
                net.hidden = true;
                net.style.display = 'none';
                net.textContent = '';
            } else {
                net.hidden = false;
                net.style.display = 'block';
                net.textContent = 'Sin conexión: puede vender de contado con el catálogo local. Crédito, factura electrónica y cierre de caja quedan bloqueados.';
            }
        }
        if (conflictBanner) {
            if (c <= 0) {
                conflictBanner.hidden = true;
                conflictBanner.style.display = 'none';
            } else {
                conflictBanner.hidden = false;
                conflictBanner.style.display = 'flex';
                if (conflictText) {
                    conflictText.textContent = 'Hay ' + c + ' venta(s) offline con conflicto (stock u otra regla). Revíselas antes de cerrar caja.';
                }
            }
        }
        applyOfflineUiGuards(online, n, c);
    }

    function applyOfflineUiGuards(online, pending, conflicts) {
        pending = typeof pending === 'number' ? pending : (Offline ? Offline.loadQueue().length : 0);
        conflicts = typeof conflicts === 'number' ? conflicts : (Offline ? Offline.loadConflicts().length : 0);
        var payTypeEl = document.getElementById('posPaymentType');
        if (payTypeEl) {
            var creditOpt = payTypeEl.querySelector('option[value="credit"]');
            if (creditOpt) creditOpt.disabled = !online;
            if (!online && payTypeEl.value === 'credit') {
                payTypeEl.value = 'full';
                if (typeof togglePosCredit === 'function') togglePosCredit();
            }
        }
        var closeBtn = document.getElementById('btnOpenCloseCash');
        if (closeBtn) {
            closeBtn.disabled = !online || pending > 0 || conflicts > 0;
            closeBtn.title = closeBtn.disabled
                ? 'Disponible solo con internet, sin cola pendiente ni conflictos'
                : '';
        }
    }

    function renderConflictModal() {
        var list = document.getElementById('posConflictList');
        var modal = document.getElementById('posConflictModal');
        if (!list || !Offline) return;
        var conflicts = Offline.loadConflicts();
        if (!conflicts.length) {
            list.innerHTML = '<p style="color:var(--color-text-secondary);">No hay conflictos pendientes.</p>';
            return;
        }
        list.innerHTML = '';
        conflicts.forEach(function (c) {
            var card = document.createElement('div');
            card.className = 'card';
            card.style.cssText = 'margin-bottom:12px;padding:12px;border:1px solid #FECACA;';
            var stockHtml = '';
            (c.stock_conflicts || []).forEach(function (s) {
                stockHtml += '<li><strong>' + esc(s.name || s.code || ('#' + s.product_id)) + '</strong>: ' +
                    'pedido ' + (s.requested || 0) + ' / disponible ' + (s.available || 0) + '</li>';
            });
            var items = (c.payload && c.payload.items) ? c.payload.items : [];
            if (!Array.isArray(items)) items = [];
            var itemsHtml = items.map(function (it) {
                return '<li>' + esc(it.product_name || ('#' + it.product_id)) + ' × ' + (it.quantity || 0) + '</li>';
            }).join('');
            card.innerHTML =
                '<div style="font-weight:600;margin-bottom:4px;">' + esc(c.message || 'Conflicto') + '</div>' +
                '<div style="font-size:12px;color:var(--color-text-secondary);margin-bottom:8px;">UUID: ' +
                esc((c.client_sale_uuid || '').slice(0, 18)) + '…</div>' +
                (stockHtml ? '<ul style="margin:0 0 8px 18px;font-size:13px;">' + stockHtml + '</ul>' : '') +
                (itemsHtml ? '<ul style="margin:0 0 8px 18px;font-size:12px;color:var(--color-text-secondary);">' + itemsHtml + '</ul>' : '') +
                '<div style="display:flex;gap:8px;flex-wrap:wrap;">' +
                '<button type="button" class="btn btn-sm btn-primary" data-requeue="' + esc(c.client_sale_uuid) + '">Reintentar</button>' +
                '<button type="button" class="btn btn-sm btn-secondary" data-discard="' + esc(c.client_sale_uuid) + '">Descartar</button>' +
                '</div>';
            list.appendChild(card);
        });
        list.querySelectorAll('[data-requeue]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                Offline.requeueConflict(btn.getAttribute('data-requeue'));
                renderConflictModal();
                updateOfflineBanner();
                syncPendingSales();
            });
        });
        list.querySelectorAll('[data-discard]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirm('¿Descartar esta venta local? No se registrará en el servidor.')) return;
                Offline.discardConflict(btn.getAttribute('data-discard'));
                renderConflictModal();
                updateOfflineBanner();
            });
        });
        if (modal) modal.style.display = 'flex';
    }

    function clearCartAfterSale(message, opts) {
        opts = opts || {};
        if (typeof showAlert === 'function' && message) showAlert(message, opts.alertType || 'success');
        items = [];
        resetDiscount();
        if (elCashReceived) elCashReceived.value = '';
        if (elPayType) elPayType.value = 'full';
        if (typeof togglePosCredit === 'function') togglePosCredit();
        renderCart();
        elPayBar.hidden = true;
        if (elSearch) {
            elSearch.value = '';
            elSearch.focus();
        }
        if (opts.reload) {
            setTimeout(function () { window.location.reload(); }, 900);
        }
    }

    function syncPendingSales() {
        if (!Offline) {
            updateOfflineBanner();
            return Promise.resolve();
        }
        return Offline.syncPendingSales(saleUrl, currentCsrf(), syncUrl).then(function (res) {
            updateOfflineBanner();
            if (res.synced > 0 || (res.newConflicts && res.newConflicts.length)) {
                // Reconciliar stock local con el servidor tras ok o conflictos.
                refreshCatalog();
            }
            if (res.synced > 0 && typeof showAlert === 'function') {
                showAlert(res.synced + ' venta(s) offline sincronizada(s)', 'success');
            }
            if (res.newConflicts && res.newConflicts.length && typeof showAlert === 'function') {
                showAlert(
                    res.newConflicts.length + ' venta(s) con conflicto. Use “Revisar conflictos”.',
                    'error'
                );
            }
            return res;
        });
    }

    function seedCatalogFromDom() {
        if (!Offline) return;
        var nodes = document.querySelectorAll('.pos-recent-product');
        var list = [];
        nodes.forEach(function (target) {
            list.push({
                id: parseInt(target.getAttribute('data-id') || '0', 10),
                name: target.getAttribute('data-name') || '',
                code: target.getAttribute('data-code') || '',
                sale_price: parseFloat(target.getAttribute('data-price') || '0'),
                stock: parseInt(target.getAttribute('data-stock') || '0', 10),
                product_type: 'product'
            });
        });
        if (list.length) Offline.upsertProducts(list);
    }

    function refreshCatalog() {
        if (!Offline || !catalogUrl) return Promise.resolve();
        return Offline.syncCatalog(catalogUrl).then(function (res) {
            if (res && res.success) {
                /* catálogo listo para búsqueda offline */
            }
            return res;
        }).catch(function () {
            return null;
        });
    }

    if (Offline) {
        Offline._onQueueChange = updateOfflineBanner;
    }
    updateOfflineBanner();
    seedCatalogFromDom();
    refreshCatalog();
    window.addEventListener('online', function () {
        updateOfflineBanner();
        syncPendingSales();
        refreshCatalog();
    });
    window.addEventListener('offline', updateOfflineBanner);
    setTimeout(syncPendingSales, 1200);

    var conflictBtn = document.getElementById('posConflictReviewBtn');
    if (conflictBtn) {
        conflictBtn.addEventListener('click', renderConflictModal);
    }
    var retryAllBtn = document.getElementById('posConflictRetryAll');
    if (retryAllBtn) {
        retryAllBtn.addEventListener('click', function () {
            if (!Offline) return;
            Offline.loadConflicts().slice().forEach(function (c) {
                Offline.requeueConflict(c.client_sale_uuid);
            });
            var modal = document.getElementById('posConflictModal');
            if (modal) modal.style.display = 'none';
            updateOfflineBanner();
            syncPendingSales();
        });
    }

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
    var elDiscountPreset = document.getElementById('posDiscountPreset');
    var elDiscountOtherWrap = document.getElementById('posDiscountOtherWrap');
    var elPayMethod = document.getElementById('posPayMethod');
    var elCashBlock = document.getElementById('posCashChangeBlock');
    var elCashReceived = document.getElementById('posCashReceived');
    var elCashChange = document.getElementById('posCashChange');
    var cartTotal = 0;

    // Estado del carrito POS (obligatorio en strict mode)
    var items = [];
    var searchTimer = null;
    var lastQuery = '';
    var paying = false;

    function money(n) {
        var x = Number(n) || 0;
        var fixed = x.toFixed(decimals);
        var parts = fixed.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return symbol + ' ' + (decimals > 0 ? parts.join('.') : parts[0]);
    }

    function parseMoneyInput(raw) {
        var s = String(raw == null ? '' : raw).trim().replace(/\s/g, '');
        if (!s) return 0;
        // Acepta 20000, 20.000, 20,000.50, 20000.50
        if (s.indexOf(',') >= 0 && s.indexOf('.') >= 0) {
            if (s.lastIndexOf(',') > s.lastIndexOf('.')) {
                s = s.replace(/\./g, '').replace(',', '.');
            } else {
                s = s.replace(/,/g, '');
            }
        } else if (s.indexOf(',') >= 0) {
            var bits = s.split(',');
            s = bits.length === 2 && bits[1].length <= 2
                ? bits[0].replace(/\./g, '') + '.' + bits[1]
                : s.replace(/,/g, '');
        } else {
            // solo puntos: si hay más de uno, son miles
            var dots = s.split('.');
            if (dots.length > 2) s = s.replace(/\./g, '');
            else if (dots.length === 2 && dots[1].length === 3 && decimals !== 3) s = s.replace(/\./g, '');
        }
        var n = parseFloat(s);
        return isNaN(n) ? 0 : n;
    }

    function currentCartTotal() {
        if (items && items.length) {
            var sub = 0;
            items.forEach(function (it) { sub += Number(it.subtotal) || 0; });
            var pct = discountPercent();
            return Math.max(0, sub - (sub * (pct / 100)));
        }
        return Number(cartTotal) || 0;
    }

    function isCashPayment() {
        var m = elPayMethod ? String(elPayMethod.value || 'cash') : 'cash';
        return m === 'cash';
    }

    function syncCashChangeVisibility() {
        elCashBlock = document.getElementById('posCashChangeBlock');
        elCashReceived = document.getElementById('posCashReceived');
        elCashChange = document.getElementById('posCashChange');
        elPayMethod = document.getElementById('posPayMethod');
        if (!elCashBlock) return;
        elCashBlock.style.display = isCashPayment() ? '' : 'none';
        if (!isCashPayment() && elCashReceived) elCashReceived.value = '';
        updateCashChange();
    }

    function updateCashChange() {
        elCashReceived = document.getElementById('posCashReceived') || elCashReceived;
        elCashChange = document.getElementById('posCashChange') || elCashChange;
        if (!elCashChange) return;

        if (!isCashPayment()) {
            elCashChange.textContent = money(0);
            elCashChange.classList.remove('is-negative', 'is-ready');
            return;
        }

        var total = currentCartTotal();
        cartTotal = total;
        var received = parseMoneyInput(elCashReceived ? elCashReceived.value : 0);
        if (received < 0) received = 0;
        var change = received - total;

        elCashChange.classList.toggle('is-negative', received > 0 && change < 0);
        elCashChange.classList.toggle('is-ready', received > 0 && change >= 0);

        if (received > 0 && change < 0) {
            elCashChange.textContent = 'Faltan ' + money(Math.abs(change));
        } else {
            elCashChange.textContent = money(Math.max(0, change));
        }
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

    function syncDiscountFromPreset() {
        if (!elDiscountPreset || !elDiscount) return;
        var v = elDiscountPreset.value;
        if (v === 'other' || v === '__other__') {
            // SoftNovaCustomSelect pide el % y lo guarda permanente.
            if (window.SoftNovaCustomSelect) {
                if (elDiscountOtherWrap) elDiscountOtherWrap.style.display = 'none';
                return;
            }
            if (elDiscountOtherWrap) elDiscountOtherWrap.style.display = '';
            elDiscount.focus();
            return;
        }
        if (elDiscountOtherWrap) elDiscountOtherWrap.style.display = 'none';
        elDiscount.value = String(v || '0');
        renderCart();
    }

    function discountPercent() {
        if (elDiscountPreset && elDiscountPreset.value !== 'other' && elDiscountPreset.value !== '__other__') {
            var preset = parseFloat(elDiscountPreset.value);
            if (!isNaN(preset)) return Math.max(0, Math.min(100, preset));
        }
        var p = elDiscount ? parseFloat(elDiscount.value) : 0;
        if (isNaN(p) || p < 0) p = 0;
        if (p > 100) p = 100;
        return p;
    }

    function resetDiscount() {
        if (elDiscountPreset) elDiscountPreset.value = '0';
        if (elDiscount) elDiscount.value = '0';
        if (elDiscountOtherWrap) elDiscountOtherWrap.style.display = 'none';
    }

    function togglePosCredit() {
        var credit = elPayType && elPayType.value === 'credit';
        if (elTermsGroup) elTermsGroup.style.display = credit ? '' : 'none';
        if (elInitialGroup) elInitialGroup.style.display = credit ? '' : 'none';
    }
    if (elPayType) elPayType.addEventListener('change', togglePosCredit);
    togglePosCredit();
    if (elPayMethod) elPayMethod.addEventListener('change', syncCashChangeVisibility);
    // Listeners robustos (input / enter / blur) + delegación
    document.addEventListener('input', function (e) {
        if (e.target && e.target.id === 'posCashReceived') updateCashChange();
    });
    document.addEventListener('keyup', function (e) {
        if (!e.target || e.target.id !== 'posCashReceived') return;
        updateCashChange();
        if (e.key === 'Enter') {
            e.preventDefault();
            updateCashChange();
        }
    });
    document.addEventListener('change', function (e) {
        if (e.target && e.target.id === 'posCashReceived') updateCashChange();
        if (e.target && e.target.id === 'posPayMethod') syncCashChangeVisibility();
    });
    syncCashChangeVisibility();

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
        var remaining = stock - cartQty(p.id);
        if (typeof showAlert === 'function') {
            var level = remaining <= 0 ? 'error' : (remaining <= 5 ? 'warning' : 'info');
            showAlert((p.name || 'Producto') + ' agregado. Restante en bodega: ' + remaining, level);
        }
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
            if (delta > 0 && typeof showAlert === 'function') {
                var remaining = row.stock - row.quantity;
                var level = remaining <= 0 ? 'error' : (remaining <= 5 ? 'warning' : 'info');
                showAlert(row.product_name + ' · Restante en bodega: ' + remaining, level);
            }
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
        if (typeof showAlert === 'function') {
            var remaining = row.stock - row.quantity;
            var level = remaining <= 0 ? 'error' : (remaining <= 5 ? 'warning' : 'info');
            showAlert(row.product_name + ' · Restante en bodega: ' + remaining, level);
        }
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
                var remaining = (parseInt(it.stock, 10) || 0) - it.quantity;
                var remColor = remaining <= 0 ? '#DC2626' : (remaining <= 5 ? '#B45309' : '#059669');
                var label = (it.code ? it.code + ' — ' : '') + it.product_name +
                    ' · Und ' + money(it.unit_price) + ' · Total ' + money(it.subtotal);
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td><input type="number" min="1" class="pos-qty" data-idx="' + idx + '" value="' + it.quantity + '"></td>' +
                    '<td><div class="pos-prod-label">' + esc(label) +
                    ' <span style="color:' + remColor + ';font-weight:600;">Restan ' + remaining + ' / ' + (it.stock || 0) + '</span></div></td>' +
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
        cartTotal = total;

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
            if (elCashReceived) elCashReceived.value = '';
        }
        updateCashChange();
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
                addProduct(p, 1);
                elSearch.focus();
            });
            elResults.appendChild(row);
        });
    }

    function handleSearchResults(products, q, opts) {
        if (q !== lastQuery) return;
        products = products || [];
        if (Offline && products.length) {
            Offline.upsertProducts(products);
        }
        if (opts.autoAddExact) {
            var exact = products.find(function (p) {
                return String(p.code || '').toLowerCase() === q.toLowerCase();
            });
            if (exact) {
                if (addProduct(exact, 1)) {
                    elSearch.value = '';
                    elResults.innerHTML = '<p class="pos-search-hint">Agregado: ' + esc(exact.name) + '. Escanee el siguiente…</p>';
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
    }

    function searchLocalFallback(q, opts, networkFailed) {
        if (!Offline) {
            elResults.innerHTML = '<p class="pos-search-hint">' +
                (networkFailed ? 'Sin red y sin catálogo local. Abra caja con internet al menos una vez.' : 'Error al buscar.') +
                '</p>';
            return;
        }
        Offline.searchLocal(q, 25).then(function (products) {
            if (q !== lastQuery) return;
            if (!products.length) {
                elResults.innerHTML = '<p class="pos-search-hint">Sin resultados locales para “' + esc(q) + '”' +
                    (networkFailed ? ' (modo offline)' : '') + '</p>';
                return;
            }
            handleSearchResults(products, q, opts);
            if (networkFailed && !opts.autoAddExact) {
                var note = document.createElement('p');
                note.className = 'pos-search-hint';
                note.style.color = '#92400E';
                note.textContent = 'Catálogo local (sin conexión)';
                elResults.insertBefore(note, elResults.firstChild);
            }
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
        if (Offline && !Offline.isOnline()) {
            searchLocalFallback(q, opts, true);
            return;
        }
        fetch(searchUrl + '&q=' + encodeURIComponent(q), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (d) {
                handleSearchResults((d && d.products) ? d.products : [], q, opts);
            })
            .catch(function (err) {
                if (Offline && Offline.isNetworkFailure(err)) {
                    searchLocalFallback(q, opts, true);
                    return;
                }
                searchLocalFallback(q, opts, false);
            });
    }

    if (elSearch) {
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
    }

    if (elClear && elSearch) {
        elClear.addEventListener('click', function () {
            elSearch.value = '';
            elResults.innerHTML = '<p class="pos-search-hint">Escriba o escanee un código para encontrar productos</p>';
            elSearch.focus();
        });
    }

    if (elDiscountPreset) {
        elDiscountPreset.addEventListener('change', syncDiscountFromPreset);
    }

    if (elDiscount) {
        elDiscount.addEventListener('input', renderCart);
        elDiscount.addEventListener('change', function () {
            var p = discountPercent();
            elDiscount.value = String(p);
            if (elDiscountPreset && elDiscountPreset.value === 'other') {
                renderCart();
            }
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

        var payMethod = (elPayMethod && elPayMethod.value) || 'cash';
        var payType = (elPayType && elPayType.value) || 'full';
        var online = Offline ? Offline.isOnline() : navigator.onLine !== false;

        if (!online && payType === 'credit') {
            if (typeof showAlert === 'function') {
                showAlert('Sin conexión no se permiten ventas a crédito. Use contado.', 'error');
            }
            return;
        }

        if (payMethod === 'cash') {
            var received = parseMoneyInput(elCashReceived ? elCashReceived.value : 0);
            cartTotal = currentCartTotal();
            if (received < cartTotal) {
                if (typeof showAlert === 'function') {
                    showAlert('En efectivo indique el billete/monto recibido (debe cubrir el total).', 'error');
                }
                if (elCashReceived) elCashReceived.focus();
                return;
            }
        }

        paying = true;
        var btn = document.getElementById('posConfirmPay');
        btn.disabled = true;

        var clientUuid = Offline ? Offline.uuid() : ('local-' + Date.now());
        var fd = new FormData();
        fd.append('csrf_token', currentCsrf());
        fd.append('client_sale_uuid', clientUuid);
        var customerId = document.getElementById('posCustomer').value;
        if (customerId) fd.append('customer_id', customerId);
        fd.append('payment_method', payMethod);
        fd.append('payment_type', payType);
        fd.append('document_type', 'invoice');
        fd.append('payment_terms', payType === 'credit'
            ? ((elPayTerms && elPayTerms.value) || 'net_30')
            : 'cash');
        fd.append('sale_date', new Date().toISOString().slice(0, 10));
        if (payType === 'credit' && elInitialPay) {
            fd.append('initial_payment', elInitialPay.value || '0');
        }
        var notes = 'Venta rápida desde caja';
        if (!online) notes += ' | Offline';
        if (payMethod === 'cash' && elCashReceived) {
            var recv = parseMoneyInput(elCashReceived.value);
            cartTotal = currentCartTotal();
            var change = Math.max(0, recv - cartTotal);
            notes += ' | Recibido: ' + recv + ' | Cambio: ' + change;
            fd.append('cash_received', String(recv));
            fd.append('cash_change', String(change));
        }
        fd.append('notes', notes);
        var pct = discountPercent();
        if (pct > 0) fd.append('discount_percent', String(pct));

        items.forEach(function (it, i) {
            fd.append('items[' + i + '][product_id]', it.product_id);
            fd.append('items[' + i + '][quantity]', it.quantity);
            fd.append('items[' + i + '][unit_price]', it.unit_price);
            fd.append('items[' + i + '][product_name]', it.product_name);
            fd.append('items[' + i + '][subtotal]', it.subtotal);
        });

        function buildOfflinePayload() {
            if (!Offline) return {};
            return Offline.normalizePayload(Offline.formDataToObject(fd));
        }

        function queueAndClear() {
            if (Offline) {
                Offline.enqueueSale(buildOfflinePayload(), clientUuid);
            }
            paying = false;
            btn.disabled = false;
            if (elInvoice) elInvoice.textContent = 'PEND-' + clientUuid.slice(0, 8).toUpperCase();
            clearCartAfterSale(
                'Sin conexión: venta guardada en cola local. Se sincronizará al volver online.',
                { alertType: 'warning', reload: false }
            );
            updateOfflineBanner();
        }

        function finishPayError(message) {
            paying = false;
            btn.disabled = false;
            if (typeof showAlert === 'function') showAlert(message || 'No se pudo registrar la venta', 'error');
        }

        if (!online) {
            if (!Offline) {
                finishPayError('Modo offline no disponible en este navegador');
                return;
            }
            Offline.validateLocalStock(items).then(function (check) {
                if (!check.ok) {
                    var first = check.conflicts[0];
                    finishPayError(
                        'Stock local insuficiente para ' + first.name +
                        ' (pedido ' + first.requested + ', disponible ' + first.available + ')'
                    );
                    return;
                }
                queueAndClear();
            }).catch(function () {
                queueAndClear();
            });
            return;
        }

        fetch(saleUrl, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (r) {
                return r.text().then(function (text) {
                    var d = null;
                    try { d = text ? JSON.parse(text) : null; } catch (e) { d = null; }
                    return { response: r, data: d, parseOk: !!d };
                });
            })
            .then(function (res) {
                if (res.parseOk && res.data && res.data.success) {
                    paying = false;
                    btn.disabled = false;
                    var msg = res.data.message || 'Venta registrada';
                    var inv = res.data.invoice_number || '';
                    var m = inv || (msg.match(/(FAC-|REM-|COT-)[A-Z0-9\-]+/i) || [])[0];
                    if (m && elInvoice) elInvoice.textContent = m;
                    clearCartAfterSale(msg, { reload: true });
                    return;
                }
                // Solo encolar ante fallo de red real; nunca ante error de negocio.
                var networkFail = Offline && (
                    Offline.isNetworkFailure(null, res.response) ||
                    (!res.parseOk && res.response && res.response.status === 0)
                );
                if (networkFail) {
                    queueAndClear();
                    return;
                }
                finishPayError((res.data && res.data.message) || 'No se pudo registrar la venta');
            })
            .catch(function (err) {
                if (Offline && Offline.isNetworkFailure(err)) {
                    queueAndClear();
                    return;
                }
                finishPayError('No se pudo registrar la venta');
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
        if (addProduct(p, 1)) { /* aviso de restante lo muestra addProduct */ }
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
        hasPanel: true,
        updateCashChange: updateCashChange,
        syncCashChangeVisibility: syncCashChangeVisibility
    };

    if (window.SoftNovaBarcode && typeof SoftNovaBarcode.onScan === 'function') {
        SoftNovaBarcode.onScan(function (code, product) {
            if (!product) return false;
            var ok = addProduct(product, 1);
            return !!ok;
        }, true);
    }

    renderCart();
    if (elSearch) elSearch.focus();
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
