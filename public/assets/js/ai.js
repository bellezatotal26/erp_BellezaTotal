// Seri ERP - Asistente IA
'use strict';

function sendMessage() {
    var input = document.getElementById('aiInput');
    var msg = input.value.trim();
    if (!msg) return;

    var container = document.getElementById('aiMessages');
    var time = new Date().toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' });

    container.innerHTML += '<div class="ai-msg user"><div class="bubble">' + esc(msg) + '<div class="time">' + time + '</div></div></div>';
    input.value = '';
    container.scrollTop = container.scrollHeight;

    container.innerHTML += '<div class="ai-typing" id="typing">🤖 Escribiendo...</div>';
    container.scrollTop = container.scrollHeight;

    fetch(window.aiChatUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: 'message=' + encodeURIComponent(msg) + '&csrf_token=' + (window.csrfToken || '')
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        var typing = document.getElementById('typing');
        if (typing) typing.remove();
        container.innerHTML += '<div class="ai-msg assistant"><div class="bubble">' + esc(d.reply || 'No entendí.') + '<div class="time">' + time + '</div></div></div>';
        container.scrollTop = container.scrollHeight;
    });
}
