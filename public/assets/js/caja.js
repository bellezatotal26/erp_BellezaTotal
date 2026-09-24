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
        if (window.SeriCsrf && typeof SeriCsrf.get === 'function') {
            var t = SeriCsrf.get();
            if (t) return t;
        }
        var meta = document.querySelector('meta[name="csrf-token"]');
        return (meta && meta.getAttribute('content')) || csrf || '';
    }

    function withFreshCsrf() {
        if (window.SeriCsrf && typeof SeriCsrf.ensureFresh === 'function') {
            return SeriCsrf.ensureFresh(true).then(function (t) {
                return t || currentCsrf();
            }).catch(function () {
                return currentCsrf();
            });
        }
        return Promise.resolve(currentCsrf());
    }

    function updateOfflineBanner() {
        var banner = document.getElementById('posOfflineBanner');
        var net = document.getElementById('posNetworkBanner');
        var conflictBanner = document.getElementById('posConflictBanner');
        var conflictText = document.getElementById('posConflictBannerText');
        var staleBanner = document.getElementById('posCatalogStaleBanner');
        var n = Offline ? Offline.loadQueue().length : 0;
        var c = Offline ? Offline.loadConflicts().length : 0;
        var online = Offline ? Offline.isOnline() : navigator.onLine !== false;
        var serverDown = Offline && typeof Offline.isServerUnreachable === 'function'
            ? Offline.isServerUnreachable()
            : false;
        var degraded = !online || serverDown;

        if (banner) {
            if (n <= 0) {
                banner.hidden = true;
                banner.style.display = 'none';
                banner.textContent = '';
            } else {
                banner.hidden = false;
                banner.style.display = 'block';
                banner.textContent = 'Hay ' + n + ' venta(s) pendiente(s) de sincronizar. Use «Sincronizar ahora» cuando el servidor responda.';
            }
        }
        if (net) {
            if (!degraded) {
                net.hidden = true;
                net.style.display = 'none';
                net.textContent = '';
            } else {
                net.hidden = false;
                net.style.display = 'block';
                net.textContent = serverDown && online
                    ? 'Servidor no responde: puede vender de contado con el catálogo local. Las ventas quedan en cola hasta que el ERP vuelva.'
                    : 'Sin conexión: puede vender de contado con el catálogo local. Crédito, factura electrónica y cierre de caja quedan bloqueados.';
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
        if (staleBanner && Offline && typeof Offline.getCatalogMeta === 'function') {
            Offline.getCatalogMeta().then(function (meta) {
                var hours = Offline.catalogAgeHours ? Offline.catalogAgeHours(meta) : null;
                var count = meta && meta.count ? meta.count : 0;
                if (!count) {
                    staleBanner.hidden = false;
                    staleBanner.style.display = 'block';
                    staleBanner.textContent = 'Aún no hay catálogo local en este PC. Con internet, pulse «Sincronizar ahora» antes de un corte.';
                    return;
                }
                if (hours != null && hours >= 12) {
                    staleBanner.hidden = false;
                    staleBanner.style.display = 'block';
                    staleBanner.textContent = 'Catálogo local con ' + Math.floor(hours) + ' h de antigüedad ('
                        + (Offline.formatSyncedAt ? Offline.formatSyncedAt(meta.synced_at) : '')
                        + '). Conviene sincronizar al inicio del turno.';
                } else {
                    staleBanner.hidden = true;
                    staleBanner.style.display = 'none';
                    staleBanner.textContent = '';
                }
            }).catch(function () {});
        }
        applyOfflineUiGuards(!degraded, n, c);
    }

    function applyOfflineUiGuards(onlineOk, pending, conflicts) {
        pending = typeof pending === 'number' ? pending : (Offline ? Offline.loadQueue().length : 0);
        conflicts = typeof conflicts === 'number' ? conflicts : (Offline ? Offline.loadConflicts().length : 0);
        var payTypeEl = document.getElementById('posPaymentType');
        if (payTypeEl) {
            var creditOpt = payTypeEl.querySelector('option[value="credit"]');
            if (creditOpt) creditOpt.disabled = !onlineOk;
            if (!onlineOk && payTypeEl.value === 'credit') {
                payTypeEl.value = 'full';
                if (typeof togglePosCredit === 'function') togglePosCredit();
            }
        }
        var closeBtn = document.getElementById('btnOpenCloseCash');
        if (closeBtn) {
            closeBtn.disabled = !onlineOk || pending > 0 || conflicts > 0;
            closeBtn.title = closeBtn.disabled
                ? 'Disponible solo con servidor disponible, sin cola pendiente ni conflictos'
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
        if (elMixedPay) elMixedPay.checked = false;
        if (elMixedRows) elMixedRows.innerHTML = '';
        syncMixedVisibility();
        if (elSearch) {
            elSearch.value = '';
            elSearch.focus();
        }
        if (opts.reload) {
            var delay = parseInt(opts.reloadDelay, 10);
            if (isNaN(delay) || delay < 200) delay = 900;
            setTimeout(function () { window.location.reload(); }, delay);
        }
    }

    function syncPendingSales() {
        if (!Offline) {
            updateOfflineBanner();
            return Promise.resolve();
        }
        return withFreshCsrf().then(function (token) {
            return Offline.syncPendingSales(saleUrl, token, syncUrl, {
                refreshCsrf: function () {
                    return withFreshCsrf();
                }
            });
        }).then(function (res) {
            updateOfflineBanner();
            updatePwaStatus();
            if (res && res.csrf_token && window.SeriCsrf) {
                SeriCsrf.apply(res.csrf_token);
            }
            if (res.synced > 0 || (res.newConflicts && res.newConflicts.length)) {
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

    function refreshCatalog() {
        if (!Offline || !catalogUrl) return Promise.resolve();
        return Offline.syncCatalog(catalogUrl).then(function (res) {
            updatePwaStatus();
            updateOfflineBanner();
            return res;
        }).catch(function () {
            updatePwaStatus();
            updateOfflineBanner();
            return null;
        });
    }

    /**
     * Sync manual: catálogo ↓ + cola de ventas ↑.
     * Usar al abrir turno (preparar offline) o al recuperar el servidor.
     */
    function manualSyncAll() {
        var btn = document.getElementById('posSyncNowBtn');
        if (!Offline) {
            if (typeof showAlert === 'function') {
                showAlert('Modo offline no disponible en este navegador', 'error');
            }
            return Promise.resolve();
        }
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Sincronizando…';
        }
        var catalogRes = null;
        return refreshCatalog().then(function (cres) {
            catalogRes = cres;
            return syncPendingSales();
        }).then(function (sres) {
            updatePwaStatus();
            updateOfflineBanner();
            var parts = [];
            if (catalogRes && catalogRes.success) {
                parts.push('Catálogo: ' + (catalogRes.count || 0) + ' productos');
            } else if (catalogRes && (catalogRes.server_down || catalogRes.offline)) {
                parts.push('Catálogo: sin contacto con servidor (se mantiene el local)');
            } else if (catalogRes && !catalogRes.success) {
                parts.push('Catálogo: no actualizado');
            }
            if (sres && sres.synced > 0) {
                parts.push(sres.synced + ' venta(s) subidas');
            } else if (sres && sres.remaining > 0) {
                parts.push(sres.remaining + ' venta(s) aún en cola');
            } else {
                parts.push('Cola de ventas al día');
            }
            if (typeof showAlert === 'function') {
                var ok = catalogRes && catalogRes.success && !(sres && sres.server_down);
                showAlert(parts.join(' · '), ok ? 'success' : 'warning');
            }
            return { catalog: catalogRes, sales: sres };
        }).catch(function (err) {
            if (typeof showAlert === 'function') {
                showAlert('No se pudo sincronizar: ' + ((err && err.message) || 'error de red'), 'error');
            }
        }).then(function (out) {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Sincronizar ahora';
            }
            return out;
        });
    }

    var deferredInstallPrompt = null;
    var INSTALL_DISMISS_KEY = 'seri_pwa_install_dismissed';

    function showInstallBanner(show) {
        var banner = document.getElementById('posPwaInstallBanner');
        if (!banner) return;
        if (show) {
            banner.hidden = false;
            banner.style.display = 'flex';
        } else {
            banner.hidden = true;
            banner.style.display = 'none';
        }
    }

    function updatePwaStatus() {
        var el = document.getElementById('posPwaStatus');
        if (!el || !Offline) return;
        var online = Offline.isOnline();
        var serverDown = typeof Offline.isServerUnreachable === 'function' && Offline.isServerUnreachable();
        var pending = Offline.loadQueue().length;
        var conflicts = Offline.loadConflicts().length;
        var standalone = Offline.isStandaloneDisplay ? Offline.isStandaloneDisplay() : false;
        var parts = [];
        parts.push(standalone ? 'App instalada' : 'Modo navegador');
        if (!online) {
            parts.push('Sin conexión');
        } else if (serverDown) {
            parts.push('Servidor no responde');
        } else {
            parts.push('En línea');
        }
        if (pending > 0) parts.push(pending + ' venta(s) en cola');
        if (conflicts > 0) parts.push(conflicts + ' conflicto(s)');
        Offline.getCatalogMeta().then(function (meta) {
            if (meta && meta.count) {
                parts.push('Catálogo local: ' + meta.count + ' productos');
                if (meta.synced_at && Offline.formatSyncedAt) {
                    parts.push('última sync ' + Offline.formatSyncedAt(meta.synced_at));
                }
            } else {
                parts.push('Sin catálogo local');
            }
            el.textContent = parts.join(' · ');
            el.style.display = 'block';
        }).catch(function () {
            el.textContent = parts.join(' · ');
            el.style.display = 'block';
        });
    }

    function seedCatalogFromDom() {
        if (!Offline) return;
        var list = [];
        document.querySelectorAll('.pos-recent-product').forEach(function (target) {
            list.push({
                id: parseInt(target.getAttribute('data-id') || '0', 10),
                name: target.getAttribute('data-name') || '',
                code: target.getAttribute('data-code') || '',
                sale_price: parseFloat(target.getAttribute('data-price') || '0'),
                stock: parseInt(target.getAttribute('data-stock') || '0', 10),
                product_type: target.getAttribute('data-type') || 'product',
                tax_rate: parseFloat(target.getAttribute('data-tax-rate') || '0'),
                is_open_item: target.getAttribute('data-open-item') === '1' ? 1 : 0,
                ownership: target.getAttribute('data-ownership') || 'owned',
                is_consignment: target.getAttribute('data-ownership') === 'consignment' ? 1 : 0,
                consignor_id: parseInt(target.getAttribute('data-consignor-id') || '0', 10),
                purchase_price: parseFloat(target.getAttribute('data-cost') || '0')
            });
        });
        var consignSel = document.getElementById('posConsignmentSelect');
        if (consignSel) {
            Array.prototype.forEach.call(consignSel.options, function (opt) {
                var id = parseInt(opt.getAttribute('data-id') || opt.value || '0', 10);
                if (!id) return;
                list.push({
                    id: id,
                    name: opt.getAttribute('data-name') || '',
                    code: opt.getAttribute('data-code') || '',
                    sale_price: parseFloat(opt.getAttribute('data-price') || '0'),
                    wholesale_price: parseFloat(opt.getAttribute('data-wholesale') || '0'),
                    purchase_price: parseFloat(opt.getAttribute('data-cost') || '0'),
                    stock: parseInt(opt.getAttribute('data-stock') || '0', 10),
                    product_type: opt.getAttribute('data-type') || 'product',
                    tax_rate: (function () {
                        var raw = opt.getAttribute('data-tax-rate');
                        if (raw === null || raw === '') return 0;
                        var n = parseFloat(raw);
                        return isNaN(n) ? 0 : n;
                    })(),
                    ownership: 'consignment',
                    is_consignment: 1,
                    consignor_id: parseInt(opt.getAttribute('data-consignor-id') || '0', 10)
                });
            });
        }
        if (list.length) Offline.upsertProducts(list);
    }

    function setupPwaInstall() {
        var installBtn = document.getElementById('posPwaInstallBtn');
        var dismissBtn = document.getElementById('posPwaInstallDismiss');
        var dismissed = false;
        try { dismissed = localStorage.getItem(INSTALL_DISMISS_KEY) === '1'; } catch (e) {}

        if (Offline && Offline.isStandaloneDisplay && Offline.isStandaloneDisplay()) {
            showInstallBanner(false);
            return;
        }

        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            deferredInstallPrompt = e;
            if (!dismissed) showInstallBanner(true);
        });

        window.addEventListener('appinstalled', function () {
            deferredInstallPrompt = null;
            showInstallBanner(false);
            try { localStorage.removeItem(INSTALL_DISMISS_KEY); } catch (e) {}
            if (typeof showAlert === 'function') {
                showAlert('Seri Caja instalada. Puede abrirla desde el menú Inicio o el escritorio.', 'success');
            }
            updatePwaStatus();
        });

        if (installBtn) {
            installBtn.addEventListener('click', function () {
                if (!deferredInstallPrompt) {
                    if (typeof showAlert === 'function') {
                        showAlert('Use Chrome o Edge → menú ⋮ → “Instalar Seri Caja” o “Instalar aplicación”.', 'info');
                    }
                    return;
                }
                deferredInstallPrompt.prompt();
                deferredInstallPrompt.userChoice.then(function () {
                    deferredInstallPrompt = null;
                    showInstallBanner(false);
                }).catch(function () {
                    deferredInstallPrompt = null;
                });
            });
        }
        if (dismissBtn) {
            dismissBtn.addEventListener('click', function () {
                showInstallBanner(false);
                try { localStorage.setItem(INSTALL_DISMISS_KEY, '1'); } catch (e) {}
            });
        }
    }

    if (Offline) {
        Offline._onQueueChange = function () {
            updateOfflineBanner();
            updatePwaStatus();
        };
    }
    updateOfflineBanner();
    updatePwaStatus();
    setupPwaInstall();
    seedCatalogFromDom();
    refreshCatalog();
    window.addEventListener('online', function () {
        updateOfflineBanner();
        updatePwaStatus();
        syncPendingSales();
        refreshCatalog();
    });
    window.addEventListener('offline', function () {
        updateOfflineBanner();
        updatePwaStatus();
    });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && Offline && Offline.isOnline()) {
            syncPendingSales();
            refreshCatalog();
        }
        updatePwaStatus();
    });
    if (navigator.serviceWorker) {
        navigator.serviceWorker.addEventListener('message', function (event) {
            if (event.data && event.data.type === 'SERI_POS_SYNC') {
                syncPendingSales();
                refreshCatalog();
            }
        });
    }
    setTimeout(syncPendingSales, 1200);

    var conflictBtn = document.getElementById('posConflictReviewBtn');
    if (conflictBtn) {
        conflictBtn.addEventListener('click', renderConflictModal);
    }
    var syncNowBtn = document.getElementById('posSyncNowBtn');
    if (syncNowBtn) {
        syncNowBtn.addEventListener('click', function () {
            manualSyncAll();
        });
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
    var elConsignSelect = document.getElementById('posConsignmentSelect');
    if (elConsignSelect) {
        elConsignSelect.addEventListener('change', function () {
            var opt = elConsignSelect.options[elConsignSelect.selectedIndex];
            var pid = parseInt(elConsignSelect.value || '0', 10);
            if (!opt || !pid) return;
            addProduct({
                id: pid,
                name: opt.getAttribute('data-name') || opt.textContent || '',
                code: opt.getAttribute('data-code') || '',
                sale_price: parseFloat(opt.getAttribute('data-price') || '0') || 0,
                wholesale_price: parseFloat(opt.getAttribute('data-wholesale') || '0') || 0,
                purchase_price: parseFloat(opt.getAttribute('data-cost') || '0') || 0,
                stock: parseInt(opt.getAttribute('data-stock') || '0', 10) || 0,
                product_type: opt.getAttribute('data-type') || 'product',
                tax_rate: (function () {
                    var raw = opt.getAttribute('data-tax-rate');
                    if (raw === null || raw === '') return null;
                    var n = parseFloat(raw);
                    return isNaN(n) ? null : n;
                })(),
                ownership: 'consignment',
                is_consignment: 1,
                consignor_id: parseInt(opt.getAttribute('data-consignor-id') || '0', 10) || 0
            }, 1);
            elConsignSelect.value = '';
            if (elSearch) elSearch.focus();
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
    var elConfirmPay = document.getElementById('posConfirmPay');
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
        if (!isTaxCalculationEnabled()) return 0;
        var r = Number(window.cajaTaxRate || 0);
        if (isNaN(r) || r < 0) return 0;
        if (r > 100) r = 100;
        return Math.round(r * 100) / 100;
    }

    function isTaxCalculationEnabled() {
        if (window.cajaTaxEnabled === false || window.cajaTaxEnabled === 0 || window.cajaTaxEnabled === '0') {
            return false;
        }
        return true;
    }

    function isProductTaxEnabled() {
        if (!isTaxCalculationEnabled()) return false;
        return window.cajaProductTaxEnabled === true
            || window.cajaProductTaxEnabled === 1
            || window.cajaProductTaxEnabled === '1';
    }

    function resolveProductTaxRate(raw, isOpenItem) {
        if (!isTaxCalculationEnabled()) return 0;
        if (isOpenItem) {
            // Comodín: tax_rate ya viene fijado por el check del modal (0 o tasa global).
            if (raw == null || raw === '') return taxRatePct();
            var or = Number(raw);
            if (isNaN(or) || or < 0) return taxRatePct();
            if (or > 100) or = 100;
            return Math.round(or * 100) / 100;
        }
        if (!isProductTaxEnabled()) return 0;
        // Inventario / consignación: 0 es exento válido (no caer a la tasa global).
        if (raw === null || raw === undefined || raw === '') return taxRatePct();
        var r = Number(raw);
        if (isNaN(r) || r < 0) return taxRatePct();
        if (r > 100) r = 100;
        return Math.round(r * 100) / 100;
    }

    function itemTaxRate(it) {
        return resolveProductTaxRate(it && it.tax_rate, !!(it && it.is_open_item));
    }

    /**
     * IVA por línea (misma lógica que el servidor).
     * El descuento % del documento no reduce el IVA: se calcula sobre el precio completo.
     */
    function cartTaxBreakdown() {
        var lineTaxes = [];
        var rateMap = {};
        items.forEach(function (it) {
            var lineSub = Number(it.subtotal) || 0;
            var rate = itemTaxRate(it);
            rateMap[String(rate)] = true;
            // Base imponible = precio completo (sin descontar).
            lineTaxes.push(roundMoney(Math.max(0, lineSub) * (rate / 100)));
        });
        var tax = roundMoney(lineTaxes.reduce(function (a, b) { return a + b; }, 0));
        var lineSum = lineTaxes.reduce(function (a, b) { return a + b; }, 0);
        var drift = roundMoney(tax - lineSum);
        if (Math.abs(drift) >= 0.01 && lineTaxes.length) {
            lineTaxes[lineTaxes.length - 1] = roundMoney(lineTaxes[lineTaxes.length - 1] + drift);
            tax = roundMoney(lineTaxes.reduce(function (a, b) { return a + b; }, 0));
        }
        return {
            tax: tax,
            rates: Object.keys(rateMap).map(Number),
            lineTaxes: lineTaxes
        };
    }

    /** Totales del carrito alineados con el servidor (descuento e IVA redondeados). */
    function cartTotals() {
        var sub = 0;
        items.forEach(function (it) { sub += Number(it.subtotal) || 0; });
        sub = roundMoney(sub);
        var pct = discountPercent();
        var discount = roundMoney(sub * (pct / 100));
        var afterDisc = roundMoney(Math.max(0, sub - discount));
        var taxInfo = cartTaxBreakdown();
        var taxAmt = taxInfo.tax;
        var creditSurcharge = creditSurchargeAmount(afterDisc);
        var delivery = deliveryFee();
        return {
            subtotal: sub,
            discount: discount,
            discountPct: pct,
            taxable: afterDisc,
            tax: taxAmt,
            taxInfo: taxInfo,
            creditSurcharge: creditSurcharge,
            delivery: delivery,
            total: roundMoney(afterDisc + taxAmt + creditSurcharge + delivery)
        };
    }

    function taxAmountOn(baseAfterDiscount) {
        // Compat: si no hay ítems, usa tasa global sobre la base.
        if (!items || !items.length) {
            var rate = taxRatePct();
            if (rate <= 0) return 0;
            return roundMoney((Number(baseAfterDiscount) || 0) * (rate / 100));
        }
        return cartTaxBreakdown().tax;
    }

    function currentCartTotal() {
        if (items && items.length) {
            return cartTotals().total;
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

    function qtyDiscountMatch(qty) {
        var tiers = Array.isArray(window.cajaQtyDiscountTiers) ? window.cajaQtyDiscountTiers.slice() : [];
        var best = null;
        var q = Number(qty) || 0;
        tiers.forEach(function (t) {
            var min = Number(t.min_qty != null ? t.min_qty : (t.qty != null ? t.qty : t.min_quantity));
            var rate = Number(t.rate);
            if (isNaN(min) || isNaN(rate) || min < 1 || rate <= 0) return;
            if (q > min && (!best || min >= best.min_qty)) {
                best = { min_qty: min, rate: rate };
            }
        });
        return best;
    }

    function listPriceForItem(it) {
        var retail = Number(it.retail_price != null ? it.retail_price : it.unit_price) || 0;
        var wholesale = Number(it.wholesale_price) || 0;
        if (isWholesaleMode() && wholesale > 0.009) return wholesale;
        return retail;
    }

    /** Aplica descuento por cantidad al precio de lista (salvo precio manual o comodín). */
    function applyQtyDiscountToItem(it) {
        if (!it || it.is_open_item || it.price_manual) {
            it.qty_discount_pct = 0;
            it.qty_discount_min = 0;
            return it;
        }
        var base = listPriceForItem(it);
        var match = qtyDiscountMatch(it.quantity);
        if (match) {
            it.unit_price = roundMoney(base * (1 - match.rate / 100));
            it.qty_discount_pct = match.rate;
            it.qty_discount_min = match.min_qty;
        } else {
            it.unit_price = roundMoney(base);
            it.qty_discount_pct = 0;
            it.qty_discount_min = 0;
        }
        it.subtotal = roundMoney((Number(it.quantity) || 0) * (Number(it.unit_price) || 0));
        return it;
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

    function productPurchaseCost(pOrRow) {
        var c = parseFloat(pOrRow && (pOrRow.purchase_price != null ? pOrRow.purchase_price : pOrRow.unit_cost));
        return isNaN(c) ? 0 : Math.round(c * 100) / 100;
    }

    /** Venta debe superar el costo (importaciones mal cargadas). */
    function saleExceedsCost(salePrice, cost) {
        salePrice = Math.round((Number(salePrice) || 0) * 100) / 100;
        cost = Math.round((Number(cost) || 0) * 100) / 100;
        if (cost <= 0.009) return true;
        return salePrice > cost;
    }

    function costPriceBlockMessage(name, salePrice, cost) {
        return (name || 'Este producto')
            + ' no se puede vender: el precio de venta (' + money(salePrice)
            + ') no supera el costo (' + money(cost)
            + '). Corrija el producto en Inventario (posible error al importar).';
    }

    function assertProductPriceVsCost(p, overrideSale) {
        if (!p) return true;
        var openItem = typeof isOpenItemProduct === 'function' ? isOpenItemProduct(p) : !!(p.is_open_item);
        if (openItem) return true;
        var cost = productPurchaseCost(p);
        if (cost <= 0.009) return true;
        var retail = parseFloat(p.sale_price) || 0;
        var sale = overrideSale != null ? Number(overrideSale) : priceForProduct(p);
        if (!saleExceedsCost(retail, cost) || !saleExceedsCost(sale, cost)) {
            var bad = !saleExceedsCost(sale, cost) ? sale : retail;
            if (typeof showAlert === 'function') {
                showAlert(costPriceBlockMessage(p.name || p.product_name, bad, cost), 'error');
            }
            return false;
        }
        return true;
    }

    function isMixedMode() {
        return !!(elMixedPay && elMixedPay.checked);
    }

    function methodOptionsHtml(selected) {
        var tpl = document.getElementById('posPayMethodOptionsTpl');
        var html = tpl ? tpl.innerHTML : '<option value="cash">Efectivo</option><option value="debit_card">Tarjeta débito</option><option value="credit_card">Tarjeta crédito</option><option value="transfer">Transferencia</option>';
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
        addMixedRow('debit_card', rest > 0 ? rest : '');
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
        if (isMixedMode()) {
            updateMixedSummary();
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
                it.wholesale_price = wholesale;
                it.price_manual = false;
                applyQtyDiscountToItem(it);
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
            addMixedRow('debit_card', left > 0 ? left : '');
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
        var isConsignment = !!(p.is_consignment || p.ownership === 'consignment');

        // Comodín: siempre línea nueva con nombre/precio capturados.
        if (openItem) {
            if (typeof requestOpenItemDetails !== 'function') {
                if (typeof showAlert === 'function') showAlert('Actualice app.js para vender el comodín', 'error');
                return false;
            }
            requestOpenItemDetails(function (customName, customPrice, hasTax) {
                var rate = (hasTax === false) ? 0 : taxRatePct();
                items.push({
                    product_id: p.id,
                    product_name: customName,
                    code: p.code || 'COMODIN',
                    quantity: qty,
                    unit_price: customPrice,
                    retail_price: customPrice,
                    wholesale_price: customPrice,
                    subtotal: customPrice * qty,
                    tax_rate: rate,
                    stock: 0,
                    product_type: 'service',
                    is_open_item: true,
                    stock_oversell_reason: '',
                    stock_oversell_note: ''
                });
                renderCart();
                if (typeof showAlert === 'function') {
                    showAlert(
                        customName + ' agregado (comodín · ' + (rate > 0 ? 'con IVA' : 'sin IVA') + ' · sin stock)',
                        'info'
                    );
                }
            });
            return false;
        }

        if (!assertProductPriceVsCost(p)) {
            return false;
        }

        var inCart = cartQty(p.id);
        var nextQty = inCart + qty;

        function commit(reason, note) {
            var retail = parseFloat(p.sale_price) || 0;
            var wholesale = parseFloat(p.wholesale_price) || 0;
            var unit = priceForProduct(p);
            var cost = productPurchaseCost(p);
            var existing = items.find(function (i) {
                return String(i.product_id) === String(p.id) && !i.is_open_item;
            });
            if (existing) {
                existing.quantity += qty;
                existing.stock = stock;
                existing.retail_price = retail;
                existing.wholesale_price = wholesale;
                existing.purchase_price = cost;
                existing.price_manual = false;
                existing.is_consignment = isConsignment;
                existing.ownership = isConsignment
                    ? 'consignment'
                    : ((p.ownership === 'borrowed') ? 'borrowed' : 'owned');
                existing.consignor_id = Number(p.consignor_id) || 0;
                existing.tax_rate = resolveProductTaxRate(
                    (p.tax_rate != null && p.tax_rate !== '') ? p.tax_rate : existing.tax_rate,
                    false
                );
                if (reason) {
                    existing.stock_oversell_reason = reason;
                    existing.stock_oversell_note = note || '';
                }
                applyQtyDiscountToItem(existing);
                if (!assertProductPriceVsCost({
                    name: existing.product_name,
                    sale_price: existing.retail_price,
                    wholesale_price: existing.wholesale_price,
                    purchase_price: existing.purchase_price,
                    is_open_item: false
                }, existing.unit_price)) {
                    existing.quantity -= qty;
                    if (existing.quantity <= 0) {
                        items = items.filter(function (i) { return i !== existing; });
                    } else {
                        applyQtyDiscountToItem(existing);
                    }
                    renderCart();
                    return false;
                }
            } else {
                var row = {
                    product_id: p.id,
                    product_name: p.name,
                    code: p.code || '',
                    quantity: qty,
                    unit_price: unit,
                    retail_price: retail,
                    wholesale_price: wholesale,
                    purchase_price: cost,
                    subtotal: unit * qty,
                    tax_rate: resolveProductTaxRate(p.tax_rate, false),
                    stock: stock,
                    product_type: p.product_type || p.type || 'product',
                    is_consignment: isConsignment,
                    ownership: isConsignment
                        ? 'consignment'
                        : ((p.ownership === 'borrowed') ? 'borrowed' : 'owned'),
                    consignor_id: Number(p.consignor_id) || 0,
                    stock_oversell_reason: reason || '',
                    stock_oversell_note: note || '',
                    price_manual: false,
                    qty_discount_pct: 0,
                    qty_discount_min: 0
                };
                applyQtyDiscountToItem(row);
                if (!assertProductPriceVsCost(p, row.unit_price)) {
                    return false;
                }
                items.push(row);
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
            if (isConsignment) {
                if (typeof showAlert === 'function') {
                    showAlert(
                        (p.name || 'Producto') + ': stock consignado insuficiente (disp. ' + stock + '). No se permite sobreventa.',
                        'error'
                    );
                }
                return false;
            }
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
            row.price_manual = false;
            if (reason) {
                row.stock_oversell_reason = reason;
                row.stock_oversell_note = note || '';
            } else if (q <= stockCap) {
                row.stock_oversell_reason = '';
                row.stock_oversell_note = '';
            }
            applyQtyDiscountToItem(row);
            if (delta > 0 && typeof showAlert === 'function') {
                var remaining = (row.stock || 0) - row.quantity;
                var level = remaining < 0 ? 'warning' : (remaining <= 0 ? 'error' : (remaining <= 5 ? 'warning' : 'info'));
                showAlert(row.product_name + ' · Restante en bodega: ' + remaining, level);
            }
            renderCart();
        }

        if (!isService && next > stockCap && !row.stock_oversell_reason) {
            if (row.is_consignment) {
                if (typeof showAlert === 'function') {
                    showAlert(row.product_name + ': stock consignado insuficiente. No se permite sobreventa.', 'error');
                }
                return;
            }
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
            if (row.is_consignment) {
                if (typeof showAlert === 'function') {
                    showAlert(row.product_name + ': stock consignado insuficiente. No se permite sobreventa.', 'error');
                }
                return;
            }
            requestOversellReason(row.product_name, row.stock || 0, q, function (reason, note) {
                row.quantity = q;
                row.price_manual = false;
                row.stock_oversell_reason = reason;
                row.stock_oversell_note = note || '';
                applyQtyDiscountToItem(row);
                renderCart();
            });
            return;
        }
        row.quantity = q;
        row.price_manual = false;
        applyQtyDiscountToItem(row);
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
        price = Math.round(price * 100) / 100;
        var cost = productPurchaseCost(row);
        if (!row.is_open_item && !saleExceedsCost(price, cost)) {
            if (typeof showAlert === 'function') {
                showAlert(costPriceBlockMessage(row.product_name, price, cost), 'error');
            }
            renderCart();
            return;
        }
        row.unit_price = price;
        // Precio editado a mano: se toma como nuevo precio de lista (sin auto-descuento).
        row.price_manual = true;
        row.retail_price = price;
        row.wholesale_price = price;
        row.qty_discount_pct = 0;
        row.qty_discount_min = 0;
        row.subtotal = roundMoney(row.quantity * row.unit_price);
        renderCart();
    }

    function renderCart() {
        var subtotal = 0;
        var count = 0;
        if (!items.length) {
            elBody.innerHTML = '<tr class="pos-empty-row"><td colspan="3">Escanee o busque productos a la derecha</td></tr>';
            elPayBtn.disabled = true;
            if (elConfirmPay) elConfirmPay.disabled = true;
        } else {
            elBody.innerHTML = '';
            items.forEach(function (it, idx) {
                applyQtyDiscountToItem(it);
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
                if (it.is_consignment) {
                    oversellBadge += ' <span class="badge badge-info">Consignación</span>';
                }
                if (it.qty_discount_pct > 0) {
                    oversellBadge += ' <span class="badge badge-success" title="Descuento por cantidad (&gt;' + (it.qty_discount_min || 0) + ' uds)">-' + it.qty_discount_pct + '%</span>';
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
            if (elConfirmPay) elConfirmPay.disabled = false;
        }

        var totals = cartTotals();
        var pct = totals.discountPct;
        var discAmt = totals.discount;
        var delivery = totals.delivery;
        var deliveryCompany = deliveryCompanyFee();
        var deliveryClient = deliveryClientFee();
        var deliveryTotal = deliveryTotalFee();
        var afterDisc = totals.taxable;
        var taxInfo = totals.taxInfo;
        var taxAmt = totals.tax;
        var creditSurcharge = totals.creditSurcharge;
        var total = totals.total;
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
                var rateLabel = (taxInfo.rates && taxInfo.rates.length === 1)
                    ? (taxInfo.rates[0] + '%')
                    : 'mixto';
                elTaxAmt.textContent = '+' + money(taxAmt) + ' (' + rateLabel + ')';
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
        if (isMixedMode() && elMixedRows && elMixedRows.querySelectorAll('.pos-mixed-row').length) {
            var mixedAmts = elMixedRows.querySelectorAll('.pos-mixed-amount');
            if (mixedAmts.length >= 2) {
                syncMixedRemainder(mixedAmts[0]);
            } else {
                updateMixedSummary();
            }
        }
        updateCashChange();
    }

    function truncateText(str, maxLen) {
        str = String(str || '').replace(/\s+/g, ' ').trim();
        if (!str) return '';
        maxLen = maxLen || 60;
        if (str.length <= maxLen) return str;
        return str.slice(0, maxLen - 1).trim() + '…';
    }

    function renderResults(list, q) {
        if (!list.length) {
            elResults.innerHTML = '<p class="pos-search-hint">Sin resultados para “' + esc(q) + '”</p>';
            return;
        }
        elResults.innerHTML = '';
        list.forEach(function (p) {
            var openItem = typeof isOpenItemProduct === 'function' && isOpenItemProduct(p);
            var desc = truncateText(p.description || '', 60);
            var cost = productPurchaseCost(p);
            var priceBad = !openItem && !saleExceedsCost(parseFloat(p.sale_price) || 0, cost);
            var meta = '';
            if (openItem) {
                meta = 'Ítem libre · nombre y precio al vender';
            } else {
                meta = 'Stock: ' + p.stock + ' · ' + money(p.sale_price);
                if (cost > 0.009) meta += ' · Costo ' + money(cost);
                if (priceBad) meta += ' · <span class="badge badge-danger">Venta ≤ costo</span>';
                if (p.is_consignment || p.ownership === 'consignment') {
                    meta += ' · <span class="badge badge-info">Consignación</span>';
                } else if (p.ownership === 'borrowed') {
                    meta += ' · <span class="badge badge-info">Prestado</span>';
                }
            }
            var row = document.createElement('div');
            row.className = 'pos-result-row' + (priceBad ? ' pos-result-price-bad' : '');
            row.innerHTML =
                '<div class="pos-result-info">' +
                '<div class="pos-result-title">' + esc((p.code ? p.code + ' — ' : '') + p.name) + '</div>' +
                (desc ? '<div class="pos-result-desc">' + esc(desc) + '</div>' : '') +
                '<div class="pos-result-meta">' + meta + '</div>' +
                '</div>' +
                '<button type="button" class="pos-result-add" title="' +
                (priceBad ? 'Precio inválido: editar en Inventario' : 'Agregar') + '"' +
                (priceBad ? ' style="opacity:.55"' : '') + '>+</button>';
            row.querySelector('.pos-result-add').addEventListener('click', function () {
                addProduct(p, 1);
                elSearch.focus();
            });
            elResults.appendChild(row);
        });
    }

    function codesMatch(a, b) {
        var aa = String(a || '').trim().toLowerCase();
        var bb = String(b || '').trim().toLowerCase();
        if (aa && bb && aa === bb) return true;
        var da = String(a || '').replace(/\D/g, '');
        var db = String(b || '').replace(/\D/g, '');
        if (!da || !db || da.length < 8 || db.length < 8) return false;
        if (da === db) return true;
        if (da.length === 12 && db.length === 13 && db === '0' + da) return true;
        if (db.length === 12 && da.length === 13 && da === '0' + db) return true;
        return false;
    }

    function looksLikeBarcodeQuery(q) {
        var d = String(q || '').replace(/\D/g, '');
        return d.length >= 8 && d.length <= 14 && /^\d+$/.test(d);
    }

    function handleSearchResults(products, q, opts) {
        if (q !== lastQuery) return;
        products = products || [];
        if (Offline && products.length) {
            Offline.upsertProducts(products);
        }
        if (opts.autoAddExact) {
            var exact = products.find(function (p) {
                return codesMatch(p.code, q) || codesMatch(p.barcode, q);
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
                    (networkFailed ? ' (sincronice el catálogo con «Sincronizar ahora»)' : '') + '</p>';
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
        var forceLocal = Offline && (
            (typeof Offline.shouldUseLocalQueue === 'function' && Offline.shouldUseLocalQueue())
            || !Offline.isOnline()
        );
        if (forceLocal) {
            searchLocalFallback(q, opts, true);
            return;
        }
        fetch(searchUrl + '&q=' + encodeURIComponent(q), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (r) {
                if (!r.ok) {
                    var err = new Error('HTTP ' + r.status);
                    err.response = r;
                    throw err;
                }
                return r.json();
            })
            .then(function (d) {
                if (Offline && typeof Offline.markServerReachable === 'function') {
                    Offline.markServerReachable(true);
                }
                var products = (d && d.products) ? d.products : [];
                // Si el servidor no trajo nada para un EAN, probar catálogo local (puede estar más fresco tras sync).
                if (!products.length && looksLikeBarcodeQuery(q) && Offline) {
                    return Offline.searchLocal(q, 25).then(function (local) {
                        if (q !== lastQuery) return;
                        if (local && local.length) {
                            handleSearchResults(local, q, opts);
                            return;
                        }
                        handleSearchResults([], q, opts);
                    });
                }
                handleSearchResults(products, q, opts);
            })
            .catch(function (err) {
                if (Offline && Offline.isNetworkFailure(err, err && err.response)) {
                    if (typeof Offline.markServerReachable === 'function') {
                        Offline.markServerReachable(false);
                    }
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
        syncMixedVisibility();
        updateCashChange();
        if (elPayBar) {
            try {
                elPayBar.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } catch (e) {
                elPayBar.scrollIntoView(false);
            }
        }
        var focusPay = elConfirmPay || elPayMethod;
        if (focusPay && typeof focusPay.focus === 'function') focusPay.focus();
    });

    function resetPosSaleBox() {
        items = [];
        resetDiscount();
        if (elCashReceived) elCashReceived.value = '';
        if (elPayType) elPayType.value = 'full';
        if (elPayTerms) elPayTerms.value = 'net_30';
        if (elInitialPay) elInitialPay.value = '0';
        if (elPayMethod) elPayMethod.value = 'cash';
        if (elMixedPay) elMixedPay.checked = false;
        if (elMixedRows) elMixedRows.innerHTML = '';
        if (elWholesale) elWholesale.checked = false;
        if (typeof togglePosCredit === 'function') togglePosCredit();
        syncMixedVisibility();
        syncCashChangeVisibility();
        if (typeof window.setPosCustomer === 'function') {
            window.setPosCustomer('', 'Cliente Final');
        }
        renderCart();
    }

    function cancelPosCheckout() {
        if (!items.length) {
            resetPosSaleBox();
            return;
        }
        var msg = '¿Cancelar la venta y vaciar el carrito?';
        if (typeof showConfirmModal === 'function') {
            showConfirmModal(msg, resetPosSaleBox);
            return;
        }
        if (confirm(msg)) resetPosSaleBox();
    }

    var elCancelPay = document.getElementById('posCancelPay');
    if (elCancelPay) {
        elCancelPay.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            cancelPosCheckout();
        });
    }

    if (elConfirmPay) elConfirmPay.addEventListener('click', function () {
        if (paying || !items.length) return;

        for (var ci = 0; ci < items.length; ci++) {
            var cit = items[ci];
            if (cit.is_open_item) continue;
            var cCost = productPurchaseCost(cit);
            if (!saleExceedsCost(cit.unit_price, cCost) || !saleExceedsCost(cit.retail_price != null ? cit.retail_price : cit.unit_price, cCost)) {
                if (typeof showAlert === 'function') {
                    showAlert(costPriceBlockMessage(cit.product_name, cit.unit_price, cCost), 'error');
                }
                return;
            }
        }

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

        function buildPayFormData(token) {
        var fd = new FormData();
        fd.append('csrf_token', token || currentCsrf());
        fd.append('client_sale_uuid', clientUuid);
        var customerId = document.getElementById('posCustomer').value;
        if (customerId) fd.append('customer_id', customerId);
        fd.append('payment_method', mixed ? (splits[0] && splits[0].method) || 'cash' : payMethod);
        fd.append('payment_type', payType);
        fd.append('document_type', 'invoice');
        fd.append('payment_terms', payType === 'credit'
            ? ((elPayTerms && elPayTerms.value) || 'net_30')
            : 'cash');
        fd.append('sale_date', (window.SeriDate ? SeriDate.localYmd() : (function () {
            var d = new Date();
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        })()));
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
            if (it.price_manual) {
                fd.append('items[' + i + '][price_manual]', '1');
            }
            if (it.is_open_item) {
                fd.append('items[' + i + '][is_open_item]', '1');
            }
            if (it.stock_oversell_reason) {
                fd.append('items[' + i + '][stock_oversell_reason]', it.stock_oversell_reason);
                fd.append('items[' + i + '][stock_oversell_note]', it.stock_oversell_note || '');
            }
            if (it.is_consignment) {
                fd.append('items[' + i + '][is_consignment]', '1');
                if (it.consignor_id) {
                    fd.append('items[' + i + '][consignor_id]', String(it.consignor_id));
                }
            }
            fd.append('items[' + i + '][tax_rate]', String(itemTaxRate(it)));
        });
        return fd;
        }

        function buildOfflinePayload(fd) {
            if (!Offline) return {};
            return Offline.normalizePayload(Offline.formDataToObject(fd));
        }

        function queueAndClear(fd) {
            if (Offline) {
                Offline.enqueueSale(buildOfflinePayload(fd), clientUuid);
            }
            paying = false;
            btn.disabled = false;
            if (elInvoice) elInvoice.textContent = 'PEND-' + clientUuid.slice(0, 8).toUpperCase();
            clearCartAfterSale(
                (Offline && Offline.isServerUnreachable && Offline.isServerUnreachable())
                    ? 'Servidor no responde: venta guardada en cola local. Pulse «Sincronizar ahora» cuando el ERP vuelva.'
                    : 'Sin conexión: venta guardada en cola local. Se sincronizará al volver online.',
                { alertType: 'warning', reload: false }
            );
            updateOfflineBanner();
            updatePwaStatus();
        }

        function finishPayError(message) {
            paying = false;
            btn.disabled = false;
            if (typeof showAlert === 'function') showAlert(message || 'No se pudo registrar la venta', 'error');
        }

        var useLocalQueue = Offline && typeof Offline.shouldUseLocalQueue === 'function'
            ? Offline.shouldUseLocalQueue()
            : !online;

        if (useLocalQueue) {
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
                queueAndClear(buildPayFormData(currentCsrf()));
            }).catch(function () {
                finishPayError('No se pudo validar el stock local. Revise conexión o reintente.');
            });
            return;
        }

        function postSale(token, retried) {
            var fd = buildPayFormData(token);
            return fetch(saleUrl, {
                method: 'POST',
                body: fd,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token || ''
                },
                credentials: 'same-origin'
            })
            .then(function (r) {
                return r.text().then(function (text) {
                    var d = null;
                    try { d = text ? JSON.parse(text) : null; } catch (e) { d = null; }
                    return { response: r, data: d, parseOk: !!d, fd: fd };
                });
            })
            .then(function (res) {
                if (res.data && res.data.csrf_token && window.SeriCsrf) {
                    SeriCsrf.apply(res.data.csrf_token);
                }
                if (res.parseOk && res.data && res.data.success) {
                    paying = false;
                    btn.disabled = false;
                    var msg = res.data.message || 'Venta registrada';
                    var inv = res.data.invoice_number || '';
                    var m = inv || (msg.match(/(FAC-|REM-|COT-)[A-Z0-9\-]+/i) || [])[0];
                    if (m && elInvoice) elInvoice.textContent = m;
                    var printUrl = res.data.ticket_print_url || '';
                    if (!printUrl && res.data.sale_id) {
                        var printBase = panel.getAttribute('data-ticket-print-base') || '';
                        if (printBase) {
                            printUrl = printBase + '?action=pdf&format=print&id=' + res.data.sale_id;
                        }
                    }
                    if (panel.getAttribute('data-auto-print-ticket') === '1' && printUrl) {
                        window.open(printUrl, 'ticket_print', 'noopener,width=420,height=720');
                        clearCartAfterSale(msg, { reload: true, reloadDelay: 1800 });
                        return;
                    }
                    clearCartAfterSale(msg, { reload: true });
                    return;
                }
                if (res.data && res.data.code === 'csrf' && !retried) {
                    var next = (res.data.csrf_token) || currentCsrf();
                    if (window.SeriCsrf && res.data.csrf_token) SeriCsrf.apply(res.data.csrf_token);
                    return withFreshCsrf().then(function (t) {
                        return postSale(t || next, true);
                    });
                }
                var networkFail = Offline && (
                    Offline.isNetworkFailure(null, res.response) ||
                    (!res.parseOk && res.response && (res.response.status === 0 || res.response.status >= 500))
                );
                if (networkFail) {
                    if (Offline.markServerReachable) Offline.markServerReachable(false);
                    queueAndClear(res.fd);
                    return;
                }
                // 2xx sin JSON usable: la venta pudo crearse. Encolar con el MISMO UUID (idempotente)
                // para no generar un segundo cobro al reintentar.
                if (res.response && res.response.ok && !res.parseOk) {
                    if (Offline) {
                        if (Offline.markServerReachable) Offline.markServerReachable(true);
                        queueAndClear(res.fd);
                        return;
                    }
                    finishPayError('Respuesta inválida del servidor. No vuelva a cobrar: verifique en Ventas si ya quedó registrada.');
                    return;
                }
                if (Offline && Offline.markServerReachable && res.response && res.response.ok) {
                    Offline.markServerReachable(true);
                }
                finishPayError((res.data && res.data.message) || 'No se pudo registrar la venta');
            });
        }

        withFreshCsrf().then(function (token) {
            return postSale(token, false);
        }).catch(function (err) {
                if (Offline && Offline.isNetworkFailure(err)) {
                    if (Offline.markServerReachable) Offline.markServerReachable(false);
                    queueAndClear(buildPayFormData(currentCsrf()));
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
                tax_rate: parseFloat(target.getAttribute('data-tax-rate') || '0'),
                is_open_item: target.getAttribute('data-open-item') === '1' ? 1 : 0,
                purchase_price: parseFloat(target.getAttribute('data-cost') || '0')
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

/**
 * Sincronización en tiempo real de movimientos y totales de caja (sin recargar página).
 */
(function initCajaLiveSync() {
    var cfg = window.CajaLiveSyncConfig;
    if (!cfg || !cfg.url) return;

    var panel = document.getElementById('cajaMovementsPanel');
    if (!panel) return;

    var intervalMs = Math.max(8000, parseInt(cfg.intervalMs, 10) || 12000);
    var lastIds = '';
    var syncing = false;
    var badge = document.getElementById('cajaLiveSyncBadge');
    var countEl = document.getElementById('cajaMovementsCount');
    var bodyEl = document.getElementById('cajaMovementsBody');
    var emptyEl = document.getElementById('cajaMovementsEmpty');
    var tableWrap = document.getElementById('cajaMovementsTableWrap');
    var btnClose = document.getElementById('btnOpenCloseCash');

    function shouldPause() {
        var openModal = document.querySelector('.modal-overlay[style*="flex"], #expenseModal[style*="flex"], #cajaLoanModal[style*="flex"], #closeCashModal[style*="flex"]');
        if (openModal) return true;
        var ae = document.activeElement;
        if (ae && ['INPUT', 'TEXTAREA', 'SELECT'].indexOf(ae.tagName) >= 0) return true;
        if (document.body.classList.contains('pos-busy')) return true;
        if (document.hidden) return true;
        return false;
    }

    function setBadge(text, ok) {
        if (!badge) return;
        badge.textContent = text;
        badge.style.color = ok === false ? '#DC2626' : 'var(--color-text-secondary)';
    }

    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] || c;
        });
    }

    function renderOrigin(m) {
        if (m.origin_url) {
            return '<a href="' + escapeHtml(m.origin_url) + '">' + escapeHtml(m.origin_label || 'Ver') + '</a>';
        }
        if (m.origin_label) return escapeHtml(m.origin_label);
        return '—';
    }

    function renderMovements(list) {
        if (!bodyEl) return;
        var html = '';
        (list || []).forEach(function (m) {
            var isIn = m.type === 'income';
            var color = isIn ? '#10B981' : '#DC2626';
            html += '<tr data-movement-id="' + escapeHtml(String(m.id)) + '">' +
                '<td>' + escapeHtml(m.time) + '</td>' +
                '<td>' + escapeHtml(m.user_name || '—') + '</td>' +
                '<td><span class="badge ' + (isIn ? 'badge-success' : 'badge-danger') + '">' + escapeHtml(m.type_label) + '</span></td>' +
                '<td>' + escapeHtml(m.description) + '</td>' +
                '<td>' + renderOrigin(m) + '</td>' +
                '<td style="color:' + color + ';font-weight:600;">' + escapeHtml(m.amount_fmt) + '</td>' +
                '</tr>';
        });
        bodyEl.innerHTML = html;
        if (emptyEl && tableWrap) {
            var hasRows = (list || []).length > 0;
            emptyEl.style.display = hasRows ? 'none' : 'block';
            tableWrap.style.display = hasRows ? '' : 'none';
        }
        if (countEl) countEl.textContent = String((list || []).length);
    }

    function updateTotals(t) {
        if (!t) return;
        var elCashSales = document.getElementById('cajaStatCashSales');
        var elOther = document.getElementById('cajaStatOtherIncomes');
        var elEx = document.getElementById('cajaStatExpenses');
        var elBal = document.getElementById('cajaStatBalance');
        if (cfg.showTopStats) {
            if (elCashSales) elCashSales.textContent = t.cash_sales_fmt || t.incomes_fmt || '';
            if (elOther) elOther.textContent = t.other_incomes_fmt || '';
            if (elEx) elEx.textContent = t.expenses_fmt || '';
            if (elBal) {
                elBal.textContent = t.expected_cash_fmt || t.balance_fmt || '';
                elBal.classList.remove('page-top-stat--in', 'page-top-stat--out');
                elBal.classList.add((t.expected_cash || t.balance || 0) >= 0 ? 'page-top-stat--in' : 'page-top-stat--out');
            }
        }
        if (btnClose) {
            btnClose.setAttribute('data-balance', String(Math.round((t.expected_cash || t.balance || 0) * 100) / 100));
            btnClose.setAttribute('data-cash-sales', String(t.cash_sales_fmt || '').replace(/^\+/, '') || '');
            btnClose.setAttribute('data-other-cash', String(t.other_cash_incomes_fmt || '').replace(/^\+/, '') || '');
            btnClose.setAttribute('data-expenses', String(t.expenses_fmt || '').replace(/^-/, '') || '');
            btnClose.setAttribute('data-balance-label', t.expected_cash_fmt || t.balance_fmt || '');
            if (t.sales_count != null) btnClose.setAttribute('data-sales-count', String(t.sales_count));
            if (t.sales_total != null && t.sales_total_fmt) {
                btnClose.setAttribute('data-sales-total', t.sales_total_fmt);
            }
        }
    }

    function sync() {
        if (syncing || shouldPause()) return;
        syncing = true;
        fetch(cfg.url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) throw new Error((data && data.message) || 'Sync falló');
                if (data.csrf_token && window.SeriCsrf) {
                    SeriCsrf.apply(data.csrf_token);
                }
                if (!data.open) {
                    setBadge('Caja cerrada', false);
                    return;
                }
                var ids = (data.movements || []).map(function (m) { return String(m.id); }).join(',');
                var changed = ids !== lastIds;
                lastIds = ids;
                renderMovements(data.movements || []);
                updateTotals(data.totals || {});
                var voidNotice = document.getElementById('cajaVoidedNotice');
                var hiddenN = parseInt(data.hidden_voided_count, 10) || 0;
                if (voidNotice) {
                    if (hiddenN > 0) {
                        voidNotice.style.display = '';
                        voidNotice.textContent = 'Se omitieron ' + hiddenN + ' movimiento(s) de compras/gastos ya anulados (neto $0 en el cajón).';
                    } else {
                        voidNotice.style.display = 'none';
                        voidNotice.textContent = '';
                    }
                }
                var now = new Date();
                setBadge('Actualizado ' + now.toLocaleTimeString('es-CO', { hour: 'numeric', minute: '2-digit', second: '2-digit' }), true);
                if (changed && panel) {
                    panel.classList.add('caja-live-flash');
                    setTimeout(function () { panel.classList.remove('caja-live-flash'); }, 600);
                }
            })
            .catch(function () {
                setBadge('Sin conexión — reintentando…', false);
            })
            .finally(function () {
                syncing = false;
            });
    }

    if (bodyEl) {
        lastIds = Array.prototype.map.call(bodyEl.querySelectorAll('tr[data-movement-id]'), function (tr) {
            return tr.getAttribute('data-movement-id') || '';
        }).join(',');
    }

    sync();
    setInterval(sync, intervalMs);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) sync();
    });
})();
