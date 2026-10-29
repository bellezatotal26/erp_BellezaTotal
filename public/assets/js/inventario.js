// Seri ERP - Módulo Inventario
var curSym = document.querySelector('meta[name="currency-symbol"]')?.content || '$';
var curDec = parseInt(document.querySelector('meta[name="currency-decimals"]')?.content || '0');

function invParseMoney(raw) {
    if (window.SeriMoney && typeof SeriMoney.parse === 'function') {
        return SeriMoney.parse(raw) || 0;
    }
    var s = String(raw == null ? '' : raw).replace(/[^\d.,\-]/g, '').replace(',', '.');
    var n = parseFloat(s);
    return isNaN(n) ? 0 : n;
}

function invFmtMoney(n) {
    n = Number(n) || 0;
    if (window.SeriMoney && typeof SeriMoney.format === 'function') {
        return curSym + ' ' + SeriMoney.format(n);
    }
    return curSym + ' ' + n.toFixed(curDec);
}

/** Utilidad unitaria y % sobre costo. */
function invUnitMargin(cost, sale) {
    cost = Math.max(0, Number(cost) || 0);
    sale = Math.max(0, Number(sale) || 0);
    var profit = Math.round((sale - cost) * 100) / 100;
    if (cost < 0.01) {
        return { profit: profit, pct: null, hasCost: false };
    }
    return {
        profit: profit,
        pct: Math.round((profit / cost) * 1000) / 10,
        hasCost: true
    };
}

function marginLine(cost, sale) {
    var m = invUnitMargin(cost, sale);
    if (!m.hasCost && sale < 0.01) return '—';
    if (!m.hasCost) return invFmtMoney(m.profit) + ' · % n/d (sin costo)';
    var sign = m.pct >= 0 ? '+' : '';
    return invFmtMoney(m.profit) + ' · ' + sign + String(m.pct).replace('.', ',') + '% s/costo';
}

function updateProdMarginHint() {
    var el = document.getElementById('prodMarginHint');
    if (!el) return;
    var cost = invParseMoney(document.getElementById('prodPcompra') && document.getElementById('prodPcompra').value);
    var sale = invParseMoney(document.getElementById('prodPventa') && document.getElementById('prodPventa').value);
    var m = invUnitMargin(cost, sale);
    el.classList.remove('inv-margin--ok', 'inv-margin--bad', 'inv-margin--muted');
    if (!m.hasCost && sale < 0.01 && cost < 0.01) {
        el.classList.add('inv-margin--muted');
        el.textContent = 'Utilidad y % de ganancia aparecerán al indicar compra y venta.';
        return;
    }
    if (!m.hasCost) {
        el.classList.add('inv-margin--muted');
        el.textContent = 'Utilidad: ' + invFmtMoney(m.profit) + ' · % n/d (indique precio de compra).';
        return;
    }
    el.classList.add(m.profit > 0.009 ? 'inv-margin--ok' : (m.profit < -0.009 ? 'inv-margin--bad' : 'inv-margin--muted'));
    var sign = m.pct >= 0 ? '+' : '';
    el.textContent = 'Utilidad unitaria: ' + invFmtMoney(m.profit)
        + ' · Ganancia: ' + sign + String(m.pct).replace('.', ',') + '% sobre el costo';
}

// Funciones del modal de producto
function openInventarioModal() {
    document.getElementById('modalTitle').textContent = 'Nuevo Producto';
    document.getElementById('productForm').action = window.invRouteCreate;
    document.getElementById('submitBtn').textContent = 'Crear';
    document.getElementById('statusGroup').style.display = 'none';
    var statusReasonGroup = document.getElementById('statusReasonGroup');
    if (statusReasonGroup) statusReasonGroup.style.display = 'none';
    var statusReason = document.getElementById('prodStatusReason');
    if (statusReason) statusReason.value = '';
    ['prodId','prodCode','prodBarcode','prodName','prodDesc','prodNotes'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.value = '';
    });
    resetProductNotesUi(false);
    setProdUnit('UND');
    document.getElementById('prodType').value = 'product';
    document.getElementById('prodCat').value = '';
    var loc = document.getElementById('prodLocation');
    if (loc) loc.value = '';
    document.getElementById('prodPcompra').value = '';
    document.getElementById('prodPventa').value = '';
    if (typeof updateProdMarginHint === 'function') updateProdMarginHint();
    var taxEl = document.getElementById('prodTaxRate');
    if (taxEl) taxEl.value = (typeof window.invDefaultTaxRate !== 'undefined') ? String(window.invDefaultTaxRate) : '';
    var mayoreo = document.getElementById('prodPmayoreo');
    if (mayoreo) mayoreo.value = '';
    document.getElementById('prodStock').value = '0';
    var stockOrig = document.getElementById('prodStockOriginal');
    if (stockOrig) stockOrig.value = '0';
    var stockAdj = document.getElementById('prodStockAdjNote');
    if (stockAdj) stockAdj.value = '';
    var stockAdjGroup = document.getElementById('stockAdjNoteGroup');
    if (stockAdjGroup) stockAdjGroup.style.display = 'none';
    var stockLabel = document.getElementById('prodStockLabel');
    if (stockLabel) stockLabel.innerHTML = 'Stock Inicial <span class="field-tip" data-tip="Cantidad disponible al crear el producto.">?</span>';
    document.getElementById('prodMinStock').value = '5';
    document.getElementById('prodMaxStock').value = '';
    document.getElementById('prodTrackLots').checked = false;
    document.getElementById('prodAlertDays').value = '30';
    document.getElementById('prodExpiry').value = '';
    document.getElementById('prodLotCode').value = '';
    var sup = document.getElementById('prodSupplier');
    if (sup) sup.value = '';
    var sku = document.getElementById('prodSupplierSku');
    if (sku) sku.value = '';
    onTypeChange();
    document.getElementById('productModal').style.display = 'flex';
    // Pistola lectora: foco en EAN (no en SKU).
    setTimeout(function () {
        var ean = document.getElementById('prodBarcode');
        if (ean) {
            try { ean.focus(); } catch (e) { /* ignore */ }
        }
    }, 50);
}

/** Asegura que el select de unidad tenga el valor (incluye legado no listado). */
function setProdUnit(unit) {
    var el = document.getElementById('prodUnit');
    if (!el) return;
    var v = String(unit || 'UND').trim() || 'UND';
    var aliases = { UNIDAD: 'UNIDAD', UND: 'UND', LT: 'LTS', LTS: 'LTS', LITRO: 'LTS', LITROS: 'LTS', M: 'MT', MTS: 'MT', METRO: 'MT', METROS: 'MT' };
    var key = v.toUpperCase();
    if (aliases[key]) v = aliases[key];
    else v = key;
    var found = false;
    for (var i = 0; i < el.options.length; i++) {
        if (el.options[i].value === v) { found = true; break; }
    }
    if (!found) {
        var opt = document.createElement('option');
        opt.value = v;
        opt.textContent = v;
        el.appendChild(opt);
    }
    el.value = v;
}

