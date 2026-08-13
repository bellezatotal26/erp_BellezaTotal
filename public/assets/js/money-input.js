/**
 * Formato de montos al digitar (COP: miles con punto, decimal con coma).
 * Visual: 20.000 ó 1.234.567,50
 * Envío de formularios: 20000 / 1234567.50 (neutro para el backend)
 *
 * Importante: el separador de miles NO debe tratarse como decimal al tipear
 * (bug previo: "20.000" se convertía en "20,00").
 */
(function (global) {
    'use strict';

    function cfg() {
        var metaDec = document.querySelector('meta[name="currency-decimals"]');
        var metaTh = document.querySelector('meta[name="currency-thousands"]');
        var metaSep = document.querySelector('meta[name="currency-decimal"]');
        var decimals = parseInt(metaDec && metaDec.content ? metaDec.content : '2', 10);
        if (isNaN(decimals) || decimals < 0) decimals = 2;
        var thousands = (metaTh && metaTh.content) ? metaTh.content : '.';
        var decimal = (metaSep && metaSep.content) ? metaSep.content : ',';
        // Evitar configuración inválida (mismo carácter para miles y decimal)
        if (thousands === decimal) {
            thousands = decimal === ',' ? '.' : ',';
        }
        return { decimals: decimals, thousands: thousands, decimal: decimal };
    }

    function escapeRegExp(s) {
        return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    /**
     * Interpreta montos locales o neutros.
     * 20.000 → 20000 | 20.000,50 → 20000.5 | 20000.50 → 20000.5
     */
    function parse(raw) {
        var s = String(raw == null ? '' : raw).trim().replace(/\s/g, '').replace(/\u00a0/g, '');
        if (!s) return 0;
        s = s.replace(/[^\d,.\-]/g, '');
        if (!s || s === '-' || s === '.' || s === ',') return 0;
        var neg = s.charAt(0) === '-';
        s = s.replace(/^[-+]/, '');
        var c = cfg();
        var th = c.thousands;
        var dec = c.decimal;

        if (th && dec && s.indexOf(th) >= 0 && s.indexOf(dec) >= 0) {
            s = s.split(th).join('').replace(dec, '.');
        } else if (dec && s.indexOf(dec) >= 0) {
            var bits = s.split(dec);
            if (bits.length === 2) {
                s = bits[0].replace(new RegExp(escapeRegExp(th), 'g'), '').replace(/\./g, '') + '.' + bits[1].replace(/\D/g, '');
            } else {
                s = s.split(dec).join('');
            }
        } else if (th && s.indexOf(th) >= 0) {
            var parts = s.split(th);
            if (parts.length > 2) {
                // Varios separadores → miles: 1.234.567
                s = parts.join('');
            } else if (parts.length === 2) {
                var right = parts[1];
                if (right.length === 3) {
                    // 20.000 → miles
                    s = parts[0] + right;
                } else if (right.length > 0 && right.length <= 2) {
                    // Alinear con parse_money PHP: un solo separador con 1–2 dígitos = decimal
                    // (aunque currency.decimals sea 0).
                    s = parts[0].replace(/\D/g, '') + '.' + right.replace(/\D/g, '');
                } else {
                    s = parts.join('');
                }
            }
        } else if (s.indexOf('.') >= 0 && s.indexOf(',') < 0) {
            // Neutro o US: 20000.50
            var dots = s.split('.');
            if (dots.length > 2) {
                s = dots.join('');
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

    /**
     * Formato en vivo: solo el separador decimal configurado abre decimales.
     * Los puntos/comas de miles se ignoran al rearmar el entero.
     */
    function formatTyping(raw) {
        var c = cfg();
        var s = String(raw == null ? '' : raw);
        var neg = /^\s*-/.test(s);
        s = s.replace(/[^\d.,]/g, '');

        var dec = c.decimal;
        var th = c.thousands;
        var intPart = s;
        var decPart = '';
        var hasDec = false;

        if (c.decimals > 0 && dec) {
            var cut = s.lastIndexOf(dec);
            if (cut >= 0) {
                hasDec = true;
                intPart = s.slice(0, cut);
                decPart = s.slice(cut + 1);
            }
        }

        // Quitar cualquier separador del entero (miles o basura)
        intPart = intPart.replace(/[.,]/g, '').replace(/\D/g, '');
        decPart = decPart.replace(/[.,]/g, '').replace(/\D/g, '').slice(0, c.decimals);

        intPart = intPart.replace(/^0+(?=\d)/, '');
        if (intPart === '') intPart = hasDec ? '0' : '';
        if (intPart === '' && !hasDec) {
            return neg ? '-' : '';
        }
        if (intPart === '') intPart = '0';

        intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, th);
        var out = intPart;
        if (hasDec && c.decimals > 0) {
            out += dec + decPart;
        }
        return (neg ? '-' : '') + out;
    }

    var MONEY_NAME_RE = /^(purchase_price|sale_price|wholesale_price|offer_price|opening_amount|closing_amount|expected_amount|amount|paid_amount|unit_price|price|unit_cost|delivery_fee|delivery_client|delivery_company|delivery_total|discount_amount|discount|last_cost|credit_amount|cash_amount|transfer_amount|card_amount|cash_received|initial_payment)(\[\])?$/i;
    var MONEY_ID_RE = /^(posCashReceived|posDeliveryTotal|posDeliveryClient|posDeliveryCompany|posInitialPayment|openItemPrice|payAmount|closingAmountInput|offerPrice|prodPcompra|prodPventa|prodPmayoreo)$/i;

    function isMoneyInput(el) {
        if (!el || el.tagName !== 'INPUT' || el.disabled || el.readOnly) return false;
        if (el.type === 'hidden' || el.type === 'checkbox' || el.type === 'radio' || el.type === 'file') return false;
        if (el.getAttribute('data-money') === '0') return false;
        if (el.getAttribute('data-money') === '1' || el.classList.contains('money-input') || el.classList.contains('pos-mixed-amount')) return true;
        var name = el.getAttribute('name') || '';
        var id = el.id || '';
        if (MONEY_NAME_RE.test(name) || MONEY_ID_RE.test(id)) return true;
        if (/qty|quantity|stock|percent|min_|max_|days|_id$|^id$/i.test(name) || /qty|quantity|percent|stock/i.test(id)) return false;
        return false;
    }

    function enhance(el) {
        if (!el || el.dataset.moneyReady === '1') return;
        if (!isMoneyInput(el)) return;
        el.dataset.moneyReady = '1';
        el.setAttribute('data-money', '1');
        if (el.type === 'number') {
            el.type = 'text';
            el.removeAttribute('step');
            el.removeAttribute('min');
            el.removeAttribute('max');
        }
        el.setAttribute('inputmode', 'decimal');
        el.classList.add('money-input');
        el.setAttribute('autocomplete', 'off');

        var initial = el.value;
        if (initial !== '' && /^-?\d+(\.\d+)?$/.test(String(initial).trim())) {
            el.value = format(parseFloat(initial));
        } else if (initial !== '') {
            el.value = formatTyping(initial);
        }

        el.addEventListener('focus', function () {
            try { el.select(); } catch (e) {}
        });

        el.addEventListener('input', function () {
            var start = el.selectionStart;
            var before = el.value;
            var next = formatTyping(before);
            if (next === before) return;
            // Preservar caret relativo al final cuando se insertan puntos de miles
            var fromEnd = before.length - (start == null ? before.length : start);
            el.value = next;
            try {
                var pos = Math.max(0, next.length - fromEnd);
                el.setSelectionRange(pos, pos);
            } catch (e) {}
        });

        el.addEventListener('blur', function () {
            if (el.value.trim() === '') return;
            el.value = format(parse(el.value));
        });
    }

    function enhanceAll(root) {
        var scope = root || document;
        scope.querySelectorAll('input').forEach(enhance);
    }

    function normalizeForm(form) {
        if (!form) return;
        form.querySelectorAll('input[data-money="1"], input.money-input').forEach(function (el) {
            if (el.value === '') return;
            var n = parse(el.value);
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

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        normalizeForm(form);
        setTimeout(function () { restoreForm(form); }, 0);
    }, true);

    document.addEventListener('DOMContentLoaded', function () {
        enhanceAll(document);
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
        formatTyping: formatTyping,
        enhance: enhance,
        enhanceAll: enhanceAll,
        normalizeForm: normalizeForm,
        restoreForm: restoreForm
    };
})(window);
