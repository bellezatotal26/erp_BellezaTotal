// Seri ERP - JavaScript Principal

document.addEventListener('DOMContentLoaded', function() {
    // Manejo de navegación activa
    const currentPath = window.location.pathname;
    const navLinks = document.querySelectorAll('.sidebar-nav a');
    
    navLinks.forEach(link => {
        if (link.getAttribute('href') === currentPath) {
            link.classList.add('active');
        }
    });
    
    // Confirmación para acciones destructivas (modal personalizado)
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-confirm]');
        if (!btn) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        var message = btn.dataset.confirm || 'Esta seguro?';
        showConfirmModal(message, function() {
            btn.removeAttribute('data-confirm');
            var form = btn.closest('form');
            if (form && btn.type === 'submit') {
                if (form.dataset.ajax === 'true' && typeof handleAjaxSubmit === 'function') {
                    handleAjaxSubmit(form);
                } else if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(btn);
                } else {
                    form.submit();
                }
            } else {
                btn.click();
            }
        });
    }, true);
    
    // AJAX Loading para formularios
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (e.defaultPrevented) {
                return;
            }
            
            if (form.dataset.ajax === 'true') {
                e.preventDefault();
                handleAjaxSubmit(form);
                return;
            }
            
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.classList.add('btn-loading');
                form.classList.add('form-loading');
            }
        });
    });
});

// Función para mostrar overlay de carga
function showLoadingOverlay() {
    const overlay = document.createElement('div');
    overlay.className = 'loading-overlay';
    overlay.innerHTML = '<div class="loading-spinner"></div>';
    document.body.appendChild(overlay);
    return overlay;
}

// Función para ocultar overlay de carga
function hideLoadingOverlay(overlay) {
    if (overlay) {
        overlay.remove();
    }
}

// Función para enviar formularios con AJAX
function submitFormWithAjax(form, callback) {
    const formData = new FormData(form);
    const overlay = showLoadingOverlay();
    
    fetch(form.action, {
        method: form.method || 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        hideLoadingOverlay(overlay);
        if (callback) callback(data);
    })
    .catch(error => {
        hideLoadingOverlay(overlay);
        console.error('Error:', error);
    });
}

/** ¿Es el producto comodín (nombre/precio libres)? */
function isOpenItemProduct(p) {
    if (!p) return false;
    if (p.is_open_item === 1 || p.is_open_item === true || p.is_open_item === '1') return true;
    var code = String(p.code || '').toUpperCase().trim();
    return code === 'COMODIN';
}

/**
 * Modal para nombre + precio del producto comodín.
 * @param {function(string,number):void} onOk
 */
function requestOpenItemDetails(onOk) {
    var modal = document.getElementById('openItemModal');
    var nameEl = document.getElementById('openItemName');
    var priceEl = document.getElementById('openItemPrice');
    var btn = document.getElementById('openItemConfirmBtn');
    if (!modal || !nameEl || !priceEl || !btn) {
        var name = window.prompt('Nombre del producto', '');
        if (name === null) return;
        name = String(name).trim();
        if (!name) {
            if (typeof showAlert === 'function') showAlert('Indique el nombre del producto', 'error');
            return;
        }
        var priceStr = window.prompt('Precio de venta', '');
        if (priceStr === null) return;
        var price = parseFloat(String(priceStr).replace(',', '.'));
        if (isNaN(price) || price <= 0) {
            if (typeof showAlert === 'function') showAlert('Indique un precio válido', 'error');
            return;
        }
        onOk(name, Math.round(price * 100) / 100);
        return;
    }
    nameEl.value = '';
    priceEl.value = '';
    modal.style.display = 'flex';
    setTimeout(function () { nameEl.focus(); }, 50);

    function cleanup() {
        btn.onclick = null;
        nameEl.onkeydown = null;
        priceEl.onkeydown = null;
    }
    function confirm() {
        var name = String(nameEl.value || '').trim();
        var price = parseFloat(String(priceEl.value || '').replace(',', '.'));
        if (!name) {
            if (typeof showAlert === 'function') showAlert('Indique el nombre del producto', 'error');
            nameEl.focus();
            return;
        }
        if (isNaN(price) || price <= 0) {
            if (typeof showAlert === 'function') showAlert('Indique un precio válido mayor a 0', 'error');
            priceEl.focus();
            return;
        }
        cleanup();
        modal.style.display = 'none';
        onOk(name, Math.round(price * 100) / 100);
    }
    btn.onclick = confirm;
    nameEl.onkeydown = function (e) {
        if (e.key === 'Enter') { e.preventDefault(); priceEl.focus(); }
    };
    priceEl.onkeydown = function (e) {
        if (e.key === 'Enter') { e.preventDefault(); confirm(); }
    };
}