function editProduct(id) {
    var card = document.querySelector('.product-card[data-id="' + id + '"]');
    var d = card ? card.dataset : {};
    document.getElementById('modalTitle').textContent = 'Editar Producto';
    document.getElementById('productForm').action = window.invRouteEdit;
    document.getElementById('submitBtn').textContent = 'Guardar';
    document.getElementById('statusGroup').style.display = '';
    document.getElementById('prodId').value = d.id || '';
    document.getElementById('prodType').value = d.type || 'product';
    document.getElementById('prodCode').value = d.code || '';
    var statusEl = document.getElementById('prodStatus');
    if (statusEl) {
        statusEl.value = d.status || 'active';
        statusEl.dataset.originalStatus = d.status || 'active';
    }
    var statusReason = document.getElementById('prodStatusReason');
    if (statusReason) statusReason.value = '';
    syncStatusReasonVisibility();
    // keep following lines from original editProduct
    var barcodeEl = document.getElementById('prodBarcode');
    if (barcodeEl) barcodeEl.value = d.barcode || '';
    document.getElementById('prodName').value = d.name || '';
    document.getElementById('prodCat').value = d.cat || '';
    var loc = document.getElementById('prodLocation');
    if (loc) loc.value = d.location || '';
    setProdUnit(d.unit || 'UND');
    document.getElementById('prodPcompra').value = window.SeriMoney ? SeriMoney.format(d.pcompra || 0) : (d.pcompra || 0);
    document.getElementById('prodPventa').value = window.SeriMoney ? SeriMoney.format(d.pventa || 0) : (d.pventa || 0);
    if (typeof updateProdMarginHint === 'function') updateProdMarginHint();
    var taxEl = document.getElementById('prodTaxRate');
    if (taxEl) taxEl.value = (d.taxrate != null && d.taxrate !== '') ? d.taxrate : ((typeof window.invDefaultTaxRate !== 'undefined') ? String(window.invDefaultTaxRate) : '0');
    var mayoreo = document.getElementById('prodPmayoreo');
    if (mayoreo) mayoreo.value = window.SeriMoney ? SeriMoney.format(d.pmayoreo || 0) : (d.pmayoreo || 0);
    document.getElementById('prodStock').value = d.stock || 0;
    var stockOrig = document.getElementById('prodStockOriginal');
    if (stockOrig) stockOrig.value = String(d.stock || 0);
    var stockAdj = document.getElementById('prodStockAdjNote');
    if (stockAdj) stockAdj.value = '';
    var stockLabel = document.getElementById('prodStockLabel');
    if (stockLabel) stockLabel.innerHTML = 'Stock (conteo físico) <span class="field-tip" data-tip="Saldo final real. Si hubo descuadre o error (ej. vendió unidades que sí existían), corrija aquí el conteo; no use Lote N si no llegó mercancía nueva. Queda en Trazabilidad.">?</span>';
    if (typeof onProdStockChange === 'function') onProdStockChange();
    document.getElementById('prodMinStock').value = d.minstock || 5;
    document.getElementById('prodMaxStock').value = d.maxstock || '';
    document.getElementById('prodTrackLots').checked = d.tracklots === '1';
    document.getElementById('prodAlertDays').value = d.alertdays || 30;
    // El lote solo se captura en entradas de mercancía, no al editar la ficha.
    document.getElementById('prodExpiry').value = '';
    document.getElementById('prodLotCode').value = '';
    var descEl = document.getElementById('prodDesc');
    if (descEl) descEl.value = d.desc || '';
    var notesEl = document.getElementById('prodNotes');
    if (notesEl) notesEl.value = '';
    var sup = document.getElementById('prodSupplier');
    if (sup) sup.value = d.supplierId || '';
    var sku = document.getElementById('prodSupplierSku');
    if (sku) sku.value = d.supplierSku || '';
    onTypeChange();
    resetProductNotesUi(true);
    loadProductNotesHistory(id);
    document.getElementById('productModal').style.display = 'flex';
}

function resetProductNotesUi(editing) {
    var section = document.getElementById('prodNotesSection');
    var wrap = document.getElementById('prodNotesHistoryWrap');
    var label = document.getElementById('prodNotesLabel');
    var hint = document.getElementById('prodNotesHint');
    var modalBox = document.getElementById('productModalContent');
    var countEl = document.getElementById('prodNotesHistoryCount');
    var list = document.getElementById('prodNotesHistory');
    if (section) {
        section.style.gridTemplateColumns = editing ? '1fr 1fr' : '1fr';
    }
    if (wrap) wrap.style.display = editing ? '' : 'none';
    if (modalBox) modalBox.style.maxWidth = editing ? '820px' : '600px';
    if (label) {
        label.innerHTML = editing
            ? 'Nueva nota interna <span class="field-tip" data-tip="Se agrega al historial con su usuario y fecha al guardar. Deje vacío para no agregar nota.">?</span>'
            : 'Notas internas <span class="field-tip" data-tip="Al crear o editar, cada texto se agrega al histórico con fecha y usuario. No sobrescribe notas anteriores.">?</span>';
    }
    if (hint) {
        hint.textContent = editing
            ? 'Al guardar se agrega al histórico (no modifica notas anteriores).'
            : 'Quedará como nota inicial del producto.';
    }
    if (countEl) countEl.textContent = '';
    if (list) list.innerHTML = '<p style="margin:0;color:var(--color-text-secondary);">Cargando…</p>';
    if (!editing && list) {
        list.innerHTML = '<p style="margin:0;color:var(--color-text-secondary);">Sin notas aún</p>';
    }
}

function formatProductNoteWhen(iso) {
    var s = String(iso || '');
    if (!s) return '—';
    var d = new Date(s.replace(' ', 'T'));
    if (isNaN(d.getTime())) return s.substr(0, 16);
    var pad = function(n) { return n < 10 ? '0' + n : String(n); };
    return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear()
        + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
}

function renderProductNotesHistory(notes) {
    var list = document.getElementById('prodNotesHistory');
    var countEl = document.getElementById('prodNotesHistoryCount');
    if (!list) return;
    notes = Array.isArray(notes) ? notes : [];
    if (countEl) countEl.textContent = notes.length ? (notes.length + ' nota(s)') : '';
    if (!notes.length) {
        list.innerHTML = '<p style="margin:0;color:var(--color-text-secondary);">Sin notas aún. Escriba una a la izquierda y guarde.</p>';
        return;
    }
    var h = '';
    notes.forEach(function(n) {
        var who = n.user_name ? esc(n.user_name) : 'Sistema / legado';
        h += '<div style="padding:8px 0;border-bottom:1px solid var(--color-border);">'
            + '<div style="font-size:11px;color:var(--color-text-secondary);margin-bottom:3px;">'
            + '<strong>' + esc(formatProductNoteWhen(n.created_at)) + '</strong> · ' + who
            + '</div>'
            + '<div style="white-space:pre-wrap;line-height:1.35;">' + esc(n.note || '') + '</div>'
            + '</div>';
    });
    list.innerHTML = h;
}

function loadProductNotesHistory(productId) {
    var url = (window.invRouteProductNotes || (window.invRouteBase + '?action=product_notes'))
        + '&id=' + encodeURIComponent(productId);
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            renderProductNotesHistory((d && d.notes) || []);
        })
        .catch(function() {
            renderProductNotesHistory([]);
        });
}

function productNotesHistoryHtml(notes) {
    notes = Array.isArray(notes) ? notes : [];
    if (!notes.length) {
        return '<p style="margin:0 0 12px;padding:8px 10px;background:var(--bg-input);border-radius:8px;font-size:13px;color:var(--color-text-secondary);"><strong>Notas internas:</strong> sin historial</p>';
    }
    var h = '<div style="margin:0 0 12px;padding:10px 12px;background:var(--bg-input);border-radius:8px;">'
        + '<strong style="font-size:13px;">Notas internas (' + notes.length + ')</strong>'
        + '<div style="max-height:220px;overflow:auto;margin-top:8px;">';
    notes.forEach(function(n) {
        var who = n.user_name ? esc(n.user_name) : 'Sistema / legado';
        h += '<div style="padding:8px 0;border-bottom:1px solid var(--color-border);font-size:13px;">'
            + '<div style="font-size:11px;color:var(--color-text-secondary);margin-bottom:3px;">'
            + '<strong>' + esc(formatProductNoteWhen(n.created_at)) + '</strong> · ' + who
            + '</div>'
            + '<div style="white-space:pre-wrap;line-height:1.35;">' + esc(n.note || '') + '</div>'
            + '</div>';
    });
    h += '</div></div>';
    return h;
}

/** Reserva el siguiente EAN de la secuencia (Configuración → Preferencias). */
function generateProductEan() {
    var input = document.getElementById('prodBarcode');
    if (!input) return;
    if (input.value && String(input.value).trim() !== '') {
        if (!confirm('El campo EAN ya tiene un valor. ¿Reemplazarlo con el siguiente de la secuencia?')) {
            return;
        }
    }
    var fd = new FormData();
    fd.append('csrf_token', window.invCsrf || '');
    fetch(window.invRouteGenerateBarcode || (window.invRouteBase + '?action=generate_barcode'), {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d || !d.success || !d.barcode) {
            throw new Error((d && d.message) || 'No se pudo generar el EAN');
        }
        input.value = d.barcode;
        if (typeof showAlert === 'function') showAlert(d.message || ('EAN ' + d.barcode), 'success');
    })
    .catch(function(err) {
        if (typeof showAlert === 'function') showAlert(err.message || 'Error al generar EAN', 'error');
    });
}

/** Reserva el siguiente SKU de la secuencia (Configuración → Preferencias). */
function generateProductSku() {
    var input = document.getElementById('prodCode');
    if (!input) return;
    if (input.value && String(input.value).trim() !== '') {
        if (!confirm('El campo SKU ya tiene un valor. ¿Reemplazarlo con el siguiente de la secuencia?')) {
            return;
        }
    }
    var fd = new FormData();
    fd.append('csrf_token', window.invCsrf || '');
    fetch(window.invRouteGenerateSku || (window.invRouteBase + '?action=generate_sku'), {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d || !d.success || !d.sku) {
            throw new Error((d && d.message) || 'No se pudo generar el SKU');
        }
        input.value = d.sku;
        if (typeof showAlert === 'function') showAlert(d.message || ('SKU ' + d.sku), 'success');
    })
    .catch(function(err) {
        if (typeof showAlert === 'function') showAlert(err.message || 'Error al generar SKU', 'error');
    });
}

