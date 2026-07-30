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
        body: new FormData(form),
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) {
            return response.json().catch(function() {
                throw new Error('Error en la respuesta del servidor');
            }).then(function(data) {
                throw Object.assign(new Error(data.message || 'Error'), { payload: data });
            });
        }
        return response.json().catch(function() {
            throw new Error('Respuesta invalida del servidor');
        });
    })
    .then(data => {
        hideLoadingOverlay(overlay);
        form.dataset.submitting = '0';
        
        if (data.success) {
            showAlert(data.message, 'success');
            if (form) form.classList.add('ajax-success-flash');
            if (data.redirect) {
                setTimeout(() => window.location.href = data.redirect, 700);
            } else {
                setTimeout(() => window.location.reload(), 700);
            }
        } else {
            showAlert(data.message || 'No se pudo completar la accion', 'error');
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
});