/**
 * Tras un AJAX ok: navega a redirect o recarga.
 * Si el destino es la misma página (solo cambia o repite el hash), recarga sí o sí.
 */
function navigateAfterAjaxSuccess(redirectUrl) {
    if (!redirectUrl) {
        window.location.reload();
        return;
    }
    try {
        var next = new URL(redirectUrl, window.location.href);
        var cur = window.location;
        var samePath = next.pathname === cur.pathname && next.search === cur.search;
        if (samePath) {
            if (next.hash && next.hash !== cur.hash) {
                try {
                    history.replaceState(null, '', next.pathname + next.search + next.hash);
                } catch (e) {
                    window.location.hash = next.hash.replace(/^#/, '');
                }
            }
            window.location.reload();
            return;
        }
        window.location.href = next.href;
    } catch (e) {
        window.location.href = redirectUrl;
    }
}

// Maneja el envio de formularios marcados con data-ajax="true"
function handleAjaxSubmit(form) {
    if (form.dataset.submitting === '1') {
        return;
    }
    
    // Hooks de preparacion (ventas/cotizaciones): deben retornar false para cancelar
    if (typeof form.onsubmit === 'function') {
        // algunos formularios usan onsubmit HTML attribute
    }
    var prepareFn = form.getAttribute('onsubmit');
    if (prepareFn && prepareFn.indexOf('prepareSaleItems') !== -1) {
        if (typeof prepareSaleItems === 'function' && prepareSaleItems() === false) {
            return;
        }
    }
    if (prepareFn && prepareFn.indexOf('prepareQuoteItems') !== -1) {
        if (typeof prepareQuoteItems === 'function' && prepareQuoteItems() === false) {
            return;
        }
    }
    if (form.dataset.prepare === 'saleItems' && typeof prepareSaleItems === 'function') {
        if (prepareSaleItems() === false) return;
    }
    if (form.id === 'cancelSaleForm' && typeof prepareCancelSale === 'function') {
        if (prepareCancelSale() === false) return;
    }
    if (form.dataset.prepare === 'quoteItems' && typeof prepareQuoteItems === 'function') {
        if (prepareQuoteItems() === false) return;
    }
    if (form.dataset.prepare === 'purchaseItems' && typeof preparePurchaseItems === 'function') {
        if (preparePurchaseItems() === false) return;
    }
    
    const submitBtn = form.querySelector('button[type="submit"]');
    const overlay = showLoadingOverlay();
    form.dataset.submitting = '1';
    
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.classList.add('btn-loading');
    }
    
    fetch(form.action, {
        method: form.method || 'POST',
        body: (function () {
            if (window.SeriMoney && typeof SeriMoney.normalizeForm === 'function') {
                SeriMoney.normalizeForm(form);
            }
            return new FormData(form);
        })(),
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        var ct = (response.headers.get('content-type') || '').toLowerCase();
        if (!response.ok) {
            return response.text().then(function(text) {
                var data = null;
                try { data = JSON.parse(text); } catch (e) { data = null; }
                var msg = (data && data.message) ? data.message : ('Error del servidor (HTTP ' + response.status + ')');
                throw Object.assign(new Error(msg), { payload: data || { message: msg } });
            });
        }
        if (ct.indexOf('application/json') === -1) {
            return response.text().then(function(text) {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error('El servidor no devolvio JSON. Revise que Controller.php este actualizado en el servidor.');
                }
            });
        }
        return response.json();
    })
    .then(data => {
        hideLoadingOverlay(overlay);
        form.dataset.submitting = '0';
        
        if (data.success) {
            showAlert(data.message, 'success');
            if (form) form.classList.add('ajax-success-flash');
            if (data.pdf_url) {
                try {
                    var frame = document.getElementById('softnovaPdfFrame');
                    if (!frame) {
                        frame = document.createElement('iframe');
                        frame.id = 'softnovaPdfFrame';
                        frame.style.display = 'none';
                        frame.setAttribute('aria-hidden', 'true');
                        document.body.appendChild(frame);
                    }
                    frame.src = data.pdf_url;
                    // Respaldo por si el iframe es bloqueado
                    setTimeout(function() {
                        var a = document.createElement('a');
                        a.href = data.pdf_url;
                        a.target = '_blank';
                        a.rel = 'noopener';
                        a.style.display = 'none';
                        document.body.appendChild(a);
                        a.click();
                        a.remove();
                    }, 400);
                } catch (e) {
                    // Abrir PDF en pestaña nueva; no abortar redirect (ej. share_close de caja).
                    try {
                        window.open(data.pdf_url, '_blank', 'noopener');
                    } catch (e2) {}
                }
            }
            // Misma ruta + #hash (ej. /app/configuracion#usuarios) no navega en el navegador
            // y deja el botón en btn-loading para siempre. Forzar recarga en ese caso.
            setTimeout(function() {
                navigateAfterAjaxSuccess(data.redirect || '');
            }, data.pdf_url ? 1200 : 700);
        } else {
            showAlert(data.message || 'No se pudo completar la accion', 'error');
            if (data.suggest_add_lot && data.existing_product && typeof window.openAddLotForProduct === 'function') {
                var ep = data.existing_product;
                var ask = 'Este SKU/EAN ya existe: «' + (ep.name || '') + '».\n\n'
                    + 'Mismo producto con otro vencimiento = Lote N (nueva remesa), no un producto nuevo.\n\n'
                    + '¿Abrir ingreso de Lote N ahora?';
                if (window.confirm(ask)) {
                    if (typeof window.closeInvModal === 'function') {
                        window.closeInvModal();
                    }
                    var pre = Object.assign({}, data.lot_prefill || {}, {
                        name: ep.name || '',
                        force_lots: true
                    });
                    window.openAddLotForProduct(ep.id, pre);
                }
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.classList.remove('btn-loading');
            }
        }
    })
    .catch(error => {
        hideLoadingOverlay(overlay);
        form.dataset.submitting = '0';
        console.error('Error:', error);
        var msg = (error && error.payload && error.payload.message)
            ? error.payload.message
            : (error && error.message ? error.message : 'Error de conexion con el servidor');
        if (msg.indexOf('JSON') !== -1 || msg.indexOf('Unexpected') !== -1) {
            msg = 'Error de conexion con el servidor';
        }
        showAlert(msg, 'error');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('btn-loading');
        }
    });
}

