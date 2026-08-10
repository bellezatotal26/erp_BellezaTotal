/**
 * Formato de montos al digitar (miles con punto / decimal con coma para COP).
 * - Visual: 1.234.567,50
 * - Al enviar formularios: normaliza a 1234567.50 (sin romper el backend)
 */
(function (global) {
    'use strict';

    function cfg() {
        var metaDec = document.querySelector('meta[name="currency-decimals"]');
        var metaTh = document.querySelector('meta[name="currency-thousands"]');
        var metaSep = document.querySelector('meta[name="currency-decimal"]');
        var decimals = parseInt(metaDec && metaDec.content ? metaDec.content : '2', 10);
        if (isNaN(decimals) || decimals < 0) decimals = 2;
        return {
            decimals: decimals,
            thousands: (metaTh && metaTh.content) ? metaTh.content : '.',
            decimal: (metaSep && metaSep.content) ? metaSep.content : ','
        };
    }

    function parse(raw) {
        var s = String(raw == null ? '' : raw).trim().replace(/\s/g, '').replace(/\u00a0/g, '');
        if (!s) return 0;
        s = s.replace(/[^\d,.\-]/g, '');
        if (!s || s === '-' || s === '.' || s === ',') return 0;
        var neg = s.charAt(0) === '-';
        s = s.replace(/^[-+]/, '');
        var c = cfg();

        if (s.indexOf(',') >= 0 && s.indexOf('.') >= 0) {
            if (s.lastIndexOf(',') > s.lastIndexOf('.')) {
                s = s.replace(/\./g, '').replace(',', '.');
            } else {
                s = s.replace(/,/g, '');
            }
        } else if (s.indexOf(',') >= 0) {
            var bits = s.split(',');
            s = (bits.length === 2 && bits[1].length <= 2)
                ? bits[0].replace(/\./g, '') + '.' + bits[1]
                : s.replace(/,/g, '');
        } else {
            var dots = s.split('.');
            if (dots.length > 2) {
                s = s.replace(/\./g, '');
            } else if (dots.length === 2) {
                var decLen = dots[1].length;
                if (decLen === 3 && c.decimals !== 3) s = s.replace(/\./g, '');
                else if (decLen > 2) s = s.replace(/\./g, '');
            }
        }
        var n = parseFloat(s);
        if (isNaN(n)) return 0;
        return neg ? -n : n;
    }

    function format(n, opts) {
        var c = Object.assign({}, cfg(), opts || {});
        var num = Number(n);
        if (!isFinite(num)) num = 0;
        var fixed = Math.abs(num).toFixed(c.decimals);
        var parts = fixed.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, c.thousands);
        var out = c.decimals > 0 ? parts[0] + c.decimal + parts[1] : parts[0];
        return (num < 0 ? '-' : '') + out;
    }

    function formatTyping(raw) {
        var c = cfg();
        var s = String(raw == null ? '' : raw);
        var neg = /^\s*-/.test(s);
        // Solo dígitos y separador decimal local mientras escribe
        s = s.replace(/[^\d.,]/g, '');
        // Unificar: si hay coma y punto, respetar el último como decimal
        var lastComma = s.lastIndexOf(',');
        var lastDot = s.lastIndexOf('.');
        var decChar = c.decimal;
        var intPart = s;
        var decPart = '';

        if (c.decimals > 0) {
            if (lastComma >= 0 || lastDot >= 0) {
                var cut = Math.max(lastComma, lastDot);
                intPart = s.slice(0, cut).replace(/[.,]/g, '');
                decPart = s.slice(cut + 1).replace(/[.,]/g, '').slice(0, c.decimals);
            } else {
                intPart = s.replace(/[.,]/g, '');
            }
        } else {
            intPart = s.replace(/[.,]/g, '');
        }

        intPart = intPart.replace(/^0+(?=\d)/, '');
        if (intPart === '') intPart = '0';
        intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, c.thousands);
        var out = intPart;
        if (c.decimals > 0 && (lastComma >= 0 || lastDot >= 0 || (raw && String(raw).indexOf(decChar) >= 0))) {
            out += decChar + decPart;
        }
        return (neg ? '-' : '') + out;
    }

    var MONEY_NAME_RE = /^(purchase_price|sale_price|wholesale_price|offer_price|opening_amount|closing_amount|expected_amount|amount|paid_amount|unit_price|price|unit_cost|delivery_fee|delivery_client|delivery_company|discount_amount|discount|last_cost|credit_amount|cash_amount|transfer_amount|card_amount)$/i;

    function isMoneyInput(el) {
        if (!el || el.tagName !== 'INPUT' || el.disabled || el.readOnly) return false;
        if (el.type === 'hidden' || el.type === 'checkbox' || el.type === 'radio' || el.type === 'file') return false;
        if (el.getAttribute('data-money') === '0') return false;
        if (el.getAttribute('data-money') === '1' || el.classList.contains('money-input')) return true;
        var name = el.getAttribute('name') || el.id || '';
        if (MONEY_NAME_RE.test(name)) return true;
        // Evitar cantidades / stock / %
        if (/qty|quantity|stock|percent|min_|max_|days|id$/i.test(name)) return false;
        return false;
    }

    function enhance(el) {
        if (!el || el.dataset.moneyReady === '1') return;
        if (!isMoneyInput(el)) return;
        el.dataset.moneyReady = '1';
        el.setAttribute('data-money', '1');
        if (el.type === 'number') {
            el.type = 'text';
            el.setAttribute('inputmode', 'decimal');
            el.removeAttribute('step');
            el.removeAttribute('min');
            el.removeAttribute('max');
        } else if (!el.getAttribute('inputmode')) {
            el.setAttribute('inputmode', 'decimal');
        }
        el.classList.add('money-input');
        el.setAttribute('autocomplete', 'off');

        // Formatear valor inicial si es numérico puro
        var initial = el.value;
        if (initial !== '' && /^-?\d+(\.\d+)?$/.test(String(initial).trim())) {
            el.value = format(parseFloat(initial));
        } else if (initial !== '') {
            el.value = formatTyping(initial);
        }

        el.addEventListener('focus', function () {
            // Seleccionar todo facilita reescribir
            try { el.select(); } catch (e) {}
        });

        el.addEventListener('input', function () {
            var caretAtEnd = el.selectionStart === el.value.length;
            var next = formatTyping(el.value);
            if (next !== el.value) {
                el.value = next;
                if (caretAtEnd) {
                    try { el.setSelectionRange(el.value.length, el.value.length); } catch (e) {}
                }
            }
        });

        el.addEventListener('blur', function () {
            if (el.value.trim() === '') return;
            el.value = format(parse(el.value));
        });
    }

    function enhanceAll(root) {
        var scope = root || document;
        var nodes = scope.querySelectorAll('input');
        nodes.forEach(enhance);
    }

    function normalizeForm(form) {
        if (!form) return;
        form.querySelectorAll('input[data-money="1"], input.money-input').forEach(function (el) {
            if (el.value === '') return;
            var n = parse(el.value);
            // Envío en formato neutro con punto decimal
            var c = cfg();
            el.value = c.decimals > 0 ? n.toFixed(c.decimals) : String(Math.round(n));
        });
    }

    function restoreForm(form) {
        if (!form) return;
        form.querySelectorAll('input[data-money="1"], input.money-input').forEach(function (el) {
            if (el.value === '') return;
            el.value = format(parse(el.value));
        });
    }

    // Antes de enviar cualquier formulario
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        normalizeForm(form);
        // Si el submit AJAX falla, re-formatear tras un tick
        setTimeout(function () { restoreForm(form); }, 0);
    }, true);

    document.addEventListener('DOMContentLoaded', function () {
        enhanceAll(document);
        // Modales / HTML dinámico
        var obs = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) return;
                    if (node.matches && node.matches('input')) enhance(node);
                    if (node.querySelectorAll) enhanceAll(node);
                });
            });
        });
        obs.observe(document.body, { childList: true, subtree: true });
    });

    global.SeriMoney = {
        parse: parse,
        format: format,
        enhance: enhance,
        enhanceAll: enhanceAll,
        normalizeForm: normalizeForm,
        restoreForm: restoreForm
    };
})(window);