function onTypeChange() {
    var isSvc = document.getElementById('prodType').value === 'service';
    document.getElementById('stockGroup').style.display = isSvc ? 'none' : '';
    document.getElementById('minStockGroup').style.display = isSvc ? 'none' : '';
    document.getElementById('maxStockGroup').style.display = isSvc ? 'none' : '';
    document.getElementById('trackLotsGroup').style.display = isSvc ? 'none' : '';
    onTrackLotsChange();
}

function onTrackLotsChange() {
    var isSvc = document.getElementById('prodType').value === 'service';
    var on = !isSvc && document.getElementById('prodTrackLots').checked;
    var isNew = !document.getElementById('prodId').value;
    document.querySelectorAll('.lot-field').forEach(function(el) {
        // El lote inicial solo tiene sentido al crear el producto con stock.
        var initialOnly = el.id === 'expiryGroup' || el.id === 'lotCodeGroup';
        el.style.display = (on && (isNew || !initialOnly)) ? '' : 'none';
    });
}

function closeInvModal() {
    document.getElementById('productModal').style.display = 'none';
}

function addStock(id) {
    openAddLotForProduct(id, {});
}

/** Abre ingreso de stock/remesa. Usado también cuando se intenta crear un SKU duplicado. */
function openAddLotForProduct(id, prefill) {
    prefill = prefill || {};
    var card = document.querySelector('.product-card[data-id="' + id + '"]')
        || document.querySelector('.inv-list-row[data-id="' + id + '"]');
    var d = card ? card.dataset : {};
    // dataset.name puede no existir en filas de lista: usar texto de la celda si hace falta
    if (!d.name && card) {
        var nameCell = card.querySelector('.inv-td-name, .product-card-name, td:nth-child(3)');
        if (nameCell) d.name = nameCell.textContent.trim();
    }
    document.getElementById('stockProdId').value = id;
    var labelName = d.name || (prefill.name || '');
    document.getElementById('stockProdName').textContent = (labelName ? (labelName + ' · ') : '') + 'Lote N';
    var debtAlert = document.getElementById('stockDebtAlert');
    var debtQty = parseInt(d.debt || '0', 10) || 0;
    var curStock = parseInt(d.stock || '0', 10) || 0;
    if (debtAlert) {
        if (debtQty > 0 || curStock < 0) {
            debtAlert.style.display = 'block';
            var bits = [];
            if (curStock < 0) {
                bits.push('Stock actual: <strong>' + curStock + '</strong>');
            }
            if (debtQty > 0) {
                bits.push('<strong>' + debtQty + '</strong> uds vendidas sin stock (deuda)');
            }
            debtAlert.innerHTML = bits.join(' · ') + '. '
                + 'Al ingresar mercancía se <strong>suma</strong> al stock (ej. −1 + 2 = 1) y se cubre la deuda de venta. '
                + 'El stock <em>no</em> se vuelve a restar.';
        } else {
            debtAlert.style.display = 'none';
            debtAlert.textContent = '';
        }
    }
    var negWrap = document.getElementById('stockNegConfirmWrap');
    var negCb = document.getElementById('stockNegConfirm');
    var negText = document.getElementById('stockNegConfirmText');
    if (negWrap) {
        if (curStock < 0) {
            negWrap.style.display = 'block';
            if (negText) {
                negText.innerHTML = 'Producto en stock negativo (<strong>' + curStock + '</strong>). '
                    + 'Si al sumar la cantidad pasa a 0 o más, debe confirmar que es un ajuste manual '
                    + '(preferible: registrar en <strong>Compras</strong>).';
            }
            if (negCb) {
                negCb.checked = false;
                negCb.required = true;
            }
        } else {
            negWrap.style.display = 'none';
            if (negCb) {
                negCb.checked = false;
                negCb.required = false;
            }
        }
    }
    var tracks = d.tracklots === '1' || !!prefill.force_lots;
    var hint = document.getElementById('stockLotHint');
    var expiry = document.getElementById('stockExpiryDate');
    if (hint) hint.style.display = tracks ? 'block' : 'none';
    if (expiry) {
        expiry.required = !!tracks;
        expiry.value = prefill.expiry_date || '';
    }
    var lotCode = document.getElementById('stockLotCode');
    if (lotCode) lotCode.value = prefill.lot_code || '';
    var qty = document.querySelector('#stockModal input[name="quantity"]');
    if (qty && prefill.quantity) {
        qty.value = prefill.quantity;
    }
    document.getElementById('stockModal').style.display = 'flex';
    if (!tracks && !d.tracklots) {
        // Sin card en DOM (p.ej. otra página de resultados): el backend activará lotes si hay expiry.
    }
}

window.openAddLotForProduct = openAddLotForProduct;

function productCostSummaryHtml(cs, p, moneyVal) {
    if (!cs) return '';
    var current = parseFloat(cs.avg_cost) || 0;
    var avg = parseFloat(cs.purchase_avg) || 0;
    function cell(label, value, sub, tip) {
        return '<div style="background:var(--bg-input);border-radius:8px;padding:8px 10px;"' + (tip ? ' title="' + esc(tip) + '"' : '') + '>' +
            '<div style="font-size:11px;color:var(--color-text-secondary);text-transform:uppercase;letter-spacing:.3px;">' + label + '</div>' +
            '<div style="font-size:15px;font-weight:700;">' + value + '</div>' +
            (sub ? '<div style="font-size:11px;color:var(--color-text-secondary);">' + sub + '</div>' : '') +
            '</div>';
    }
    function vsAvg(v) {
        v = parseFloat(v) || 0;
        if (!(avg > 0) || !(v > 0)) return '';
        var pct = ((v - avg) / avg) * 100;
        if (Math.abs(pct) < 0.05) return 'igual al promedio';
        var color = pct > 0 ? 'var(--dmc-DC2626, #DC2626)' : 'var(--dmc-047857, #047857)';
        return '<span style="color:' + color + ';font-weight:600;">' + (pct > 0 ? '+' : '') + pct.toFixed(1) + '%</span> vs promedio';
    }
    var h = '<h4 style="margin:0 0 8px;">Costos</h4>';
    h += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-bottom:14px;">';
    var curCmp = vsAvg(current);
    h += cell('Precio de costo vigente', moneyVal(current),
        'Valor inventario: ' + moneyVal(cs.stock_value) + (curCmp ? '<br>' + curCmp : ''),
        'P. Compra actual del producto: base de la utilidad y del valor del inventario.');
    if (cs.purchase_avg != null) {
        h += cell('Costo promedio compras', moneyVal(cs.purchase_avg),
            cs.purchase_count + ' compra(s) · ' + cs.purchase_units + ' ud(s)<br>Mín ' + moneyVal(cs.purchase_min) + ' · Máx ' + moneyVal(cs.purchase_max),
            'Promedio de todas las compras ingresadas, ponderado por cantidad (excluye OC anuladas).');
    }
    if (cs.last_cost != null) {
        var lastSub = (cs.last_cost_date ? esc(String(cs.last_cost_date).substr(0, 10)) : '') +
            (cs.last_cost_ref ? ' · ' + esc(cs.last_cost_ref) : '');
        var cmp = vsAvg(cs.last_cost);
        h += cell('Último costo de compra', moneyVal(cs.last_cost), lastSub + (cmp ? '<br>' + cmp : ''),
            'Costo unitario con el que entró la última compra al inventario.');
    } else {
        h += cell('Último costo de compra', '—', 'Sin compras registradas', '');
    }
    if (cs.purchase_avg_90 != null) {
        h += cell('Prom. compras 90 días', moneyVal(cs.purchase_avg_90),
            cs.purchase_units_90 + ' ud(s) · ' + vsAvg(cs.purchase_avg_90),
            'Promedio de las compras de los últimos 90 días, ponderado por cantidad.');
    }
    h += '</div>';
    return h;
}

