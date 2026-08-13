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
    var thousands = panel.getAttribute('data-thousands') || '.';
    var decimalSep = panel.getAttribute('data-decimal') || ',';
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
        if (elMixedPay) elMixedPay.checked = false;
        if (elMixedRows) elMixedRows.innerHTML = '';
        syncMixedVisibility();
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
                product_type: target.getAttribute('data-type') || 'product',
                is_open_item: target.getAttribute('data-open-item') === '1' ? 1 : 0
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
    var elOpenItemBtn = document.getElementById('posOpenItemBtn');
    if (elOpenItemBtn) {
        elOpenItemBtn.addEventListener('click', function () {
            addProduct({
                id: parseInt(elOpenItemBtn.getAttribute('data-id') || '0', 10),
                name: elOpenItemBtn.getAttribute('data-name') || 'Producto comodín',
                code: elOpenItemBtn.getAttribute('data-code') || 'COMODIN',
                sale_price: 0,
                stock: 0,
                product_type: 'service',
                is_open_item: 1
            }, 1);
        });
    }
    var elDiscount = document.getElementById('posDiscountPercent');
    var elDelivery = document.getElementById('posDeliveryTotal') || document.getElementById('posDeliveryFee');
    var elDeliveryClient = document.getElementById('posDeliveryClient');
    var elDeliveryCompany = document.getElementById('posDeliveryCompany');
    var elDeliverySplit = document.getElementById('posDeliverySplit');
    var elSubLine = document.getElementById('posSubtotalLine');
    var elDiscLine = document.getElementById('posDiscountLine');
    var elDeliveryLine = document.getElementById('posDeliveryLine');
    var elDeliveryCompanyLine = document.getElementById('posDeliveryCompanyLine');
    var elSubtotal = document.getElementById('posSubtotal');
    var elDiscAmt = document.getElementById('posDiscountAmt');
    var elDeliveryAmt = document.getElementById('posDeliveryAmt');
    var elDeliveryCompanyAmt = document.getElementById('posDeliveryCompanyAmt');
    var deliverySplitManual = false;
    var deliverySplitSyncing = false;

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
    var elMixedPay = document.getElementById('posMixedPay');
    var elMixedWrap = document.getElementById('posMixedPayWrap');
    var elSinglePayWrap = document.getElementById('posSinglePayWrap');
    var elMixedRows = document.getElementById('posMixedRows');
    var elMixedSummary = document.getElementById('posMixedSummary');
    var elMixedAdd = document.getElementById('posMixedAdd');
    var cartTotal = 0;

    // Estado del carrito POS (obligatorio en strict mode)
    var items = [];
    var searchTimer = null;
    var lastQuery = '';
    var paying = false;

    function money(n) {
        if (window.SeriMoney && typeof SeriMoney.format === 'function') {
            return symbol + ' ' + SeriMoney.format(n, { decimals: decimals, thousands: thousands, decimal: decimalSep });
        }
        var x = Number(n) || 0;
        var fixed = x.toFixed(decimals);
        var parts = fixed.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousands);
        return symbol + ' ' + (decimals > 0 ? parts.join(decimalSep) : parts[0]);
    }

    function moneyPlain(n) {
        if (window.SeriMoney && typeof SeriMoney.format === 'function') {
            return SeriMoney.format(n, { decimals: decimals, thousands: thousands, decimal: decimalSep });
        }
        return String(Number(n) || 0);
    }

    function parseMoneyInput(raw) {
        if (window.SeriMoney && typeof SeriMoney.parse === 'function') {
            return SeriMoney.parse(raw);
        }
        var s = String(raw == null ? '' : raw).trim().replace(/\s/g, '');
        if (!s) return 0;
        if (s.indexOf(',') >= 0 && s.indexOf('.') >= 0) {
            if (s.lastIndexOf(',') > s.lastIndexOf('.')) {
                s = s.replace(/\./g, '').replace(',', '.');
            } else {
                s = s.replace(/,/g, '');
            }
        } else if (s.indexOf(',') >= 0) {
            var bits = s.split(',');
            s = bits.length === 2
                ? bits[0].replace(/\./g, '') + '.' + bits[1]
                : s.replace(/,/g, '');
        } else {
            var dots = s.split('.');
            if (dots.length > 2) s = s.replace(/\./g, '');
            else if (dots.length === 2 && dots[1].length === 3 && decimals !== 3) s = s.replace(/\./g, '');
        }
        var n = parseFloat(s);
        return isNaN(n) ? 0 : n;
    }

    function roundMoney(n) {
        return Math.round((Number(n) || 0) * 100) / 100;
    }

    function deliveryTotalFee() {
        var fee = elDelivery ? parseMoneyInput(elDelivery.value) : 0;
        if (isNaN(fee) || fee < 0) fee = 0;
        return roundMoney(fee);
    }

    /** Aporte empresa → gasto Transporte (no entra al total de la venta). */
    function deliveryCompanyFee() {
        if (elDeliveryCompany) {
            var company = parseMoneyInput(elDeliveryCompany.value);
            if (isNaN(company) || company < 0) company = 0;
            return roundMoney(Math.min(company, deliveryTotalFee()));
        }
        return 0;
    }

    /** Parte del cliente (total − empresa). */
    function deliveryClientFee() {
        if (elDeliveryClient) {
            var client = parseMoneyInput(elDeliveryClient.value);
            if (isNaN(client) || client < 0) client = 0;
            return roundMoney(Math.min(client, deliveryTotalFee()));
        }
        return roundMoney(Math.max(0, deliveryTotalFee() - deliveryCompanyFee()));
    }

    /** Solo la parte del cliente entra al total de la venta. */
    function deliveryFee() {
        return deliveryClientFee();
    }

    function suggestDeliverySplit(total) {
        total = roundMoney(total);
        var client = roundMoney(total / 2);
        var company = roundMoney(total - client);
        if (elDeliveryClient) elDeliveryClient.value = String(client);
        if (elDeliveryCompany) elDeliveryCompany.value = String(company);
    }

    function syncDeliverySplitVisibility() {
        var total = deliveryTotalFee();
        if (elDeliverySplit) {
            elDeliverySplit.style.display = total > 0.009 ? 'flex' : 'none';
        }
        if (total <= 0.009) {
            deliverySplitManual = false;
            if (elDeliveryClient) elDeliveryClient.value = '0';
            if (elDeliveryCompany) elDeliveryCompany.value = '0';
        } else if (!deliverySplitManual) {
            suggestDeliverySplit(total);
        }
    }

    function onDeliveryTotalChanged() {
        if (deliverySplitSyncing) return;
        deliverySplitManual = false;
        syncDeliverySplitVisibility();
        renderCart();
    }

    function onDeliveryClientChanged() {
        if (deliverySplitSyncing) return;
        deliverySplitManual = true;
        var total = deliveryTotalFee();
        var client = elDeliveryClient ? parseMoneyInput(elDeliveryClient.value) : 0;
        if (isNaN(client) || client < 0) client = 0;
        if (client > total) {
            client = total;
            deliverySplitSyncing = true;
            if (elDeliveryClient) elDeliveryClient.value = String(client);
            deliverySplitSyncing = false;
        }
        var company = roundMoney(Math.max(0, total - client));
        deliverySplitSyncing = true;
        if (elDeliveryCompany) elDeliveryCompany.value = String(company);
        deliverySplitSyncing = false;
        syncDeliverySplitVisibility();
        renderCart();
    }

    function onDeliveryCompanyChanged() {
        if (deliverySplitSyncing) return;
        deliverySplitManual = true;
        var total = deliveryTotalFee();
        var company = deliveryCompanyFee();
        if (company > total) {
            deliverySplitSyncing = true;
            if (elDelivery) elDelivery.value = String(company);
            deliverySplitSyncing = false;
            total = company;
        }
        var client = roundMoney(Math.max(0, total - company));
        deliverySplitSyncing = true;
        if (elDeliveryClient) elDeliveryClient.value = String(client);
        deliverySplitSyncing = false;
        syncDeliverySplitVisibility();
        renderCart();
    }

    function taxRatePct() {
        var r = Number(window.cajaTaxRate || 0);
        return isNaN(r) || r < 0 ? 0 : r;
    }

    function taxAmountOn(baseAfterDiscount) {
        var rate = taxRatePct();
        if (rate <= 0) return 0;
        return roundMoney((Number(baseAfterDiscount) || 0) * (rate / 100));
    }

    function currentCartTotal() {
        if (items && items.length) {
            var sub = 0;
            items.forEach(function (it) { sub += Number(it.subtotal) || 0; });
            var pct = discountPercent();
            var afterDisc = Math.max(0, sub - (sub * (pct / 100)));
            return afterDisc + taxAmountOn(afterDisc) + creditSurchargeAmount(afterDisc) + deliveryFee();
        }
        return Number(cartTotal) || 0;
    }

    function creditTermsDays() {
        if (!elPayType || elPayType.value !== 'credit') return 0;
        var t = (elPayTerms && elPayTerms.value) || 'net_30';
        if (t === 'net_15') return 15;
        if (t === 'net_30') return 30;
        if (t === 'net_60') return 60;
        if (t === 'net_90') return 90;
        if (t === 'net_120') return 120;
        return 30;
    }

    function creditSurchargeMatch(days) {
        var tiers = Array.isArray(window.cajaCreditSurchargeTiers) ? window.cajaCreditSurchargeTiers.slice() : [];
        if (!tiers.length) {
            var legacyDays = Number(window.cajaCreditSurchargeDays || 60);
            var legacyRate = Number(window.cajaCreditSurchargeRate || 0);
            if (!isNaN(legacyDays) && legacyDays >= 1 && legacyRate > 0) {
                tiers = [{ min_days: legacyDays, rate: legacyRate }];
            }
        }
        var best = null;
        tiers.forEach(function (t) {
            var min = Number(t.min_days != null ? t.min_days : t.days);
            var rate = Number(t.rate);
            if (isNaN(min) || isNaN(rate) || min < 1 || rate <= 0) return;
            if (days > min && (!best || min >= best.min_days)) {
                best = { min_days: min, rate: rate };
            }
        });
        return best;
    }

    function creditSurchargeRate() {
        var match = creditSurchargeMatch(creditTermsDays());
        return match ? match.rate : 0;
    }

    function creditSurchargeAmount(baseAfterDiscount) {
        var match = creditSurchargeMatch(creditTermsDays());
        if (!match) return 0;
        return roundMoney((Number(baseAfterDiscount) || 0) * (match.rate / 100));
    }

    function isWholesaleMode() {
        var el = document.getElementById('posWholesaleMode');
        return !!(el && el.checked);
    }

    function priceForProduct(p) {
        var retail = parseFloat(p.sale_price) || 0;
        var wholesale = parseFloat(p.wholesale_price) || 0;
        if (isWholesaleMode() && wholesale > 0.009) return wholesale;
        return retail;
    }

    function isMixedMode() {
        return !!(elMixedPay && elMixedPay.checked);
    }

    function methodOptionsHtml(selected) {
        var tpl = document.getElementById('posPayMethodOptionsTpl');
        var html = tpl ? tpl.innerHTML : '<option value="cash">Efectivo</option><option value="dataphone">Datáfono</option><option value="transfer">Transferencia</option>';
        if (!selected) return html;
        // Marca selected sin romper HTML de opciones.
        return html.replace(
            new RegExp('value="' + selected.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '"', 'i'),
            'value="' + selected + '" selected'
        );
    }

    function amountToCollect() {
        var payType = (elPayType && elPayType.value) || 'full';
        var total = currentCartTotal();
        if (payType === 'credit') {
            var init = parseMoneyInput(elInitialPay ? elInitialPay.value : 0);
            if (init < 0) init = 0;
            return Math.min(init, total);
        }
        return total;
    }

    function collectMixedSplits() {
        if (!elMixedRows) return [];
        var rows = [];
        elMixedRows.querySelectorAll('.pos-mixed-row').forEach(function (row) {
            var sel = row.querySelector('.pos-mixed-method');
            var amt = row.querySelector('.pos-mixed-amount');
            var method = sel ? String(sel.value || '') : '';
            var amount = parseMoneyInput(amt ? amt.value : 0);
            if (!method || amount <= 0) return;
            rows.push({ method: method, amount: Math.round(amount * 100) / 100 });
        });
        return rows;
    }

    function mixedCashPortion() {
        var sum = 0;
        collectMixedSplits().forEach(function (s) {
            if (s.method === 'cash') sum += s.amount;
        });
        return Math.round(sum * 100) / 100;
    }

    function mixedAssignedTotal() {
        var sum = 0;
        collectMixedSplits().forEach(function (s) { sum += s.amount; });
        return Math.round(sum * 100) / 100;
    }

    function updateMixedSummary() {
        if (!elMixedSummary) return;
        var need = amountToCollect();
        var assigned = mixedAssignedTotal();
        var left = Math.round((need - assigned) * 100) / 100;
        elMixedSummary.classList.remove('is-ok', 'is-bad');
        if (Math.abs(left) <= 0.02) {
            elMixedSummary.classList.add('is-ok');
            elMixedSummary.textContent = 'Completo · ' + money(assigned) + ' de ' + money(need);
        } else if (left > 0) {
            elMixedSummary.classList.add('is-bad');
            elMixedSummary.textContent = 'Faltan ' + money(left) + ' · Asignado ' + money(assigned) + ' / ' + money(need);
        } else {
            elMixedSummary.classList.add('is-bad');
            elMixedSummary.textContent = 'Sobra ' + money(Math.abs(left)) + ' · Asignado ' + money(assigned) + ' / ' + money(need);
        }
    }

    var mixedAmountSyncing = false;

    /** Como la división de domicilio: al editar un monto, el otro queda con el restante. */
    function syncMixedRemainder(changedInput) {
        if (mixedAmountSyncing || !elMixedRows || !isMixedMode()) return;
        var inputs = elMixedRows.querySelectorAll('.pos-mixed-amount');
        if (inputs.length < 2) {
            updateMixedSummary();
            return;
        }
        var need = amountToCollect();
        mixedAmountSyncing = true;
        if (inputs.length === 2) {
            var edited = (changedInput === inputs[1]) ? inputs[1] : inputs[0];
            var other = edited === inputs[0] ? inputs[1] : inputs[0];
            var val = parseMoneyInput(edited.value);
            if (isNaN(val) || val < 0) val = 0;
            if (val > need) val = need;
            var rest = roundMoney(Math.max(0, need - val));
            if (Math.abs(parseMoneyInput(edited.value) - val) > 0.001) edited.value = moneyPlain(val);
            other.value = moneyPlain(rest);
        } else {
            var last = inputs[inputs.length - 1];
            var sum = 0;
            var i;
            if (changedInput !== last) {
                for (i = 0; i < inputs.length - 1; i++) {
                    sum += parseMoneyInput(inputs[i].value);
                }
                last.value = moneyPlain(roundMoney(Math.max(0, need - sum)));
            } else {
                for (i = 1; i < inputs.length; i++) {
                    sum += parseMoneyInput(inputs[i].value);
                }
                inputs[0].value = moneyPlain(roundMoney(Math.max(0, need - sum)));
            }
        }
        mixedAmountSyncing = false;
        updateMixedSummary();
        syncCashChangeVisibility();
    }

    function addMixedRow(method, amount) {
        if (!elMixedRows) return;
        method = method || 'cash';
        var row = document.createElement('div');
        row.className = 'pos-mixed-row';
        row.innerHTML =
            '<div class="form-group pos-mixed-method-wrap" style="flex:1;min-width:150px;">' +
            '<label style="font-size:12px;">Método</label>' +
            '<select class="form-control pos-mixed-method">' + methodOptionsHtml(method) + '</select>' +
            '</div>' +
            '<div class="form-group">' +
            '<label style="font-size:12px;">Monto</label>' +
            '<input type="text" inputmode="decimal" class="form-control pos-mixed-amount money-input" data-money="1" value="' + (amount != null && amount !== '' ? moneyPlain(amount) : '') + '" placeholder="0">' +
            '</div>' +
            '<button type="button" class="btn btn-sm btn-danger pos-mixed-remove" title="Quitar">&times;</button>';
        elMixedRows.appendChild(row);
        var amtInput = row.querySelector('.pos-mixed-amount');
        if (amtInput && window.SeriMoney && typeof SeriMoney.enhance === 'function') {
            SeriMoney.enhance(amtInput);
        }
        var sel = row.querySelector('.pos-mixed-method');
        if (sel) sel.value = method;
        updateMixedSummary();
        syncCashChangeVisibility();
    }

    function seedMixedRows() {
        if (!elMixedRows) return;
        elMixedRows.innerHTML = '';
        var need = amountToCollect();
        var half = Math.round((need / 2) * 100) / 100;
        var rest = Math.round((need - half) * 100) / 100;
        addMixedRow('cash', half > 0 ? half : '');
        addMixedRow('dataphone', rest > 0 ? rest : '');
        updateMixedSummary();
    }

    function syncMixedVisibility() {
        elMixedPay = document.getElementById('posMixedPay') || elMixedPay;
        elMixedWrap = document.getElementById('posMixedPayWrap') || elMixedWrap;
        elSinglePayWrap = document.getElementById('posSinglePayWrap') || elSinglePayWrap;
        elMixedRows = document.getElementById('posMixedRows') || elMixedRows;
        var on = isMixedMode();
        if (elMixedWrap) {
            elMixedWrap.hidden = !on;
            elMixedWrap.style.display = on ? 'block' : 'none';
        }
        if (elSinglePayWrap) elSinglePayWrap.style.display = on ? 'none' : '';
        if (on && elMixedRows && !elMixedRows.children.length) {
            seedMixedRows();
        }
        syncCashChangeVisibility();
        updateMixedSummary();
    }

    function isCashPayment() {
        if (isMixedMode()) {
            return mixedCashPortion() > 0.009;
        }
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

        var cashDue = isMixedMode() ? mixedCashPortion() : currentCartTotal();
        // En crédito con un solo método, el billete se valida contra el abono si aplica.
        if (!isMixedMode()) {
            var payType = (elPayType && elPayType.value) || 'full';
            if (payType === 'credit') {
                cashDue = amountToCollect();
            }
        }
        cartTotal = currentCartTotal();
        var received = parseMoneyInput(elCashReceived ? elCashReceived.value : 0);
        if (received < 0) received = 0;
        var change = received - cashDue;

        elCashChange.classList.toggle('is-negative', received > 0 && change < 0);
        elCashChange.classList.toggle('is-ready', received > 0 && change >= 0);
        if (received > 0 && change < 0) {
            elCashChange.textContent = 'Faltan ' + money(Math.abs(change));
        } else {
            elCashChange.textContent = money(Math.max(0, change));
        }
        if (isMixedMode() && cashDue > 0) {
            elCashChange.title = 'Vueltas sobre la parte en efectivo (' + money(cashDue) + ')';
        } else {
            elCashChange.title = '';
        }
        if (isMixedMode() && elMixedRows) {
            var firstMixedAmt = elMixedRows.querySelector('.pos-mixed-amount');
            if (firstMixedAmt && elMixedRows.querySelectorAll('.pos-mixed-amount').length >= 2) {
                syncMixedRemainder(firstMixedAmt);
            } else {
                updateMixedSummary();
            }
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
        deliverySplitManual = false;
        if (elDelivery) elDelivery.value = '0';
        if (elDeliveryClient) elDeliveryClient.value = '0';
        if (elDeliveryCompany) elDeliveryCompany.value = '0';
        if (elDeliverySplit) elDeliverySplit.style.display = 'none';
    }

    function togglePosCredit() {
        var credit = elPayType && elPayType.value === 'credit';
        if (elTermsGroup) elTermsGroup.style.display = credit ? '' : 'none';
        if (elInitialGroup) elInitialGroup.style.display = credit ? '' : 'none';
        renderCart();
    }
    if (elPayType) elPayType.addEventListener('change', togglePosCredit);
    if (elPayTerms) elPayTerms.addEventListener('change', function () { renderCart(); });
    togglePosCredit();
    var elWholesale = document.getElementById('posWholesaleMode');
    if (elWholesale) {
        elWholesale.addEventListener('change', function () {
            // Recalcular precios de ítems ya en carrito según modo.
            items.forEach(function (it) {
                var retail = Number(it.retail_price != null ? it.retail_price : it.unit_price) || 0;
                var wholesale = Number(it.wholesale_price) || 0;
                it.retail_price = retail;
                var next = (elWholesale.checked && wholesale > 0.009) ? wholesale : retail;
                it.unit_price = next;
                it.subtotal = roundMoney(next * (Number(it.quantity) || 0));
            });
            renderCart();
        });
    }
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
    if (elMixedPay) {
        elMixedPay.addEventListener('change', syncMixedVisibility);
        elMixedPay.addEventListener('click', function () {
            setTimeout(syncMixedVisibility, 0);
        });
    }
    document.addEventListener('change', function (e) {
        if (e.target && e.target.id === 'posCashReceived') updateCashChange();
        if (e.target && e.target.id === 'posPayMethod') syncCashChangeVisibility();
        if (e.target && e.target.id === 'posMixedPay') syncMixedVisibility();
        if (e.target && e.target.classList.contains('pos-mixed-method')) {
            updateMixedSummary();
            syncCashChangeVisibility();
        }
        if (e.target && e.target.classList.contains('pos-mixed-amount')) {
            syncMixedRemainder(e.target);
        }
        if (e.target && (e.target.id === 'posPaymentType' || e.target.id === 'posInitialPayment')) {
            if (isMixedMode() && elMixedRows) {
                var firstAmt = elMixedRows.querySelector('.pos-mixed-amount');
                if (firstAmt) syncMixedRemainder(firstAmt);
                else updateMixedSummary();
            } else {
                updateMixedSummary();
            }
            updateCashChange();
        }
    });
    document.addEventListener('input', function (e) {
        if (e.target && e.target.classList.contains('pos-mixed-amount')) {
            syncMixedRemainder(e.target);
        }
    });
    if (elMixedAdd) {
        elMixedAdd.addEventListener('click', function () {
            var left = Math.round((amountToCollect() - mixedAssignedTotal()) * 100) / 100;
            addMixedRow('dataphone', left > 0 ? left : '');
            var lastAmt = elMixedRows && elMixedRows.querySelector('.pos-mixed-row:last-child .pos-mixed-amount');
            if (lastAmt) lastAmt.focus();
        });
    }
    if (elMixedRows) {
        elMixedRows.addEventListener('click', function (e) {
            var btn = e.target.closest('.pos-mixed-remove');
            if (!btn) return;
            var row = btn.closest('.pos-mixed-row');
            if (row && elMixedRows.querySelectorAll('.pos-mixed-row').length > 1) {
                row.remove();
                var firstAmt = elMixedRows.querySelector('.pos-mixed-amount');
                if (firstAmt && elMixedRows.querySelectorAll('.pos-mixed-amount').length >= 2) {
                    syncMixedRemainder(firstAmt);
                } else {
                    updateMixedSummary();
                    syncCashChangeVisibility();
                }
            }
        });
    }
    syncCashChangeVisibility();
    syncMixedVisibility();

    function initPosCustomerCombobox() {
        var input = document.getElementById('posCustomerSearch');
        var hidden = document.getElementById('posCustomer');
        var list = document.getElementById('posCustomerList');
        if (!input || !hidden || !list || input._posReady) return;
        input._posReady = true;
        input.value = 'Cliente Final';
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
            input.value = li.getAttribute('data-label') || 'Cliente Final';
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
        if (input) input.value = label || 'Cliente Final';
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
        var isService = (p.product_type || p.type || '') === 'service';
        var openItem = typeof isOpenItemProduct === 'function' ? isOpenItemProduct(p) : false;

        // Comodín: siempre línea nueva con nombre/precio capturados.
        if (openItem) {
            if (typeof requestOpenItemDetails !== 'function') {
                if (typeof showAlert === 'function') showAlert('Actualice app.js para vender el comodín', 'error');
                return false;
            }
            requestOpenItemDetails(function (customName, customPrice) {
                items.push({
                    product_id: p.id,
                    product_name: customName,
                    code: p.code || 'COMODIN',
                    quantity: qty,
                    unit_price: customPrice,
                    retail_price: customPrice,
                    wholesale_price: customPrice,
                    subtotal: customPrice * qty,
                    stock: 0,
                    product_type: 'service',
                    is_open_item: true,
                    stock_oversell_reason: '',
                    stock_oversell_note: ''
                });
                renderCart();
                if (typeof showAlert === 'function') {
                    showAlert(customName + ' agregado (comodín · sin stock)', 'info');
                }
            });
            return false;
        }

        var inCart = cartQty(p.id);
        var nextQty = inCart + qty;

        function commit(reason, note) {
            var retail = parseFloat(p.sale_price) || 0;
            var wholesale = parseFloat(p.wholesale_price) || 0;
            var unit = priceForProduct(p);
            var existing = items.find(function (i) {
                return String(i.product_id) === String(p.id) && !i.is_open_item;
            });
            if (existing) {
                existing.quantity += qty;
                existing.subtotal = existing.quantity * existing.unit_price;
                existing.stock = stock;
                existing.retail_price = retail;
                existing.wholesale_price = wholesale;
                if (reason) {
                    existing.stock_oversell_reason = reason;
                    existing.stock_oversell_note = note || '';
                }
            } else {
                items.push({
                    product_id: p.id,
                    product_name: p.name,
                    code: p.code || '',
                    quantity: qty,
                    unit_price: unit,
                    retail_price: retail,
                    wholesale_price: wholesale,
                    subtotal: unit * qty,
                    stock: stock,
                    product_type: p.product_type || p.type || 'product',
                    stock_oversell_reason: reason || '',
                    stock_oversell_note: note || ''
                });
            }
            renderCart();
            var remaining = stock - cartQty(p.id);
            if (typeof showAlert === 'function') {
                var level = remaining < 0 ? 'warning' : (remaining <= 0 ? 'error' : (remaining <= 5 ? 'warning' : 'info'));
                var msg = (p.name || 'Producto') + ' agregado. Restante en bodega: ' + remaining;
                if (reason) msg += ' · Sin stock (' + oversellLabel(reason) + ')';
                showAlert(msg, level);
            }
            return true;
        }

        if (!isService && nextQty > Math.max(0, stock)) {
            requestOversellReason(p.name || 'Producto', stock, nextQty, function (reason, note) {
                commit(reason, note);
            });
            return false;
        }
        return commit('', '');
    }

    function oversellLabel(code) {
        var map = { cambio: 'Cambio', prestamo: 'Préstamo', venta_pendiente: 'Venta pendiente', otro: 'Otro' };
        return map[code] || code;
    }

    var oversellPending = null;

    function requestOversellReason(productName, available, requested, onOk) {
        var modal = document.getElementById('oversellModal');
        var info = document.getElementById('oversellInfo');
        var sel = document.getElementById('oversellReason');
        var note = document.getElementById('oversellNote');
        var noteGroup = document.getElementById('oversellNoteGroup');
        var btn = document.getElementById('oversellConfirmBtn');
        if (!modal || !sel || !btn) {
            // Fallback si el modal no cargó (cache vieja / deploy parcial).
            var reason = window.prompt(
                (productName || 'Producto') + ' sin stock (disp. ' + available + '). Motivo: cambio / prestamo / venta_pendiente / otro',
                'prestamo'
            );
            if (!reason) return;
            reason = String(reason).toLowerCase().trim().replace(/\s+/g, '_');
            var map = { cambio:1, prestamo:1, venta_pendiente:1, otro:1 };
            if (!map[reason]) reason = 'otro';
            var detail = '';
            if (reason === 'otro') {
                detail = window.prompt('Detalle del motivo', '') || '';
                if (!String(detail).trim()) {
                    if (typeof showAlert === 'function') showAlert('Debe indicar el detalle', 'error');
                    return;
                }
            }
            if (typeof onOk === 'function') onOk(reason, detail);
            return;
        }
        oversellPending = onOk;
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
                if (!reason) {
                    if (typeof showAlert === 'function') showAlert('Seleccione un motivo', 'error');
                    return;
                }
                if (reason === 'otro' && detail === '') {
                    if (typeof showAlert === 'function') showAlert('Escriba el detalle del motivo Otro', 'error');
                    return;
                }
                modal.style.display = 'none';
                var cb = oversellPending;
                oversellPending = null;
                if (typeof cb === 'function') cb(reason, detail);
            });
            sel._oversellBound = true;
        }
    }

    function bumpQty(idx, delta) {
        var row = items[idx];
        if (!row) return;
        var next = row.quantity + delta;
        if (next <= 0) {
            items.splice(idx, 1);
            renderCart();
            return;
        }
        var isService = (row.product_type || '') === 'service';
        var stockCap = Math.max(0, parseInt(row.stock, 10) || 0);

        function applyQty(q, reason, note) {
            row.quantity = q;
            row.subtotal = row.quantity * row.unit_price;
            if (reason) {
                row.stock_oversell_reason = reason;
                row.stock_oversell_note = note || '';
            } else if (q <= stockCap) {
                row.stock_oversell_reason = '';
                row.stock_oversell_note = '';
            }
            if (delta > 0 && typeof showAlert === 'function') {
                var remaining = (row.stock || 0) - row.quantity;
                var level = remaining < 0 ? 'warning' : (remaining <= 0 ? 'error' : (remaining <= 5 ? 'warning' : 'info'));
                showAlert(row.product_name + ' · Restante en bodega: ' + remaining, level);
            }
            renderCart();
        }

        if (!isService && next > stockCap && !row.stock_oversell_reason) {
            requestOversellReason(row.product_name, row.stock || 0, next, function (reason, note) {
                applyQty(next, reason, note);
            });
            return;
        }
        applyQty(next, row.stock_oversell_reason || '', row.stock_oversell_note || '');
    }

    function setQty(idx, value) {
        var row = items[idx];
        if (!row) return;
        var q = parseInt(value, 10);
        if (isNaN(q) || q < 1) q = 1;
        var isService = (row.product_type || '') === 'service' || !!row.is_open_item;
        if (!isService && q > Math.max(0, row.stock || 0) && !row.stock_oversell_reason) {
            requestOversellReason(row.product_name, row.stock || 0, q, function (reason, note) {
                row.quantity = q;
                row.subtotal = row.quantity * row.unit_price;
                row.stock_oversell_reason = reason;
                row.stock_oversell_note = note || '';
                renderCart();
            });
            return;
        }
        row.quantity = q;
        row.subtotal = row.quantity * row.unit_price;
        if (!row.is_open_item && typeof showAlert === 'function') {
            var remaining = (row.stock || 0) - row.quantity;
            var level = remaining < 0 ? 'warning' : (remaining <= 0 ? 'error' : (remaining <= 5 ? 'warning' : 'info'));
            showAlert(row.product_name + ' · Restante en bodega: ' + remaining, level);
        }
        renderCart();
    }

    function setUnitPrice(idx, value) {
        var row = items[idx];
        if (!row) return;
        var price = parseFloat(String(value).replace(',', '.'));
        if (isNaN(price) || price < 0) price = 0;
        row.unit_price = Math.round(price * 100) / 100;
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
                var remaining = (parseInt(it.stock, 10) || 0) - it.quantity;
                var remColor = remaining < 0 ? '#B45309' : (remaining <= 0 ? '#DC2626' : (remaining <= 5 ? '#B45309' : '#059669'));
                var name = it.is_open_item
                    ? it.product_name
                    : ((it.code ? it.code + ' — ' : '') + it.product_name);
                var oversellBadge = it.stock_oversell_reason
                    ? ' <span class="badge badge-warning" title="' + esc(it.stock_oversell_note || '') + '">Sin stock · ' + esc(oversellLabel(it.stock_oversell_reason)) + '</span>'
                    : '';
                if (it.is_open_item) {
                    oversellBadge += ' <span class="badge badge-info">Comodín</span>';
                }
                var stockHint = it.is_open_item
                    ? '<span style="color:var(--color-text-secondary);">Sin inventario</span>'
                    : '<span style="color:' + remColor + ';font-weight:600;">Restan ' + remaining + ' / ' + (it.stock || 0) + '</span>';
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td><input type="number" min="1" class="pos-qty" data-idx="' + idx + '" value="' + it.quantity + '"></td>' +
                    '<td><div class="pos-prod-label">' + esc(name) + oversellBadge +
                    ' ' + stockHint + '</div>' +
                    '<div class="pos-price-row">Precio und ' +
                    '<input type="number" min="0" step="any" class="pos-price" data-idx="' + idx + '" value="' + it.unit_price + '" title="Editar precio de venta">' +
                    ' · Total ' + money(it.subtotal) + '</div></td>' +
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
        var delivery = deliveryFee(); // solo parte cliente
        var deliveryCompany = deliveryCompanyFee();
        var deliveryClient = deliveryClientFee();
        var deliveryTotal = deliveryTotalFee();
        var afterDisc = Math.max(0, subtotal - discAmt);
        var taxAmt = taxAmountOn(afterDisc);
        var creditSurcharge = creditSurchargeAmount(afterDisc);
        var total = afterDisc + taxAmt + creditSurcharge + delivery;
        cartTotal = total;

        var showBreakdown = (pct > 0 && subtotal > 0) || taxAmt > 0 || delivery > 0 || deliveryCompany > 0 || creditSurcharge > 0;
        if (elSubLine && elSubtotal) {
            if (showBreakdown && subtotal > 0) {
                elSubLine.style.display = '';
                elSubtotal.textContent = money(subtotal);
            } else {
                elSubLine.style.display = 'none';
            }
        }
        if (elDiscLine && elDiscAmt) {
            if (pct > 0 && subtotal > 0) {
                elDiscLine.style.display = '';
                elDiscAmt.textContent = '-' + money(discAmt) + ' (' + pct + '%)';
            } else {
                elDiscLine.style.display = 'none';
            }
        }
        if (elDeliveryLine && elDeliveryAmt) {
            if (delivery > 0 || deliveryTotal > 0) {
                elDeliveryLine.style.display = '';
                elDeliveryAmt.textContent = '+' + money(delivery)
                    + (deliveryCompany > 0.009
                        ? ' (de ' + money(deliveryTotal) + ' · empresa ' + money(deliveryCompany) + ' no suma)'
                        : '');
            } else {
                elDeliveryLine.style.display = 'none';
            }
        }
        if (elDeliveryCompanyLine && elDeliveryCompanyAmt) {
            if (deliveryCompany > 0) {
                elDeliveryCompanyLine.style.display = '';
                elDeliveryCompanyAmt.textContent = money(deliveryCompany) + ' (gasto interno, no en total)';
            } else {
                elDeliveryCompanyLine.style.display = 'none';
            }
        }
        var elTaxLine = document.getElementById('posTaxLine');
        var elTaxAmt = document.getElementById('posTaxAmt');
        if (elTaxLine && elTaxAmt) {
            if (taxAmt > 0.009) {
                elTaxLine.style.display = '';
                elTaxAmt.textContent = '+' + money(taxAmt) + ' (' + taxRatePct() + '%)';
            } else {
                elTaxLine.style.display = 'none';
            }
        }
        var elCreditLine = document.getElementById('posCreditSurchargeLine');
        var elCreditAmt = document.getElementById('posCreditSurchargeAmt');
        if (elCreditLine && elCreditAmt) {
            if (creditSurcharge > 0.009) {
                var match = creditSurchargeMatch(creditTermsDays());
                elCreditLine.style.display = '';
                elCreditAmt.textContent = '+' + money(creditSurcharge)
                    + (match ? (' (>' + match.min_days + 'd · ' + match.rate + '%)') : '');
            } else {
                elCreditLine.style.display = 'none';
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
            var openItem = typeof isOpenItemProduct === 'function' && isOpenItemProduct(p);
            var row = document.createElement('div');
            row.className = 'pos-result-row';
            row.innerHTML =
                '<div class="pos-result-info">' +
                '<div class="pos-result-title">' + esc((p.code ? p.code + ' — ' : '') + p.name) + '</div>' +
                '<div class="pos-result-meta">' +
                (openItem
                    ? 'Ítem libre · nombre y precio al vender'
                    : ('Stock: ' + p.stock + ' · ' + money(p.sale_price))) +
                '</div>' +
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

    if (elDelivery) {
        elDelivery.addEventListener('input', onDeliveryTotalChanged);
        elDelivery.addEventListener('change', onDeliveryTotalChanged);
    }
    if (elDeliveryClient) {
        elDeliveryClient.addEventListener('input', onDeliveryClientChanged);
        elDeliveryClient.addEventListener('change', onDeliveryClientChanged);
    }
    if (elDeliveryCompany) {
        elDeliveryCompany.addEventListener('input', onDeliveryCompanyChanged);
        elDeliveryCompany.addEventListener('change', onDeliveryCompanyChanged);
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
        var qty = e.target.closest('.pos-qty');
        if (qty) {
            setQty(parseInt(qty.getAttribute('data-idx'), 10), qty.value);
            return;
        }
        var price = e.target.closest('.pos-price');
        if (price) {
            setUnitPrice(parseInt(price.getAttribute('data-idx'), 10), price.value);
        }
    });

    elBody.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        var price = e.target.closest('.pos-price');
        if (!price) return;
        e.preventDefault();
        price.blur();
    });

    elPayBtn.addEventListener('click', function () {
        if (!items.length) return;
        elPayBar.hidden = false;
        syncMixedVisibility();
        updateCashChange();
    });

    document.getElementById('posCancelPay').addEventListener('click', function () {
        elPayBar.hidden = true;
    });

    document.getElementById('posConfirmPay').addEventListener('click', function () {
        if (paying || !items.length) return;

        var payMethod = (elPayMethod && elPayMethod.value) || 'cash';
        var payType = (elPayType && elPayType.value) || 'full';
        var online = Offline ? Offline.isOnline() : navigator.onLine !== false;
        var mixed = isMixedMode();
        var splits = mixed ? collectMixedSplits() : [];
        var due = amountToCollect();

        if (!online && payType === 'credit') {
            if (typeof showAlert === 'function') {
                showAlert('Sin conexión no se permiten ventas a crédito. Use contado.', 'error');
            }
            return;
        }

        if (mixed) {
            if (splits.length < 2) {
                if (typeof showAlert === 'function') {
                    showAlert('En pago mixto agregue al menos 2 métodos con monto.', 'error');
                }
                return;
            }
            if (Math.abs(mixedAssignedTotal() - due) > 0.02) {
                if (typeof showAlert === 'function') {
                    showAlert('La suma de los métodos debe igualar ' + money(due) + '.', 'error');
                }
                return;
            }
            var cashPart = mixedCashPortion();
            if (cashPart > 0.009) {
                var receivedMix = parseMoneyInput(elCashReceived ? elCashReceived.value : 0);
                if (receivedMix < cashPart) {
                    if (typeof showAlert === 'function') {
                        showAlert('En la parte en efectivo indique el billete recibido (mínimo ' + money(cashPart) + ').', 'error');
                    }
                    if (elCashReceived) elCashReceived.focus();
                    return;
                }
            }
            payMethod = 'mixed';
        } else if (payMethod === 'cash') {
            var received = parseMoneyInput(elCashReceived ? elCashReceived.value : 0);
            cartTotal = currentCartTotal();
            var cashNeed = payType === 'credit' ? due : cartTotal;
            if (received < cashNeed) {
                if (typeof showAlert === 'function') {
                    showAlert('En efectivo indique el billete/monto recibido (debe cubrir ' + money(cashNeed) + ').', 'error');
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
        fd.append('payment_method', mixed ? (splits[0] && splits[0].method) || 'cash' : payMethod);
        fd.append('payment_type', payType);
        fd.append('document_type', 'invoice');
        fd.append('payment_terms', payType === 'credit'
            ? ((elPayTerms && elPayTerms.value) || 'net_30')
            : 'cash');
        fd.append('sale_date', new Date().toISOString().slice(0, 10));
        if (payType === 'credit' && elInitialPay) {
            fd.append('initial_payment', String(parseMoneyInput(elInitialPay.value || '0')));
        }
        var notes = 'Venta rápida desde caja';
        if (!online) notes += ' | Offline';
        if (mixed) {
            splits.forEach(function (s, i) {
                fd.append('payment_splits[' + i + '][method]', s.method);
                fd.append('payment_splits[' + i + '][amount]', String(s.amount));
            });
            notes += ' | Mixto: ' + splits.map(function (s) {
                return s.method + ' ' + s.amount;
            }).join(' + ');
            var cashPartNote = mixedCashPortion();
            if (cashPartNote > 0.009 && elCashReceived) {
                var recvM = parseMoneyInput(elCashReceived.value);
                var changeM = Math.max(0, recvM - cashPartNote);
                notes += ' | Recibido efectivo: ' + recvM + ' | Cambio: ' + changeM;
                fd.append('cash_received', String(recvM));
                fd.append('cash_change', String(changeM));
            }
        } else if (payMethod === 'cash' && elCashReceived) {
            var recv = parseMoneyInput(elCashReceived.value);
            cartTotal = currentCartTotal();
            var cashNeed2 = payType === 'credit' ? due : cartTotal;
            var change = Math.max(0, recv - cashNeed2);
            notes += ' | Recibido: ' + recv + ' | Cambio: ' + change;
            fd.append('cash_received', String(recv));
            fd.append('cash_change', String(change));
        }
        fd.append('notes', notes);
        var pct = discountPercent();
        if (pct > 0) fd.append('discount_percent', String(pct));
        var delivery = deliveryFee(); // cliente → total venta
        var deliveryCompany = deliveryCompanyFee();
        var deliveryTotal = deliveryTotalFee();
        if (deliveryTotal > 0.009 || delivery > 0.009 || deliveryCompany > 0.009) {
            fd.append('delivery_total', String(deliveryTotal > 0.009 ? deliveryTotal : roundMoney(delivery + deliveryCompany)));
            fd.append('delivery_fee', String(delivery));
            fd.append('delivery_company_fee', String(deliveryCompany));
        }
        if (isWholesaleMode()) fd.append('sale_channel', 'wholesale');
        var cs = creditSurchargeAmount(Math.max(0, (function () {
            var sub = 0;
            items.forEach(function (it) { sub += Number(it.subtotal) || 0; });
            var pct = discountPercent();
            return sub - (sub * (pct / 100));
        })()));
        if (cs > 0.009) fd.append('credit_surcharge', String(cs));

        items.forEach(function (it, i) {
            fd.append('items[' + i + '][product_id]', it.product_id);
            fd.append('items[' + i + '][quantity]', it.quantity);
            fd.append('items[' + i + '][unit_price]', it.unit_price);
            fd.append('items[' + i + '][product_name]', it.product_name);
            fd.append('items[' + i + '][subtotal]', it.subtotal);
            if (it.is_open_item) {
                fd.append('items[' + i + '][is_open_item]', '1');
            }
            if (it.stock_oversell_reason) {
                fd.append('items[' + i + '][stock_oversell_reason]', it.stock_oversell_reason);
                fd.append('items[' + i + '][stock_oversell_note]', it.stock_oversell_note || '');
            }
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

    // Últimos productos / ofertas sugeridas (click)
    function onSuggestedProductClick(e) {
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
            stock: parseInt(target.getAttribute('data-stock') || '0', 10),
            product_type: target.getAttribute('data-type') || 'product',
            is_open_item: target.getAttribute('data-open-item') === '1' ? 1 : 0
        };
        if (addProduct(p, 1)) { /* aviso de restante lo muestra addProduct */ }
    }
    elResults.addEventListener('click', onSuggestedProductClick);
    var elOffers = document.getElementById('posOfferSuggestions');
    if (elOffers) elOffers.addEventListener('click', onSuggestedProductClick);

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