// ============================================
// Búsqueda global en vivo
// ============================================
(function() {
    const searchInput = document.getElementById('globalSearchInput');
    const searchResults = document.getElementById('searchResults');
    const searchClear = document.getElementById('searchClear');
    let debounceTimer;
    let searchAbort = null;
    let searchSeq = 0;
    
    if (!searchInput) return;
    
    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const query = this.value.trim();
        
        // Mostrar/ocultar botón clear
        if (searchClear) {
            searchClear.style.display = query ? 'flex' : 'none';
        }
        
        if (query.length < 2) {
            if (searchAbort) searchAbort.abort();
            searchResults.style.display = 'none';
            searchResults.innerHTML = '';
            return;
        }
        
        debounceTimer = setTimeout(() => {
            fetchSearchResults(query);
        }, 300);
    });
    
    searchInput.addEventListener('focus', function() {
        if (searchResults.children.length > 0) {
            searchResults.style.display = 'block';
        }
    });
    
    // Cerrar resultados al hacer clic fuera
    document.addEventListener('click', function(e) {
        const searchContainer = document.getElementById('globalSearch');
        if (searchContainer && !searchContainer.contains(e.target)) {
            searchResults.style.display = 'none';
        }
    });
    
    // Navegar con teclado
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            searchResults.style.display = 'none';
            this.blur();
        }
    });
    
    function fetchSearchResults(query) {
        const url = searchInput.dataset.searchUrl + '?q=' + encodeURIComponent(query);
        const seq = ++searchSeq;
        if (searchAbort) searchAbort.abort();
        searchAbort = new AbortController();
        
        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: searchAbort.signal
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(function(data) {
            if (seq !== searchSeq) return;
            
            if (data.error) {
                searchResults.innerHTML = '<div class="search-result-empty">Error: ' + escapeHtml(data.error) + '</div>';
                searchResults.style.display = 'block';
                return;
            }
            
            if (!data.results || data.results.length === 0) {
                searchResults.innerHTML = '<div class="search-result-empty">Sin resultados para "' + escapeHtml(query) + '"</div>';
                searchResults.style.display = 'block';
                return;
            }
            
            let html = '';
            data.results.forEach(function(item) {
                const typeIcon = item.type === 'producto' ? '📦'
                    : (item.type === 'cliente' ? '👤'
                    : (item.type === 'venta' ? '🧾'
                    : (item.type === 'ticket' ? '💬' : '🔍')));
                html += '<a href="' + item.url + '" class="search-result-item">';
                html += '<span class="search-result-icon">' + typeIcon + '</span>';
                html += '<div class="search-result-info">';
                html += '<span class="search-result-title">' + escapeHtml(item.title) + '</span>';
                html += '<span class="search-result-subtitle">' + escapeHtml(item.subtitle || '') + '</span>';
                html += '</div>';
                html += '<span class="badge ' + (item.badgeClass || 'badge-info') + '">' + escapeHtml(item.badge || item.type) + '</span>';
                html += '</a>';
            });
            
            searchResults.innerHTML = html;
            searchResults.style.display = 'block';
        })
        .catch(function(err) {
            if (err && err.name === 'AbortError') return;
            if (seq !== searchSeq) return;
            searchResults.innerHTML = '<div class="search-result-empty">Error de conexión. Verifique la consola.</div>';
            searchResults.style.display = 'block';
        });
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
})();