/** Historial del precio de costo: gráfica (más antiguo → reciente) + tabla. */
function productCostHistoryHtml(rows, moneyVal) {
    rows = rows || [];
    var h = '<h4 style="margin:0 0 8px;">Historial de costos (' + rows.length + ')</h4>';
    if (!rows.length) {
        return h + '<p style="text-align:center;color:var(--color-text-secondary);margin:0 0 14px;">Sin compras ni cambios de costo registrados</p>';
    }
    function costOf(r) {
        var n = r.new_cost != null ? parseFloat(r.new_cost) : NaN;
        if (isFinite(n) && n > 0) return n;
        var u = parseFloat(r.unit_cost_in);
        return isFinite(u) && u > 0 ? u : 0;
    }
    var pts = rows.slice().reverse().filter(function(r) { return costOf(r) > 0; });
    var sum = 0;
    pts.forEach(function(r) { sum += costOf(r); });
    var avg = pts.length ? sum / pts.length : 0;

    if (pts.length >= 2) {
        var W = 600, H = 130, padL = 8, padR = 8, padT = 14, padB = 18;
        var vals = pts.map(costOf);
        var min = Math.min.apply(null, vals), max = Math.max.apply(null, vals);
        if (max - min < 0.01) { max += 1; min = Math.max(0, min - 1); }
        var x = function(i) { return padL + (i * (W - padL - padR) / (pts.length - 1)); };
        var y = function(v) { return padT + (H - padT - padB) * (1 - (v - min) / (max - min)); };
        var path = vals.map(function(v, i) { return (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(v).toFixed(1); }).join(' ');
        var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" style="width:100%;height:130px;display:block;">';
        svg += '<line x1="' + padL + '" x2="' + (W - padR) + '" y1="' + y(avg).toFixed(1) + '" y2="' + y(avg).toFixed(1) + '" stroke="#F59E0B" stroke-dasharray="5 4" stroke-width="1.5"/>';
        svg += '<path d="' + path + '" fill="none" stroke="#7C3AED" stroke-width="2.2" vector-effect="non-scaling-stroke"/>';
        vals.forEach(function(v, i) {
            var r = pts[i];
            svg += '<circle cx="' + x(i).toFixed(1) + '" cy="' + y(v).toFixed(1) + '" r="3.5" fill="' + (r.source === 'manual' ? '#DC2626' : '#7C3AED') + '">' +
                '<title>' + esc(String(r.date || '').substr(0, 10)) + ' · ' + esc(invFmtMoney(v)) + (r.ref ? ' · ' + esc(r.ref) : '') + '</title></circle>';
        });
        svg += '</svg>';
        h += '<div style="background:var(--bg-input);border-radius:8px;padding:8px 10px;margin-bottom:8px;">' + svg +
            '<div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;font-size:11px;color:var(--color-text-secondary);">' +
            '<span>' + esc(String(pts[0].date || '').substr(0, 10)) + '</span>' +
            '<span><b style="color:#7C3AED;">━</b> Precio de costo · <b style="color:#F59E0B;">┅</b> Promedio ' + moneyVal(avg) +
            ' · Mín ' + moneyVal(Math.min.apply(null, vals)) + ' · Máx ' + moneyVal(Math.max.apply(null, vals)) + '</span>' +
            '<span>' + esc(String(pts[pts.length - 1].date || '').substr(0, 10)) + '</span></div></div>';
    } else {
        h += '<p style="margin:0 0 8px;font-size:13px;">Promedio: <strong>' + moneyVal(avg) + '</strong></p>';
    }

    var srcLabel = { purchase: 'Compra', manual: 'Edición manual', entry: 'Entrada' };
    h += '<div style="max-height:260px;overflow:auto;margin-bottom:14px;"><table><thead><tr>' +
        '<th>Fecha</th><th>Origen</th><th>Cant</th><th title="Costo unitario con el que entró la mercancía">Costo entrada</th>' +
        '<th title="Precio de costo del producto antes → después">Precio de costo</th><th>Var.</th></tr></thead><tbody>';
    rows.forEach(function(r) {
        var origin = (srcLabel[r.source] || r.source || '—');
        if (r.ref) origin += '<div style="font-size:11px;color:var(--color-text-secondary);">' + esc(r.ref) + '</div>';
        if (r.supplier) origin += '<div style="font-size:11px;color:var(--color-text-secondary);">' + esc(r.supplier) + '</div>';
        if (r.user) origin += '<div style="font-size:11px;color:var(--color-text-secondary);">' + esc(r.user) + '</div>';
        var oldC = r.old_cost != null ? parseFloat(r.old_cost) : null;
        var newC = r.new_cost != null ? parseFloat(r.new_cost) : null;
        var change = '—';
        if (newC != null) {
            change = (oldC != null ? moneyVal(oldC) + ' → ' : '') + '<strong>' + moneyVal(newC) + '</strong>';
        } else if (!Number(r.logged)) {
            change = '<span style="color:var(--color-text-secondary);" title="Compra anterior al registro de historial">No registrado</span>';
        }
        var varCell = '';
        if (oldC != null && newC != null && oldC > 0) {
            var pct = ((newC - oldC) / oldC) * 100;
            if (Math.abs(pct) >= 0.05) {
                varCell = '<span style="color:' + (pct > 0 ? 'var(--dmc-DC2626, #DC2626)' : 'var(--dmc-047857, #047857)') + ';font-weight:600;">' +
                    (pct > 0 ? '+' : '') + pct.toFixed(1) + '%</span>';
            } else {
                varCell = '<span style="color:var(--color-text-secondary);">0%</span>';
            }
        }
        h += '<tr><td style="white-space:nowrap;font-size:12px;">' + esc(String(r.date || '').substr(0, 10)) + '</td>' +
            '<td style="font-size:12px;">' + origin + '</td>' +
            '<td>' + (r.qty != null ? r.qty : '—') + '</td>' +
            '<td>' + (r.unit_cost_in != null ? moneyVal(r.unit_cost_in) : '—') + '</td>' +
            '<td style="white-space:nowrap;">' + change + '</td>' +
            '<td>' + varCell + '</td></tr>';
    });
    h += '</tbody></table></div>';
    return h;
}

function viewDetail(id) {
    fetch(window.invRouteDetail + '&id=' + id, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.error) return showAlert(d.error, 'error');
        var p = d.product, m = d.movements || [], s = d.lastSales || [];
        var isSvc = p.product_type === 'service';
        function moneyVal(n) { return invFmtMoney(n); }
        var h = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:15px;">';
        h += '<div><strong>Nombre:</strong> ' + esc(p.name) + '</div>';
        h += '<div><strong>Tipo:</strong> ' + (isSvc ? 'Servicio' : 'Producto') + '</div>';
        h += '<div><strong>SKU:</strong> ' + esc(p.code || '-') + '</div>';
        h += '<div><strong>EAN:</strong> ' + esc(p.barcode || '-') + '</div>';
        h += '<div><strong>Categoría:</strong> ' + esc(p.category_name || '-') + '</div>';
        h += '<div><strong>Ubicación:</strong> ' + esc(p.location || '-') + '</div>';
        h += '<div title="Precio de costo vigente del producto (se actualiza con cada compra o edición; ver Historial de costos)"><strong>P. Compra:</strong> ' + moneyVal(p.purchase_price) + '</div>';
        h += '<div><strong>P. Venta:</strong> ' + moneyVal(p.sale_price) + '</div>';
        h += '<div style="grid-column:1/-1;"><strong>Utilidad / ganancia:</strong> ' + marginLine(p.purchase_price, p.sale_price) + '</div>';
        h += '<div><strong>Stock:</strong> ' + (isSvc ? 'N/A' : p.stock) + '</div>';
        h += '<div><strong>Creado:</strong> ' + (p.created_at || '').substr(0, 10) + '</div>';
        h += '<div><strong>Última Venta:</strong> ' + ((p.last_sale_date || '').substr(0, 10) || 'Nunca') + '</div>';
        h += '</div>';
        if (!isSvc) {
            h += productCostSummaryHtml(d.cost_summary, p, moneyVal);
            h += productCostHistoryHtml(d.cost_history, moneyVal);
        }
        h += '<p style="margin:0 0 12px;padding:8px 10px;background:var(--bg-input);border-radius:8px;font-size:13px;white-space:pre-wrap;"><strong>Descripción:</strong> ' + esc(p.description || '—') + '</p>';
        h += productNotesHistoryHtml(d.notes_history && d.notes_history.length ? d.notes_history : (p.notes ? [{ note: p.notes, created_at: '', user_name: null }] : []));
        var debts = d.stock_debts || [];
        if (debts.length) {
            h += '<h4>Deuda de stock (vendido sin inventario)</h4><ul style="margin:0 0 12px;padding-left:18px;">';
            debts.forEach(function(x) {
                h += '<li>' + x.qty_remaining + ' uds · ' + esc(x.reason || '') +
                    (x.invoice_number ? (' · ' + esc(x.invoice_number)) : '') +
                    (x.note ? (' — ' + esc(x.note)) : '') + '</li>';
            });
            h += '</ul>';
        }
        h += '<h4>Movimientos (' + m.length + ')</h4>';
        if (m.length === 0) {
            h += '<p style="text-align:center;color:var(--color-text-secondary);">Sin movimientos</p>';
        } else {
            h += '<table><thead><tr><th>Fecha</th><th>Origen</th><th>Tipo</th><th>Cant</th><th>Detalle</th></tr></thead><tbody>';
            m.forEach(function(x) {
                var isRelatedSub = Number(x.related_substitution) === 1;
                var isRelatedOrigin = Number(x.related_loan_origin) === 1;
                var hasSub = Number(x.has_substitution) === 1;
                var t = x.type === 'in' ? 'Entrada' : (x.type === 'out' ? 'Salida' : 'Ajuste');
                var bc = x.type === 'in' ? 'badge-success' : (x.type === 'out' ? 'badge-danger' : 'badge-warning');
                if (isRelatedSub) {
                    t = 'Cambio';
                    bc = 'badge-warning';
                } else if (isRelatedOrigin) {
                    t = 'Origen';
                    bc = 'badge-secondary';
                } else if (hasSub) {
                    t = 'Salida';
                    bc = 'badge-danger';
                }
                var when = String(x.display_at || x.created_at || x.movement_date || '').substr(0, 19);
                var origin = x.origin_label || x.reference_type || 'Movimiento';
                if (isRelatedSub || isRelatedOrigin) {
                    var relCode = String(x.related_product_code || '').trim();
                    var relName = String(x.related_product_name || '').trim();
                    if (relCode || relName) {
                        origin += '<div style="font-size:11px;color:var(--color-text-secondary);margin-top:2px;">'
                            + esc(relCode || '') + (relCode && relName ? ' · ' : '') + esc(relName || '')
                            + '</div>';
                    }
                } else if (hasSub) {
                    origin += '<div style="font-size:11px;color:var(--dmc-B45309, #B45309);margin-top:2px;">con cambio de referencia</div>';
                }
                var detail = x.motive || x.notes || '-';
                if (x.user_name) detail += (detail && detail !== '-' ? ' · ' : '') + x.user_name;
                var qtyCell = (x.quantity != null ? x.quantity : '—');
                if (Number(x.affects_local_stock) === 0) {
                    qtyCell = '<span title="No altera el stock de este SKU">' + qtyCell + '*</span>';
                }
                h += '<tr' + (isRelatedSub || isRelatedOrigin || hasSub ? ' style="background:rgba(245,158,11,.08);"' : '') + '>';
                h += '<td style="white-space:nowrap;font-size:12px;">' + esc(when) + '</td>';
                h += '<td style="font-size:12px;">' + origin + '</td>';
                h += '<td><span class="badge ' + bc + '">' + t + '</span></td>';
                h += '<td>' + qtyCell + '</td>';
                h += '<td style="font-size:12px;max-width:320px;">' + esc(detail) + '</td></tr>';
            });
            h += '</tbody></table>';
        }

        var purchases = d.purchases || [];
        h += '<h4 style="margin-top:15px;">Compras / facturas (' + purchases.length + ')</h4>';
        if (!purchases.length) {
            h += '<p style="text-align:center;color:var(--color-text-secondary);">Sin compras ni facturas de proveedor vinculadas</p>';
        } else {
            h += '<table><thead><tr><th>OC / Factura</th><th>Proveedor</th><th>Fecha</th><th>Cant</th>' +
                '<th title="Costo unitario de la factura (contabilidad)">Costo factura</th>' +
                '<th title="Costo con el que entró al inventario (promedio). Vacío = igual al costo factura">Costo inv.</th>' +
                '<th>Estado</th></tr></thead><tbody>';
            purchases.forEach(function(x) {
                var oc = String(x.order_number || ('#' + (x.purchase_order_id || '')));
                var inv = String(x.invoice_number || '').trim();
                var when = String(x.received_at || x.warehouse_date || x.invoice_date || x.created_at || '').substr(0, 16);
                h += '<tr><td>' + esc(oc) + (inv ? ('<div style="font-size:11px;color:var(--color-text-secondary);">Fact. ' + esc(inv) + '</div>') : '') + '</td>';
                h += '<td>' + esc(x.supplier_name || '—') + '</td>';
                h += '<td style="font-size:12px;">' + esc(when || '—') + '</td>';
                h += '<td>' + (x.received_qty != null && x.received_qty !== '' ? x.received_qty : (x.quantity || 0)) + '</td>';
                h += '<td>' + moneyVal(x.unit_cost) + '</td>';
                var invC = parseFloat(x.inventory_cost) || 0;
                h += '<td>' + (invC > 0 && Math.abs(invC - (parseFloat(x.unit_cost) || 0)) > 0.009
                    ? '<strong style="color:#7C3AED;">' + moneyVal(invC) + '</strong>'
                    : '<span style="color:var(--color-text-secondary);">= factura</span>') + '</td>';
                h += '<td><span class="badge badge-secondary">' + esc(String(x.status || '').toUpperCase()) + '</span></td></tr>';
            });
            h += '</tbody></table>';
        }
        var sups = d.suppliers || [];
        h += '<h4 style="margin-top:15px;">🚚 Proveedores (' + sups.length + ')</h4>';
        if (!sups.length) {
            h += '<p style="text-align:center;color:var(--color-text-secondary);">Sin proveedores vinculados</p>';
        } else {
            h += '<table><thead><tr><th>Proveedor</th><th>SKU proveedor</th><th>Último costo</th><th>Entrega</th><th>Mín. pedido</th><th></th></tr></thead><tbody>';
            sups.forEach(function(s) {
                h += '<tr><td>' + esc(s.supplier_name) + (s.is_primary === '1' || s.is_primary === 1 ? ' <span class="badge badge-success">Principal</span>' : '') + '</td>';
                h += '<td>' + esc(s.supplier_sku || '—') + '</td>';
                h += '<td>' + curSym + ' ' + parseFloat(s.last_cost || 0).toFixed(curDec) + '</td>';
                h += '<td>' + (parseInt(s.lead_time_days, 10) || 0) + ' d</td>';
                h += '<td>' + (parseInt(s.min_order_qty, 10) || 1) + '</td>';
                h += '<td><button type="button" class="btn btn-sm btn-danger" onclick="unlinkSupplier(' + p.id + ',' + s.supplier_id + ')">Quitar</button></td></tr>';
            });
            h += '</tbody></table>';
        }
        if (window.invCanEdit) {
            h += '<button type="button" class="btn btn-sm btn-secondary" style="margin-top:8px;" onclick="openSupplierLink(' + p.id + ', ' + JSON.stringify(esc(p.name)) + ')">Vincular proveedor</button>';
        }

        var lots = d.lots || [];
        if (p.track_lots == 1 || lots.length) {
            h += '<h4 style="margin-top:15px;">Lotes / remesas (' + lots.length + ')</h4>';
            h += '<p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 8px;">Cada remesa con distinta caducidad es un lote. La caja vende solo lotes activos (FEFO).</p>';
            if (!lots.length) {
                h += '<p style="color:var(--color-text-secondary);">Sin lotes registrados. Active el control y registre una entrada con fecha de vencimiento.</p>';
            } else {
                h += '<table><thead><tr><th>Lote</th><th>Vence</th><th>Días</th><th>Cant</th><th>Costo</th><th>Estado</th></tr></thead><tbody>';
                lots.forEach(function(l) {
                    var dias = (l.days_left === null || l.days_left === undefined || l.days_left === '') ? null : parseInt(l.days_left, 10);
                    var color = '';
                    var diasTxt = '—';
                    if (dias !== null && !isNaN(dias)) {
                        diasTxt = String(dias);
                        color = dias < 0 ? '#DC2626' : (dias <= 30 ? '#F59E0B' : '#16A34A');
                    }
                    var st = String(l.status || '-');
                    var stLabel = st === 'active' ? 'Activo' : (st === 'expired' ? 'Vencido' : (st === 'blocked' ? 'Bloqueado' : (st === 'depleted' ? 'Agotado' : st)));
                    h += '<tr><td>' + esc(l.lot_code || '-') + '</td>';
                    h += '<td' + (color ? ' style="color:' + color + ';font-weight:600;"' : '') + '>' + (l.expiry_date || '—') + '</td>';
                    h += '<td' + (color ? ' style="color:' + color + ';font-weight:600;"' : '') + '>' + diasTxt + '</td>';
                    h += '<td>' + l.quantity + '</td>';
                    h += '<td>' + curSym + ' ' + parseFloat(l.unit_cost || 0).toFixed(curDec) + '</td>';
                    h += '<td>' + esc(stLabel) + '</td></tr>';
                });
                h += '</tbody></table>';
                h += '<p style="margin-top:8px;"><a href="' + (window.invRouteBase || '') + '?action=vencimientos">Ir a control de vencimientos</a></p>';
            }
        }
        h += '<h4 style="margin-top:15px;">🛒 Últimas Ventas</h4>';
        if (s.length === 0) {
            h += '<p style="text-align:center;color:var(--color-text-secondary);">Sin ventas</p>';
        } else {
            h += '<table><thead><tr><th>Factura</th><th>Fecha</th><th>Cant</th></tr></thead><tbody>';
            s.forEach(function(x) {
                h += '<tr><td>' + esc(x.invoice_number) + '</td>';
                h += '<td>' + (x.sale_date || '').substr(0, 16) + '</td>';
                h += '<td>' + x.quantity + '</td></tr>';
            });
            h += '</tbody></table>';
        }
        document.getElementById('detailContent').innerHTML = h;
        document.getElementById('detailModal').style.display = 'flex';
    });
}

