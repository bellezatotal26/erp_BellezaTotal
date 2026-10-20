// Seri ERP - Burbuja flotante del Asistente IA (mini-chat sobre cualquier página)
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="ai-widget-url"]');
    if (!meta || !meta.content) return;
    if (document.body.classList.contains('ai-page') || document.querySelector('.ai-page-root')) return;

    var base = meta.content.replace(/\/+$/, '');
    var path = location.pathname;
    var STORE_KEY = 'seriAiWidgetConv';
    var OPEN_KEY = 'seriAiWidgetOpen';

    var state = {
        convId: 0,
        busy: false,
        loaded: false,
        infoLoaded: false,
        name: 'Seri',
        welcome: 'Hola, soy Seri. ¿En qué te ayudo?',
        suggestions: []
    };
    try { state.convId = parseInt(sessionStorage.getItem(STORE_KEY) || '0', 10) || 0; } catch (e) {}

    var root = document.createElement('div');
    root.className = 'ai-widget';
    if (/\/app\/caja(\/|$)/.test(path)) root.classList.add('ai-widget--compact');
    if (/\/app\/inventario(\/|$)/.test(path)) root.classList.add('ai-widget--raised');

    root.innerHTML =
        '<div class="ai-widget-panel" role="dialog" aria-label="Asistente IA" hidden>' +
            '<div class="ai-widget-head">' +
                '<div class="ai-widget-title"><span class="ai-widget-dot"></span><span data-aiw="name">Seri</span></div>' +
                '<div class="ai-widget-actions">' +
                    '<button type="button" data-aiw="new" title="Nueva conversación">+</button>' +
                    '<a data-aiw="full" title="Abrir el asistente completo">&#x2197;</a>' +
                    '<button type="button" data-aiw="close" title="Cerrar">&times;</button>' +
                '</div>' +
            '</div>' +
            '<div class="ai-widget-msgs" data-aiw="msgs"></div>' +
            '<div class="ai-widget-sugs" data-aiw="sugs"></div>' +
            '<form class="ai-widget-input" data-aiw="form">' +
                '<input type="text" class="form-control" data-aiw="input" data-barcode-input="true" maxlength="2000" placeholder="Escribe tu pregunta..." autocomplete="off">' +
                '<button type="submit" class="btn btn-primary" data-aiw="send">Enviar</button>' +
            '</form>' +
        '</div>' +
        '<button type="button" class="ai-widget-fab" data-aiw="fab" title="Asistente IA" aria-label="Abrir asistente IA">' +
            '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>' +
                '<path d="M12 7l1.2 2.4L16 10l-2 1.8.5 2.7L12 13.3 9.5 14.5l.5-2.7L8 10l2.8-.6z"></path>' +
            '</svg>' +
        '</button>';
    document.body.appendChild(root);

    var $ = function (k) { return root.querySelector('[data-aiw="' + k + '"]'); };
    var panel = root.querySelector('.ai-widget-panel');
    var msgs = $('msgs');
    var sugs = $('sugs');
    var input = $('input');
    var sendBtn = $('send');
    $('full').href = base;

    function csrf() {
        return (window.SeriCsrf && typeof SeriCsrf.get === 'function') ? SeriCsrf.get() : '';
    }

    function nowTime() {
        return new Date().toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' });
    }

    function scrollBottom() { msgs.scrollTop = msgs.scrollHeight; }

    function bubble(role, text) {
        var wrap = document.createElement('div');
        wrap.className = 'ai-widget-msg ' + role;
        var b = document.createElement('div');
        b.className = 'ai-widget-bubble';
        b.appendChild(document.createTextNode(text || ''));
        var t = document.createElement('div');
        t.className = 'ai-widget-time';
        t.textContent = nowTime();
        b.appendChild(t);
        wrap.appendChild(b);
        msgs.appendChild(wrap);
        scrollBottom();
        return wrap;
    }

    function typing() {
        var el = document.createElement('div');
        el.className = 'ai-widget-typing';
        el.innerHTML = '<span></span><span></span><span></span>';
        msgs.appendChild(el);
        scrollBottom();
        return el;
    }

    function setBusy(busy) {
        state.busy = !!busy;
        input.disabled = state.busy;
        sendBtn.disabled = state.busy;
        sugs.querySelectorAll('button').forEach(function (b) { b.disabled = state.busy; });
    }

    function renderSuggestions() {
        sugs.innerHTML = '';
        state.suggestions.slice(0, 4).forEach(function (s) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = s;
            b.addEventListener('click', function () {
                if (state.busy) return;
                input.value = s;
                send();
            });
            sugs.appendChild(b);
        });
        sugs.hidden = msgs.querySelectorAll('.ai-widget-msg.user').length > 0 || state.suggestions.length === 0;
    }

    function resetToWelcome() {
        msgs.innerHTML = '';
        bubble('assistant', state.welcome);
        renderSuggestions();
    }

    function getJson(url) {
        return fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    function loadInfo() {
        if (state.infoLoaded) return Promise.resolve();
        return getJson(base + '/history?action=widget').then(function (d) {
            state.infoLoaded = true;
            if (d && d.success) {
                state.name = d.name || state.name;
                state.welcome = d.welcome || state.welcome;
                state.suggestions = Array.isArray(d.suggestions) ? d.suggestions : [];
                $('name').textContent = state.name;
            }
        }).catch(function () { state.infoLoaded = true; });
    }

    function loadConversation() {
        if (!state.convId) {
            resetToWelcome();
            return Promise.resolve();
        }
        return getJson(base + '/history?action=load&id=' + state.convId).then(function (d) {
            if (!d || !d.success || !(d.messages || []).length) {
                setConv(0);
                resetToWelcome();
                return;
            }
            msgs.innerHTML = '';
            d.messages.forEach(function (m) {
                bubble(m.role === 'assistant' ? 'assistant' : 'user', m.content);
            });
            renderSuggestions();
        }).catch(function () { resetToWelcome(); });
    }

    function setConv(id) {
        state.convId = id || 0;
        try {
            if (state.convId) sessionStorage.setItem(STORE_KEY, String(state.convId));
            else sessionStorage.removeItem(STORE_KEY);
        } catch (e) {}
    }

    function postChat(msg, retried) {
        var body = 'message=' + encodeURIComponent(msg)
            + '&conversation_id=' + state.convId
            + '&csrf_token=' + encodeURIComponent(csrf());
        return fetch(base + '/chat', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        }).then(function (d) {
            if (d && d.csrf_token && window.SeriCsrf) SeriCsrf.refreshFromPayload(d);
            if (d && d.code === 'csrf' && !retried) return postChat(msg, true);
            return d;
        });
    }

    function send() {
        if (state.busy) return;
        var msg = (input.value || '').trim();
        if (!msg) return;
        if (!navigator.onLine) {
            bubble('assistant', 'Sin conexión a internet. El asistente estará disponible cuando vuelva la conexión.');
            return;
        }
        bubble('user', msg);
        sugs.hidden = true;
        input.value = '';
        setBusy(true);
        var t = typing();
        postChat(msg, false).then(function (d) {
            t.remove();
            bubble('assistant', (d && (d.reply || d.message)) || 'No entendí. ¿Podrías reformular tu pregunta?');
            if (d && d.conversation_id) setConv(parseInt(d.conversation_id, 10));
        }).catch(function () {
            t.remove();
            bubble('assistant', 'Error de conexión. Intenta de nuevo.');
        }).finally(function () {
            setBusy(false);
            input.focus();
        });
    }

    function open() {
        panel.hidden = false;
        root.classList.add('is-open');
        try { sessionStorage.setItem(OPEN_KEY, '1'); } catch (e) {}
        if (!state.loaded) {
            state.loaded = true;
            msgs.innerHTML = '';
            var t = typing();
            loadInfo().then(loadConversation).finally(function () { t.remove(); });
        }
        setTimeout(function () { input.focus(); }, 50);
    }

    function close() {
        panel.hidden = true;
        root.classList.remove('is-open');
        try { sessionStorage.removeItem(OPEN_KEY); } catch (e) {}
    }

    $('fab').addEventListener('click', function () {
        if (panel.hidden) open(); else close();
    });
    $('close').addEventListener('click', close);
    $('new').addEventListener('click', function () {
        if (state.busy) return;
        setConv(0);
        resetToWelcome();
        input.focus();
    });
    $('form').addEventListener('submit', function (e) {
        e.preventDefault();
        send();
    });
    root.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !panel.hidden) close();
    });

    try { if (sessionStorage.getItem(OPEN_KEY) === '1' && window.innerWidth > 600) open(); } catch (e) {}
})();