function clearSearch() {
    const input = document.getElementById('globalSearchInput');
    const results = document.getElementById('searchResults');
    const clearBtn = document.getElementById('searchClear');
    if (input) input.value = '';
    if (results) { results.style.display = 'none'; results.innerHTML = ''; }
    if (clearBtn) clearBtn.style.display = 'none';
    if (input) input.focus();
}

// Muestra una alerta tipo toast (notificación animada)
function showAlert(message, type) {
    var container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    var toast = document.createElement('div');
    toast.className = 'toast toast-' + (type || 'info') + (type === 'success' ? ' toast-bounce' : '');
    var labels = { success: 'OK', error: 'Error', warning: 'Aviso', info: 'Info' };
    toast.innerHTML = '<strong style="opacity:0.9;">' + (labels[type] || 'Info') + '</strong><span>' + esc(message || '') + '</span>';
    container.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 5000);
}

/**
 * Intenta compartir un PDF con la app nativa (móvil → WhatsApp app).
 * En escritorio cae a wa.me (abre WhatsApp Web/Desktop): el navegador
 * no puede enviar archivos a WhatsApp en silencio sin API Business.
 *
 * @param {{pdfUrl:string, text?:string, waLink?:string, title?:string, filename?:string}} opts
 * @returns {Promise<boolean>}
 */
function sharePdfToWhatsApp(opts) {
    opts = opts || {};
    var pdfUrl = opts.pdfUrl || '';
    var waLink = opts.waLink || '';
    var text = opts.text || '';
    var title = opts.title || 'Documento';
    var filename = opts.filename || 'documento.pdf';

    function openWaFallback() {
        if (waLink) {
            window.open(waLink, '_blank', 'noopener');
            return true;
        }
        if (text) {
            window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank', 'noopener');
            return true;
        }
        if (pdfUrl) {
            window.open(pdfUrl, '_blank', 'noopener');
            return true;
        }
        return false;
    }

    if (!pdfUrl || !navigator.share || !navigator.canShare) {
        return Promise.resolve(openWaFallback());
    }

    return fetch(pdfUrl, { credentials: 'same-origin' })
        .then(function(r) {
            if (!r.ok) throw new Error('PDF no disponible');
            return r.blob();
        })
        .then(function(blob) {
            var file = new File([blob], filename, { type: 'application/pdf' });
            var payload = { title: title, text: text || title, files: [file] };
            if (!navigator.canShare(payload)) {
                openWaFallback();
                return false;
            }
            return navigator.share(payload).then(function() { return true; });
        })
        .catch(function() {
            openWaFallback();
            return false;
        });
}