function openSupplierLink(productId, productName) {
    document.getElementById('psProductId').value = productId;
    document.getElementById('psProductName').textContent = productName;
    document.getElementById('psForm').reset();
    document.getElementById('psProductId').value = productId;
    document.getElementById('productSupplierModal').style.display = 'flex';
}

function closeSupplierLink() {
    document.getElementById('productSupplierModal').style.display = 'none';
}

function unlinkSupplier(productId, supplierId) {
    if (!confirm('¿Quitar este proveedor del producto?')) return;
    var form = document.getElementById('psUnlinkForm');
    form.querySelector('[name="product_id"]').value = productId;
    form.querySelector('[name="supplier_id"]').value = supplierId;
    form.submit();
}

function openImageLightbox(src, name) {
    var o = document.createElement('div');
    o.className = 'modal-overlay';
    o.style.display = 'flex';
    o.style.zIndex = '9999';
    o.onclick = function() { o.remove(); };
    o.innerHTML = '<div style="position:relative;max-width:80vw;max-height:80vh;" onclick="event.stopPropagation()">' +
        '<img src="' + src + '" style="max-width:100%;max-height:80vh;border-radius:16px;box-shadow:0 25px 50px rgba(0,0,0,0.3);">' +
        '<div style="position:absolute;bottom:-40px;left:0;right:0;text-align:center;color:#fff;font-size:16px;font-weight:600;">' + esc(name) + '</div>' +
        '<button onclick="this.parentElement.parentElement.remove()" style="position:absolute;top:-15px;right:-15px;width:36px;height:36px;background:#DC2626;color:#fff;border:none;border-radius:50%;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,0.3);">\u2715</button></div>';
    document.body.appendChild(o);
}

