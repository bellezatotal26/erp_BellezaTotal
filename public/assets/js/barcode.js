// Seri ERP - Lector de códigos de barras HID (ráfaga + Enter)
'use strict';

(function (global) {
    var MAX_GAP_MS = 55;
    var MIN_LEN = 3;
    var handlers = [];
    var buffer = '';
    var lastKeyAt = 0;
    var contaminated = null;
    var processing = false;

    function lookupUrl() {
        var meta = document.querySelector('meta[name="barcode-lookup-url"]');
        if (meta && meta.content) return meta.content;
        var input = document.getElementById('globalSearchInput');
        if (input && input.dataset.searchUrl) {
            return input.dataset.searchUrl.replace(/\?.*$/, '') + '?action=lookup_barcode';
        }
        return '';
    }

    function isExcludedTarget(el) {
        if (!el || !el.tagName) return false;
        var tag = el.tagName.toLowerCase();
        if (tag === 'textarea') return true;
        if (tag === 'select') return true;
        if (tag === 'input') {
            var type = (el.getAttribute('type') || 'text').toLowerCase();
            if (['password', 'number', 'date', 'datetime-local', 'time', 'email', 'tel', 'file', 'checkbox', 'radio', 'range', 'color', 'hidden'].indexOf(type) >= 0) {
                return true;
            }
            if (el.getAttribute('data-barcode-input') === 'true') return true;
        }
        if (el.isContentEditable) return true;
        return false;
    }

    function modalVisible(id) {
        var m = document.getElementById(id);
        if (!m) return false;
        var style = window.getComputedStyle ? window.getComputedStyle(m) : m.style;
        return style.display !== 'none' && style.visibility !== 'hidden';
    }

    function flashCode(code) {
        if (typeof showAlert === 'function') {
            showAlert('Código: ' + code, 'info');
        }
    }

    function cleanContamination(code) {
        if (!contaminated) return;
        var el = contaminated;
        contaminated = null;
        try {
            if (el.value != null && code && String(el.value).indexOf(code) >= 0) {
                el.value = String(el.value).split(code).join('');
            }
        } catch (e) { /* ignore */ }
    }

    function lookup(code) {
        code = String(code || '').trim();
        if (!code) {
            return Promise.resolve({ success: false, product: null, message: 'Código vacío' });
        }
        var base = lookupUrl();
        if (!base) {
            return Promise.resolve({ success: false, product: null, message: 'URL de lookup no configurada' });
        }
        var sep = base.indexOf('?') >= 0 ? '&' : '?';
        var url = base + sep + 'code=' + encodeURIComponent(code);
        return fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .catch(function () {
                return { success: false, product: null, message: 'Error de conexión' };
            });
    }

    function findOptionByCode(sel, code, productId) {
        if (!sel) return null;
        var opt = null;
        var codeLower = String(code || '').toLowerCase();
        Array.prototype.some.call(sel.options, function (o) {
            if (productId && String(o.value) === String(productId)) {
                opt = o;
                return true;
            }
            if (String(o.getAttribute('data-code') || '').toLowerCase() === codeLower) {
                opt = o;
                return true;
            }
            return false;
        });
        return opt;
    }

    function defaultHandler(code, product) {
        var input = document.getElementById('globalSearchInput');
        if (input) {
            input.value = code;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            try { input.focus(); } catch (e) { /* ignore */ }
        }
        if (!product) {
            if (typeof showAlert === 'function') {
                showAlert('No se encontró producto con código: ' + code, 'warning');
            }
        } else if (typeof showAlert === 'function') {
            showAlert(product.name + ' (' + (product.code || code) + ')', 'success');
        }
        return true;
    }

    function runHandlers(code, product) {
        for (var i = 0; i < handlers.length; i++) {
            try {
                if (handlers[i](code, product) === true) return true;
            } catch (e) {
                console.error('SoftNovaBarcode handler', e);
            }
        }
        return defaultHandler(code, product);
    }

    function onScanComplete(code) {
        if (processing) return;
        processing = true;
        flashCode(code);
        cleanContamination(code);
        buffer = '';
        lastKeyAt = 0;

        lookup(code).then(function (d) {
            var product = (d && d.success && d.product) ? d.product : null;
            runHandlers(code, product);
            processing = false;
        }).catch(function () {
            runHandlers(code, null);
            processing = false;
        });
    }

    function onKeyDown(e) {
        if (e.ctrlKey || e.altKey || e.metaKey) return;

        var target = e.target;
        if (isExcludedTarget(target)) {
            buffer = '';
            lastKeyAt = 0;
            contaminated = null;
            return;
        }

        var now = Date.now();
        var key = e.key;

        if (key === 'Enter') {
            if (buffer.length >= MIN_LEN && lastKeyAt && (now - lastKeyAt) <= MAX_GAP_MS * 3) {
                e.preventDefault();
                e.stopPropagation();
                onScanComplete(buffer);
            }
            buffer = '';
            lastKeyAt = 0;
            return;
        }

        if (key.length !== 1) return;

        if (lastKeyAt && (now - lastKeyAt) > MAX_GAP_MS) {
            buffer = '';
            contaminated = null;
        }

        buffer += key;
        lastKeyAt = now;
        if (target && target.tagName && target.tagName.toLowerCase() === 'input' && target.getAttribute('data-barcode-input') !== 'true') {
            contaminated = target;
        }
    }

    function onScan(handler, prepend) {
        if (typeof handler !== 'function') return;
        if (prepend) handlers.unshift(handler);
        else handlers.push(handler);
    }

    function registerBuiltinHandlers() {
        // Modal Ventas
        onScan(function (code, product) {
            if (!modalVisible('saleModal') || !product) return false;
            if (typeof window.SoftNovaPos !== 'undefined' && window.SoftNovaPos.hasPanel && modalVisible('posPanel')) {
                // POS tiene prioridad si está activo; saleModal no aplica en caja
            }
            var sel = document.getElementById('productSelect');
            if (!sel || !document.getElementById('saleModal')) return false;
            // Solo si estamos en ventas (no cotizaciones con mismo id)
            if (!document.getElementById('saleModal').contains(sel) && document.getElementById('itemsTable')) {
                /* ok */
            }
            var pageHasSale = !!document.querySelector('#saleModal form[action*="ventas"]');
            if (!pageHasSale) return false;

            var opt = findOptionByCode(sel, code, product.id);
            if (!opt) {
                opt = document.createElement('option');
                opt.value = product.id;
                opt.setAttribute('data-price', product.sale_price);
                opt.setAttribute('data-name', product.name);
                opt.setAttribute('data-stock', product.stock);
                opt.setAttribute('data-code', product.code || code);
                opt.textContent = product.name;
                sel.appendChild(opt);
            }
            sel.value = opt.value;
            var qty = document.getElementById('productQty');
            if (qty) qty.value = '1';
            if (typeof addProduct === 'function') {
                addProduct({ target: document.getElementById('addProductBtn') || sel });
                return true;
            }
            return false;
        });

        // Modal Cotizaciones
        onScan(function (code, product) {
            if (!modalVisible('quoteModal') || !product) return false;
            var sel = document.querySelector('#quoteModal #productSelect') || document.getElementById('productSelect');
            if (!sel) return false;
            var opt = findOptionByCode(sel, code, product.id);
            if (!opt) {
                opt = document.createElement('option');
                opt.value = product.id;
                opt.setAttribute('data-price', product.sale_price);
                opt.setAttribute('data-name', product.name);
                opt.setAttribute('data-code', product.code || code);
                opt.textContent = product.name;
                sel.appendChild(opt);
            }
            sel.value = opt.value;
            var qty = document.getElementById('productQty');
            if (qty) qty.value = '1';
            if (typeof addQuoteProduct === 'function') {
                addQuoteProduct();
                return true;
            }
            return false;
        });

        // Modal Compras/OC
        onScan(function (code, product) {
            if (!modalVisible('purchaseModal') || !product) return false;
            var rows = document.querySelectorAll('#poItems .po-item-row');
            var found = null;
            Array.prototype.some.call(rows, function (row) {
                var sel = row.querySelector('select[name="product_id[]"]');
                if (sel && String(sel.value) === String(product.id)) {
                    found = row;
                    return true;
                }
                return false;
            });
            if (found) {
                var qtyInput = found.querySelector('input[name="quantity[]"]');
                if (qtyInput) {
                    qtyInput.value = String((parseInt(qtyInput.value, 10) || 0) + 1);
                }
                if (typeof showAlert === 'function') showAlert(product.name + ': cantidad +1', 'success');
                return true;
            }
            if (typeof addPoItem === 'function') {
                addPoItem({
                    product_id: product.id,
                    quantity: 1,
                    unit_cost: product.purchase_price || 0
                });
                if (typeof showAlert === 'function') showAlert(product.name + ' agregado a la OC', 'success');
                return true;
            }
            return false;
        });

        // Inventario
        onScan(function (code) {
            var onInv = /inventario/i.test(location.pathname || '') || /inventario/i.test(location.href || '');
            if (!onInv) return false;

            if (modalVisible('productModal')) {
                var codeInput = document.getElementById('prodCode');
                if (codeInput) {
                    codeInput.value = code;
                    if (typeof showAlert === 'function') showAlert('Código/SKU: ' + code, 'success');
                    return true;
                }
            }

            var qInput = document.querySelector('form input[name="q"]');
            if (qInput) {
                qInput.value = code;
                var form = qInput.closest('form');
                if (form) {
                    form.submit();
                    return true;
                }
            }
            return false;
        });
    }

    document.addEventListener('keydown', onKeyDown, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', registerBuiltinHandlers);
    } else {
        registerBuiltinHandlers();
    }

    global.SoftNovaBarcode = {
        onScan: onScan,
        lookup: lookup,
        isModalVisible: modalVisible
    };
})(window);