function showConfirmModal(message, callback) {
    var overlay = document.createElement('div');
    overlay.className = 'modal-overlay confirm-modal';
    overlay.style.display = 'flex';
    overlay.innerHTML = '<div class="modal-content neumorphic"><h3>Confirmar</h3><p>' + esc(message || '') + '</p><div class="confirm-actions"><button class="btn btn-secondary" id="confirmCancel">Cancelar</button><button class="btn btn-danger" id="confirmOk">Confirmar</button></div></div>';
    document.body.appendChild(overlay);
    document.getElementById('confirmCancel').onclick = function() { overlay.remove(); };
    document.getElementById('confirmOk').onclick = function() { overlay.remove(); if(callback)callback(); };
}

// Utilidad global: escapar HTML para prevenir XSS
function esc(s) {
    return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Animacion de producto hacia el carrito / badge
 */
function animateAddToCart(fromEl) {
    var badge = document.getElementById('cartBadge');
    var target = badge || document.querySelector('[onclick*="showCartModal"]') || document.querySelector('.header-right');
    if (!fromEl || !target) {
        if (badge) {
            badge.classList.remove('cart-badge-bounce');
            void badge.offsetWidth;
            badge.classList.add('cart-badge-bounce');
        }
        return;
    }

    var start = fromEl.getBoundingClientRect();
    var end = target.getBoundingClientRect();
    var flyer = document.createElement('div');
    flyer.className = 'cart-fly-item';
    flyer.textContent = '+1';
    flyer.style.left = (start.left + start.width / 2 - 18) + 'px';
    flyer.style.top = (start.top + start.height / 2 - 18) + 'px';
    flyer.style.setProperty('--fly-x', ((end.left + end.width / 2) - (start.left + start.width / 2)) + 'px');
    flyer.style.setProperty('--fly-y', ((end.top + end.height / 2) - (start.top + start.height / 2)) + 'px');
    document.body.appendChild(flyer);
    setTimeout(function() { flyer.remove(); }, 700);

    if (badge) {
        badge.classList.remove('cart-badge-bounce');
        void badge.offsetWidth;
        badge.classList.add('cart-badge-bounce');
    }
}

/**
 * Sacudir campana de notificaciones
 */
function ringNotificationBell() {
    var bell = document.getElementById('notificationBellBtn');
    if (!bell) return;
    bell.classList.remove('bell-ring');
    void bell.offsetWidth;
    bell.classList.add('bell-ring');
}

// Filtro de clientes en dropdowns con buscador (legado)
function filterCustomers(selectId, searchId) {
    var search = document.getElementById(searchId);
    var select = document.getElementById(selectId);
    if (!search || !select) return;
    
    var query = search.value.toLowerCase().trim();
    var options = select.options;
    
    for (var i = 0; i < options.length; i++) {
        if (i === 0 && options[i].value === '') {
            options[i].style.display = '';
            continue;
        }
        var searchData = options[i].getAttribute('data-search') || '';
        if (query === '' || searchData.indexOf(query) !== -1) {
            options[i].style.display = '';
        } else {
            options[i].style.display = 'none';
        }
    }
}

/** Combobox unificado cliente (input + lista) */
function initCustomerCombobox() {
    var wrap = document.getElementById('customerCombobox');
    var input = document.getElementById('customerSearch');
    var hidden = document.getElementById('customerId');
    var list = document.getElementById('customerList');
    if (!wrap || !input || !hidden || !list || wrap.dataset.ready === '1') return;
    wrap.dataset.ready = '1';

    function openList() {
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }
    function closeList() {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
    }
    function filterList() {
        var q = input.value.toLowerCase().trim();
        var options = list.querySelectorAll('.combobox-option');
        var visible = 0;
        options.forEach(function(li) {
            var match = !q || (li.getAttribute('data-search') || '').indexOf(q) !== -1;
            li.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        if (visible > 0) openList(); else closeList();
    }
    function selectOption(li) {
        hidden.value = li.getAttribute('data-id') || '';
        input.value = li.getAttribute('data-label') || li.textContent.trim();
        closeList();
    }

    input.addEventListener('focus', function() {
        filterList();
        openList();
    });
    input.addEventListener('input', function() {
        hidden.value = '';
        filterList();
    });
    list.addEventListener('mousedown', function(e) {
        var li = e.target.closest('.combobox-option');
        if (!li) return;
        e.preventDefault();
        selectOption(li);
    });
    document.addEventListener('click', function(e) {
        if (!wrap.contains(e.target)) closeList();
    });
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeList();
        if (e.key === 'Enter') {
            var first = list.querySelector('.combobox-option:not([style*="display: none"])');
            if (first && !list.hidden) {
                e.preventDefault();
                selectOption(first);
            }
        }
    });
}

function setSelectedCustomer(id, label) {
    var hidden = document.getElementById('customerId');
    var input = document.getElementById('customerSearch');
    var list = document.getElementById('customerList');
    if (hidden) hidden.value = id || '';
    if (input) input.value = label || 'Consumidor Final';
    if (list && id) {
        var exists = list.querySelector('.combobox-option[data-id="' + id + '"]');
        if (!exists) {
            var li = document.createElement('li');
            li.className = 'combobox-option';
            li.setAttribute('data-id', id);
            li.setAttribute('data-label', label);
            li.setAttribute('data-search', (label || '').toLowerCase());
            li.textContent = label;
            list.appendChild(li);
        }
    }
}

function resetCustomerCombobox() {
    setSelectedCustomer('', 'Consumidor Final');
    var list = document.getElementById('customerList');
    if (list) list.hidden = true;
}

/**
 * Ojito para mostrar/ocultar contraseña en todos los inputs type=password
 */
function initPasswordToggles(root) {
    var scope = root || document;
    var inputs = scope.querySelectorAll('input[type="password"]:not([data-password-toggle="ready"])');
    if (!inputs.length) return;

    var eyeOpen = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
    var eyeOff = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';

    inputs.forEach(function(input) {
        if (input.closest('.password-field')) {
            input.setAttribute('data-password-toggle', 'ready');
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'password-field';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'password-toggle-btn';
        btn.setAttribute('aria-label', 'Mostrar contraseña');
        btn.setAttribute('title', 'Mostrar contraseña');
        btn.innerHTML = eyeOpen;
        wrap.appendChild(btn);

        input.setAttribute('data-password-toggle', 'ready');

        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.innerHTML = show ? eyeOff : eyeOpen;
            btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
            btn.setAttribute('title', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
            btn.classList.toggle('is-visible', show);
            input.focus();
        });
    });
}

document.addEventListener('DOMContentLoaded', function() {
    initPasswordToggles();
    if (typeof initCustomerCombobox === 'function') {
        initCustomerCombobox();
    }
    if (window.SoftNovaCustomSelect) {
        SoftNovaCustomSelect.bindAll();
    }
});

/**
 * Desplegables con opción "Otro": pide valor, lo guarda permanente y lo selecciona.
 * Uso: <select data-custom-other="payment_method">…<option value="__other__">Otro</option>
 * Grupos: payment_method | customer_source | sale_cancel_reason | discount_percent | product_location | expense_category
 */
window.SoftNovaCustomSelect = {
    otherValues: { __other__: true, other: true, Otro: true },

    isOther: function(v) {
        return !!this.otherValues[String(v || '')];
    },

    csrf: function() {
        return (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    },

    url: function() {
        return (document.querySelector('meta[name="custom-option-url"]') || {}).content || '';
    },

    promptLabel: function(group) {
        if (group === 'discount_percent') return 'Ingrese el porcentaje de descuento (ej. 12):';
        if (group === 'payment_method') return 'Nombre del nuevo medio de pago:';
        if (group === 'customer_source') return '¿De dónde viene el cliente? Escriba el origen:';
        if (group === 'sale_cancel_reason') return 'Escriba el motivo de eliminación:';
        if (group === 'product_location') return 'Nombre de la nueva ubicación (bodega, local, etc.):';
        if (group === 'expense_category') return 'Nombre del nuevo tipo de gasto (se guardará de forma permanente):';
        return 'Escriba la nueva opción:';
    },

    save: function(group, label) {
        var self = this;
        var endpoint = this.url();
        if (!endpoint) {
            return Promise.reject(new Error('URL de opciones no configurada'));
        }
        var fd = new FormData();
        fd.append('csrf_token', this.csrf());
        fd.append('group', group);
        fd.append('label', label);
        return fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.success || !data.option) {
                    throw new Error((data && data.message) || 'No se pudo guardar la opción');
                }
                return data.option;
            });
    },

    upsertOption: function(select, value, label) {
        var found = null;
        Array.prototype.forEach.call(select.options, function(opt) {
            if (opt.value === value) found = opt;
        });
        if (found) {
            found.textContent = label;
            found.selected = true;
            return;
        }
        var otherOpt = null;
        Array.prototype.forEach.call(select.options, function(opt) {
            if (SoftNovaCustomSelect.isOther(opt.value)) otherOpt = opt;
        });
        var opt = document.createElement('option');
        opt.value = value;
        opt.textContent = label;
        var group = select.getAttribute('data-custom-other') || '';
        var adminGroup = null;
        if (group === 'expense_category') {
            Array.prototype.forEach.call(select.getElementsByTagName('optgroup'), function(og) {
                var lbl = String(og.label || '').toLowerCase();
                if (lbl.indexOf('administrativ') !== -1) adminGroup = og;
            });
        }
        if (adminGroup) {
            adminGroup.appendChild(opt);
        } else if (otherOpt) {
            select.insertBefore(opt, otherOpt);
        } else {
            select.appendChild(opt);
        }
        select.value = value;
    },

    handleChange: function(select, prevValue) {
        var group = select.getAttribute('data-custom-other');
        if (!group || !this.isOther(select.value)) {
            return;
        }
        var self = this;
        var label = window.prompt(this.promptLabel(group), '');
        if (label === null) {
            select.value = prevValue || '';
            return;
        }
        label = String(label).trim();
        if (!label) {
            select.value = prevValue || '';
            if (typeof showAlert === 'function') showAlert('Debe escribir una opción', 'warning');
            return;
        }
        // No deshabilitar el <select>: FormData omite disabled y el gasto podría
        // guardarse sin category_id si el usuario envía el form durante el fetch.
        select.dataset.customSaving = '1';
        select.setAttribute('aria-busy', 'true');
        this.save(group, label).then(function(opt) {
            self.upsertOption(select, opt.value, opt.label);
            delete select.dataset.customSaving;
            select.removeAttribute('aria-busy');
            select.dispatchEvent(new Event('change', { bubbles: true }));
            if (typeof showAlert === 'function') {
                showAlert(opt.created === false ? 'Opción seleccionada' : 'Opción añadida permanentemente', 'success');
            }
        }).catch(function(err) {
            select.value = prevValue || '';
            delete select.dataset.customSaving;
            select.removeAttribute('aria-busy');
            if (typeof showAlert === 'function') showAlert(err.message || 'Error', 'error');
        });
    },

    bind: function(select) {
        if (!select || select.dataset.customOtherBound === '1') return;
        select.dataset.customOtherBound = '1';
        var prev = select.value;
        select.addEventListener('focus', function() {
            prev = select.value;
        });
        select.addEventListener('change', function() {
            SoftNovaCustomSelect.handleChange(select, prev);
            if (!SoftNovaCustomSelect.isOther(select.value)) {
                prev = select.value;
            }
        });
        var form = select.closest('form');
        if (form && form.dataset.customOtherSubmitBound !== '1') {
            form.dataset.customOtherSubmitBound = '1';
            form.addEventListener('submit', function(e) {
                var pending = form.querySelector('select[data-custom-other][data-custom-saving="1"]');
                var otherSel = null;
                form.querySelectorAll('select[data-custom-other]').forEach(function(sel) {
                    if (SoftNovaCustomSelect.isOther(sel.value)) otherSel = sel;
                });
                if (pending || otherSel) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    if (typeof showAlert === 'function') {
                        showAlert(pending
                            ? 'Espere a que se guarde la nueva opción'
                            : 'Seleccione una opción válida (termine de agregar "Otro")', 'warning');
                    }
                    return false;
                }
            }, true);
        }
    },

    bindAll: function(root) {
        var scope = root || document;
        scope.querySelectorAll('select[data-custom-other]').forEach(function(sel) {
            SoftNovaCustomSelect.bind(sel);
        });
    }
};