// ============================================
// Carrito de Compras
// ============================================
var cart = JSON.parse(localStorage.getItem('eva_cart') || '[]');

function addToCart(id, name, price, evt) {
    var ex = cart.find(function(i) { return i.id == id; });
    if (ex) { ex.qty++; }
    else { cart.push({ id: id, name: name, price: price, qty: 1 }); }
    saveCart();
    showAlert(name + ' agregado al carrito', 'success');
    var fromEl = (evt && evt.currentTarget) ? evt.currentTarget : (typeof event !== 'undefined' ? event.target : null);
    if (typeof animateAddToCart === 'function') {
        animateAddToCart(fromEl && fromEl.closest ? (fromEl.closest('.btn') || fromEl) : fromEl);
    }
}

function saveCart() {
    localStorage.setItem('eva_cart', JSON.stringify(cart));
    updateCartBadge();
}

function updateCartBadge() {
    var b = document.getElementById('cartBadge');
    if (!b) return;
    var total = cart.reduce(function(a, i) { return a + i.qty; }, 0);
    b.textContent = total;
    b.style.display = cart.length ? 'flex' : 'none';
}

function clearCart() {
    cart = [];
    saveCart();
}

function showCartModal() {
    if (cart.length === 0) {
        showAlert('Carrito vacío', 'warning');
        return;
    }
    var h = '<div style="max-height:50vh;overflow-y:auto;">';
    h += '<table style="width:100%;"><thead><tr><th>Producto</th><th>Precio</th><th>Cant</th><th>Subtotal</th><th></th></tr></thead><tbody>';
    var total = 0;
    cart.forEach(function(it, i) {
        var sub = it.price * it.qty;
        total += sub;
        h += '<tr>';
        h += '<td>' + esc(it.name) + '</td>';
        h += '<td>' + curSym + ' ' + it.price.toFixed(curDec) + '</td>';
        h += '<td><input type="number" value="' + it.qty + '" min="1" style="width:60px;" onchange="updateCartQty(' + i + ',this.value)"></td>';
        h += '<td>' + curSym + ' ' + sub.toFixed(curDec) + '</td>';
        h += '<td><button class="btn btn-sm btn-danger" onclick="removeCartItem(' + i + ')">✕</button></td>';
        h += '</tr>';
    });
    h += '</tbody></table></div>';
    h += '<p style="margin-top:15px;font-size:18px;font-weight:700;text-align:right;">Total: ' + curSym + ' ' + total.toFixed(curDec) + '</p>';
    h += '<div style="display:flex;gap:10px;margin-top:15px;">';
    h += '<button class="btn btn-secondary" onclick="clearCart();closeCartModal();">Vaciar</button>';
    h += '<button class="btn btn-secondary" onclick="closeCartModal()">Cerrar</button>';
    h += '<button class="btn btn-primary" style="flex:1;" onclick="checkoutCart()">Realizar Venta</button>';
    h += '</div>';
    document.getElementById('cartContent').innerHTML = h;
    document.getElementById('cartModal').style.display = 'flex';
}

function closeCartModal() {
    document.getElementById('cartModal').style.display = 'none';
}

function updateCartQty(i, q) {
    var n = parseInt(q);
    if (n < 1) return;
    cart[i].qty = n;
    saveCart();
    showCartModal();
}

function removeCartItem(i) {
    cart.splice(i, 1);
    saveCart();
    if (cart.length === 0) { closeCartModal(); }
    else { showCartModal(); }
}

function checkoutCart() {
    closeCartModal();
    window.location = window.invRouteVentas + '?fromCart=1';
}

document.addEventListener('DOMContentLoaded', function() {
    updateCartBadge();
    var savedView = 'grid';
    try {
        savedView = localStorage.getItem('seri_inv_view_mode') || 'grid';
    } catch (e) {}
    if (typeof setInventarioView === 'function') {
        setInventarioView(savedView === 'list' ? 'list' : 'grid', true);
    }
    initInvListColumnFilters();
    initInvLiveSearch();
});

/**
 * Búsqueda / filtros en vivo: al escribir SKU, EAN o nombre se aplica sin pulsar «Filtrar».
 * Los selects también se aplican al cambiar.
 */
function initInvLiveSearch() {
    var form = document.querySelector('.inv-filters-form');
    if (!form || form._liveSearchReady) return;
    form._liveSearchReady = true;

    var qInput = document.getElementById('invSearchQ') || form.querySelector('input[name="q"]');
    var timer = null;
    // Espera tras la última tecla: EAN/SKU largos y nombres con espacios.
    var DEBOUNCE_MS = 1400;

    function currentQueryFromUrl() {
        try {
            return new URL(window.location.href).searchParams.get('q') || '';
        } catch (e) {
            return '';
        }
    }

    function markKeepFocus() {
        try { sessionStorage.setItem('seri_inv_keep_search_focus', '1'); } catch (e) {}
    }

    function submitNow() {
        markKeepFocus();
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    if (qInput) {
        // Tras recarga por búsqueda en vivo, recuperar foco y cursor al final.
        try {
            if (sessionStorage.getItem('seri_inv_keep_search_focus') === '1') {
                sessionStorage.removeItem('seri_inv_keep_search_focus');
                qInput.focus();
                var len = (qInput.value || '').length;
                if (typeof qInput.setSelectionRange === 'function') {
                    qInput.setSelectionRange(len, len);
                }
            }
        } catch (e) {}

        qInput.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                var next = String(qInput.value || '');
                if (next === currentQueryFromUrl()) return;
                submitNow();
            }, DEBOUNCE_MS);
        });

        // Escáner / Enter: aplicar de inmediato (sin esperar debounce).
        qInput.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') {
                clearTimeout(timer);
                markKeepFocus();
                // el submit nativo del form GET ya ocurre
            }
        });
    }

    Array.prototype.forEach.call(form.querySelectorAll('select[name]'), function (sel) {
        sel.addEventListener('change', function () {
            clearTimeout(timer);
            submitNow();
        });
    });
}

function setInventarioView(mode, skipSave) {
    var catalog = document.getElementById('invCatalog');
    if (!catalog) return;
    var isList = mode === 'list';
    catalog.classList.toggle('inv-catalog--list', isList);
    catalog.classList.toggle('inv-catalog--grid', !isList);
    var gridBtn = document.getElementById('invViewGridBtn');
    var listBtn = document.getElementById('invViewListBtn');
    if (gridBtn) gridBtn.classList.toggle('is-active', !isList);
    if (listBtn) listBtn.classList.toggle('is-active', isList);
    if (!skipSave) {
        try { localStorage.setItem('seri_inv_view_mode', isList ? 'list' : 'grid'); } catch (e) {}
    }
    updateInvBulkState();
}

function initInvListColumnFilters() {
    var table = document.getElementById('invListTable');
    if (!table || table._filtersReady) return;
    table._filtersReady = true;
    var filters = table.querySelectorAll('.inv-col-filter');
    Array.prototype.forEach.call(filters, function (el) {
        el.addEventListener('input', applyInvListFilters);
        el.addEventListener('change', applyInvListFilters);
        el.addEventListener('keyup', applyInvListFilters);
    });
}

function clearInvListFilters() {
    var table = document.getElementById('invListTable');
    if (!table) return;
    Array.prototype.forEach.call(table.querySelectorAll('.inv-col-filter'), function (el) {
        el.value = '';
    });
    applyInvListFilters();
}

function applyInvListFilters() {
    var table = document.getElementById('invListTable');
    var body = document.getElementById('invListBody');
    if (!table || !body) return;

    /** Coincide si cada palabra del filtro aparece en el texto (AND). Comas = OR entre grupos. */
    function textMatches(cell, query) {
        var q = String(query || '').toLowerCase().trim();
        if (!q) return true;
        var cellL = String(cell || '').toLowerCase();
        var groups = q.split(/[,;]+/).map(function (g) { return g.trim(); }).filter(Boolean);
        if (!groups.length) return true;
        return groups.some(function (group) {
            var words = group.split(/\s+/).filter(Boolean);
            if (!words.length) return true;
            return words.every(function (w) { return cellL.indexOf(w) !== -1; });
        });
    }

    var vals = {};
    Array.prototype.forEach.call(table.querySelectorAll('.inv-col-filter'), function (el) {
        var col = el.getAttribute('data-col');
        vals[col] = String(el.value || '').toLowerCase().trim();
    });

    var rows = body.querySelectorAll('.inv-list-row');
    var visible = 0;
    Array.prototype.forEach.call(rows, function (row) {
        var ok = true;
        Object.keys(vals).forEach(function (col) {
            if (!ok || !vals[col]) return;
            var cell = String(row.getAttribute('data-' + col) || '').toLowerCase();
            if (col === 'type' || col === 'status') {
                if (cell !== vals[col]) ok = false;
                return;
            }
            if (!textMatches(cell, vals[col])) ok = false;
        });
        row.style.display = ok ? '' : 'none';
        if (ok) visible++;
    });

    var countEl = document.getElementById('invListFilterCount');
    if (countEl) countEl.textContent = visible + ' / ' + rows.length + ' filas';
    var emptyEl = document.getElementById('invListEmpty');
    if (emptyEl) emptyEl.hidden = visible > 0;
    updateInvBulkState();
}

/** IDs seleccionados (únicos; grid y lista comparten el mismo producto). */
function getInvSelectedIds() {
    var ids = {};
    document.querySelectorAll('.inv-product-check:checked').forEach(function (cb) {
        var id = parseInt(cb.value, 10);
        if (id > 0) ids[id] = true;
    });
    return Object.keys(ids).map(function (k) { return parseInt(k, 10); });
}

function syncInvChecks(id, checked) {
    document.querySelectorAll('.inv-product-check').forEach(function (cb) {
        if (String(cb.value) === String(id)) cb.checked = !!checked;
    });
}

function updateInvBulkState() {
    var ids = getInvSelectedIds();
    var countEl = document.getElementById('invBulkCount');
    var btn = document.getElementById('invBulkDeleteBtn');
    if (countEl) countEl.textContent = String(ids.length);
    if (btn) btn.disabled = ids.length === 0;

    var visibleChecks = [];
    var catalog = document.getElementById('invCatalog');
    var isList = catalog && catalog.classList.contains('inv-catalog--list');
    if (isList) {
        document.querySelectorAll('#invListBody .inv-list-row').forEach(function (row) {
            if (row.style.display === 'none') return;
            var cb = row.querySelector('.inv-product-check');
            if (cb) visibleChecks.push(cb);
        });
    } else {
        document.querySelectorAll('.inv-products-grid .inv-product-check').forEach(function (cb) {
            visibleChecks.push(cb);
        });
    }
    var allChecked = visibleChecks.length > 0 && visibleChecks.every(function (cb) { return cb.checked; });
    var selectAll = document.getElementById('invSelectAll');
    var selectAllList = document.getElementById('invSelectAllList');
    if (selectAll) selectAll.checked = allChecked;
    if (selectAllList) selectAllList.checked = allChecked;
}

function setInvSelectAll(checked) {
    var catalog = document.getElementById('invCatalog');
    var isList = catalog && catalog.classList.contains('inv-catalog--list');
    var seen = {};
    if (isList) {
        document.querySelectorAll('#invListBody .inv-list-row').forEach(function (row) {
            if (row.style.display === 'none') return;
            var cb = row.querySelector('.inv-product-check');
            if (!cb) return;
            cb.checked = !!checked;
            seen[cb.value] = true;
        });
    } else {
        document.querySelectorAll('.inv-products-grid .inv-product-check').forEach(function (cb) {
            cb.checked = !!checked;
            seen[cb.value] = true;
        });
    }
    // Sincronizar el otro modo de vista
    Object.keys(seen).forEach(function (id) {
        syncInvChecks(id, checked);
    });
    updateInvBulkState();
}

function syncStatusReasonVisibility() {
    var statusEl = document.getElementById('prodStatus');
    var group = document.getElementById('statusReasonGroup');
    if (!statusEl || !group) return;
    var original = String(statusEl.dataset.originalStatus || 'active');
    var current = String(statusEl.value || 'active');
    group.style.display = (original !== current) ? '' : 'none';
    if (original === current) {
        var reason = document.getElementById('prodStatusReason');
        if (reason) reason.value = '';
    }
}

function invDeleteWithReason(form) {
    if (!form) return false;
    var reason = window.prompt('Indique la razón de desactivación (mín. 5 caracteres). Quedará en Trazabilidad:');
    if (reason == null) return false;
    reason = String(reason).trim();
    if (reason.length < 5) {
        if (typeof showAlert === 'function') {
            showAlert('La razón debe tener al menos 5 caracteres', 'error');
        } else {
            alert('La razón debe tener al menos 5 caracteres');
        }
        return false;
    }
    var input = form.querySelector('input[name="status_change_reason"]');
    if (input) input.value = reason;
    return true;
}

function submitInvBulkDelete() {
    var ids = getInvSelectedIds();
    if (!ids.length) return;
    var reason = window.prompt('Indique la razón de desactivación de los productos seleccionados (mín. 5 caracteres). Quedará en Trazabilidad:');
    if (reason == null) return;
    reason = String(reason).trim();
    if (reason.length < 5) {
        if (typeof showAlert === 'function') {
            showAlert('La razón debe tener al menos 5 caracteres', 'error');
        } else {
            alert('La razón debe tener al menos 5 caracteres');
        }
        return;
    }
    var msg = ids.length === 1
        ? '¿Desactivar 1 producto seleccionado?'
        : '¿Desactivar ' + ids.length + ' productos seleccionados?';
    var run = function () {
        var form = document.getElementById('invBulkDeleteForm');
        var wrap = document.getElementById('invBulkIds');
        var reasonInput = document.getElementById('invBulkDeleteReason');
        if (!form || !wrap) return;
        if (reasonInput) reasonInput.value = reason;
        wrap.innerHTML = '';
        ids.forEach(function (id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ids[]';
            input.value = String(id);
            wrap.appendChild(input);
        });
        if (typeof handleAjaxSubmit === 'function') {
            handleAjaxSubmit(form);
        } else {
            form.submit();
        }
    };
    if (typeof showConfirmModal === 'function') {
        showConfirmModal(msg, run);
    } else if (window.confirm(msg)) {
        run();
    }
}

function initInvBulkSelection() {
    if (document.body._invBulkReady) return;
    document.body._invBulkReady = true;
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (!t) return;
        if (t.classList && t.classList.contains('inv-product-check')) {
            syncInvChecks(t.value, t.checked);
            updateInvBulkState();
            return;
        }
        if (t.id === 'invSelectAll' || t.id === 'invSelectAllList') {
            setInvSelectAll(t.checked);
        }
    });
    var btn = document.getElementById('invBulkDeleteBtn');
    if (btn) btn.addEventListener('click', submitInvBulkDelete);
    updateInvBulkState();
}

document.addEventListener('DOMContentLoaded', function () {
    initInvBulkSelection();
    initOfferModal();
    var statusEl = document.getElementById('prodStatus');
    if (statusEl) statusEl.addEventListener('change', syncStatusReasonVisibility);
    var productForm = document.getElementById('productForm');
    if (productForm) {
        productForm.addEventListener('submit', function (e) {
            var st = document.getElementById('prodStatus');
            var reasonGroup = document.getElementById('statusReasonGroup');
            if (!st || !reasonGroup || reasonGroup.style.display === 'none') return;
            var reason = document.getElementById('prodStatusReason');
            var text = reason ? String(reason.value || '').trim() : '';
            if (text.length < 5) {
                e.preventDefault();
                e.stopImmediatePropagation();
                if (typeof showAlert === 'function') {
                    showAlert('Indique la razón del cambio de estado (mín. 5 caracteres)', 'error');
                } else {
                    alert('Indique la razón del cambio de estado (mín. 5 caracteres)');
                }
                return false;
            }
        }, true);
    }
});

function fmtOfferMoney(n) {
    if (window.SeriMoney && typeof SeriMoney.format === 'function') {
        var c = window.invCurrency || {};
        return (c.symbol || '$') + ' ' + SeriMoney.format(n);
    }
    var c = window.invCurrency || {};
    var decimals = parseInt(c.decimals, 10);
    if (isNaN(decimals)) decimals = 0;
    var num = Number(n) || 0;
    var parts = num.toFixed(decimals).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, c.thousands || '.');
    return (c.symbol || '$') + ' ' + (decimals > 0 ? parts.join(c.decimal || ',') : parts[0]);
}

function moneyVal(elOrRaw) {
    var raw = (elOrRaw && elOrRaw.value !== undefined) ? elOrRaw.value : elOrRaw;
    if (window.SeriMoney && typeof SeriMoney.parse === 'function') {
        return SeriMoney.parse(raw);
    }
    return parseFloat(raw) || 0;
}

function openOfferModal(id) {
    var card = document.querySelector('.product-card[data-id="' + id + '"]');
    var d = card ? card.dataset : {};
    var original = parseFloat(d.offer === '1' && d.offerOriginal ? d.offerOriginal : (d.pventa || 0)) || 0;
    if (original <= 0) {
        if (typeof showAlert === 'function') showAlert('El producto necesita precio de venta mayor a 0', 'error');
        return;
    }
    document.getElementById('offerProductId').value = id;
    document.getElementById('offerProductLabel').textContent =
        (d.code ? d.code + ' · ' : '') + (d.name || ('Producto #' + id));
    document.getElementById('offerOriginalValue').value = String(original);
    document.getElementById('offerOriginalDisplay').value = fmtOfferMoney(original);
    document.getElementById('offerPercent').value = '';
    var priceEl = document.getElementById('offerPrice');
    var priceVal = d.offer === '1' && d.offerPrice ? d.offerPrice : '';
    priceEl.value = priceVal !== '' && window.SeriMoney ? SeriMoney.format(priceVal) : priceVal;
    document.getElementById('offerStartsAt').value = '';
    document.getElementById('offerDuration').value = '24h';
    document.getElementById('offerEndsAt').value = '';
    document.getElementById('offerEndsWrap').style.display = 'none';
    document.getElementById('offerReason').value = d.offerReason || '';
    document.getElementById('offerReasonNote').value = '';
    document.getElementById('offerReasonNoteWrap').style.display = 'none';
    document.querySelectorAll('.offer-pct-btn').forEach(function (b) { b.classList.remove('btn-primary'); b.classList.add('btn-secondary'); });
    updateOfferPreview();
    document.getElementById('offerModal').style.display = 'flex';
}

function updateOfferPreview() {
    var original = parseFloat(document.getElementById('offerOriginalValue').value) || 0;
    var price = moneyVal(document.getElementById('offerPrice'));
    var el = document.getElementById('offerPreview');
    if (!el) return;
    if (original > 0 && price > 0 && price < original) {
        var pct = ((original - price) / original) * 100;
        el.textContent = 'Descuento: ' + pct.toFixed(1) + '% · Ahorro: ' + fmtOfferMoney(original - price);
    } else {
        el.textContent = '';
    }
}

function applyOfferPercent(pct) {
    var original = parseFloat(document.getElementById('offerOriginalValue').value) || 0;
    pct = parseFloat(pct) || 0;
    document.getElementById('offerPercent').value = pct > 0 ? String(pct) : '';
    document.querySelectorAll('.offer-pct-btn').forEach(function (b) {
        var active = parseFloat(b.getAttribute('data-pct')) === pct;
        b.classList.toggle('btn-primary', active);
        b.classList.toggle('btn-secondary', !active);
    });
    if (pct > 0 && original > 0) {
        var price = Math.round(original * (1 - pct / 100) * 100) / 100;
        var priceEl = document.getElementById('offerPrice');
        priceEl.value = window.SeriMoney ? SeriMoney.format(price) : String(price);
    }
    updateOfferPreview();
}

function initOfferModal() {
    var btns = document.getElementById('offerPercentBtns');
    if (btns && !btns._bound) {
        btns.addEventListener('click', function (e) {
            var t = e.target.closest('.offer-pct-btn');
            if (!t) return;
            applyOfferPercent(t.getAttribute('data-pct'));
        });
        btns._bound = true;
    }
    var price = document.getElementById('offerPrice');
    if (price && !price._bound) {
        price.addEventListener('input', function () {
            document.getElementById('offerPercent').value = '';
            document.querySelectorAll('.offer-pct-btn').forEach(function (b) {
                var isManual = parseFloat(b.getAttribute('data-pct')) === 0;
                b.classList.toggle('btn-primary', isManual);
                b.classList.toggle('btn-secondary', !isManual);
            });
            updateOfferPreview();
        });
        price._bound = true;
    }
    var dur = document.getElementById('offerDuration');
    if (dur && !dur._bound) {
        dur.addEventListener('change', function () {
            document.getElementById('offerEndsWrap').style.display = dur.value === 'custom' ? '' : 'none';
        });
        dur._bound = true;
    }
    var reason = document.getElementById('offerReason');
    if (reason && !reason._bound) {
        reason.addEventListener('change', function () {
            document.getElementById('offerReasonNoteWrap').style.display = reason.value === 'otro' ? '' : 'none';
        });
        reason._bound = true;
    }
}

function cancelOffer(id) {
    if (!confirm('¿Cancelar la oferta y restaurar el precio original?')) return;
    var fd = new FormData();
    fd.append('product_id', String(id));
    if (window.invCsrf) {
        fd.append('_token', window.invCsrf);
        fd.append('csrf_token', window.invCsrf);
    }
    fetch(window.invRouteOfferCancel || (window.invRouteEdit || '').replace('action=edit', 'action=offer_cancel'), {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        credentials: 'same-origin'
    }).then(function (r) { return r.json(); }).then(function (d) {
        if (d && d.success) {
            if (typeof showAlert === 'function') showAlert(d.message || 'Oferta cancelada', 'success');
            setTimeout(function () { window.location.reload(); }, 600);
        } else if (typeof showAlert === 'function') {
            showAlert((d && d.message) || 'No se pudo cancelar', 'error');
        }
    }).catch(function () {
        if (typeof showAlert === 'function') showAlert('Error de red', 'error');
    });
}

/** Muestra justificación obligatoria si el stock de la ficha cambió. */
function onProdStockChange() {
    var stockEl = document.getElementById('prodStock');
    var origEl = document.getElementById('prodStockOriginal');
    var group = document.getElementById('stockAdjNoteGroup');
    var note = document.getElementById('prodStockAdjNote');
    var deltaHint = document.getElementById('prodStockDeltaHint');
    var negWrap = document.getElementById('prodNegConfirmWrap');
    var negCb = document.getElementById('prodNegConfirm');
    var negText = document.getElementById('prodNegConfirmText');
    if (!stockEl || !group) return;
    var cur = parseInt(stockEl.value || '0', 10) || 0;
    var orig = origEl ? (parseInt(origEl.value || '0', 10) || 0) : cur;
    var isEdit = !!(document.getElementById('prodId') && document.getElementById('prodId').value);
    var changed = isEdit && cur !== orig;
    group.style.display = changed ? '' : 'none';
    if (note) {
        if (changed) note.setAttribute('required', 'required');
        else {
            note.removeAttribute('required');
            note.value = '';
        }
    }
    if (deltaHint) {
        if (changed) {
            var delta = cur - orig;
            var sign = delta > 0 ? '+' : '';
            deltaHint.style.display = 'block';
            deltaHint.innerHTML = 'Se registrará movimiento <strong>' + sign + delta + '</strong> '
                + '(de <strong>' + orig + '</strong> a <strong>' + cur + '</strong>). '
                + 'Ponga aquí las unidades que <em>realmente hay</em> ahora.';
        } else {
            deltaHint.style.display = 'none';
            deltaHint.textContent = '';
        }
    }
    var crossingNeg = changed && orig < 0 && cur >= 0;
    if (negWrap) {
        if (crossingNeg) {
            negWrap.style.display = 'block';
            if (negText) {
                negText.innerHTML = 'Corrección desde stock negativo: <strong>' + orig + '</strong> → <strong>' + cur + '</strong>. '
                    + 'Use Editar cuando el negativo fue por <strong>descuadre o error</strong> '
                    + '(ej. se vendieron unidades que sí existían: tras la venta el conteo correcto puede ser 0). '
                    + 'Al guardar se cubre la deuda de sobreventa y <strong>desaparece la alerta</strong> en Caja. '
                    + 'No use «Lote N»/Compras si no llegó producto nuevo.';
            }
            if (negCb) {
                negCb.required = true;
                if (!changed) negCb.checked = false;
            }
        } else {
            negWrap.style.display = 'none';
            if (negCb) {
                negCb.required = false;
                negCb.checked = false;
            }
        }
    }
}