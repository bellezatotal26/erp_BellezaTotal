// Seri ERP - Módulo Configuración
'use strict';

function setTheme(t) {
    if (t === 'dark') {
        document.body.classList.add('dark-mode');
        document.getElementById('btnDark').className = 'btn btn-primary neumorphic-btn';
        document.getElementById('btnLight').className = 'btn btn-secondary';
    } else {
        document.body.classList.remove('dark-mode');
        document.getElementById('btnLight').className = 'btn btn-primary neumorphic-btn';
        document.getElementById('btnDark').className = 'btn btn-secondary';
    }
    localStorage.setItem('theme', t);
}

function setLang(l) {
    if (l === 'en') {
        document.getElementById('btnEn').className = 'btn btn-primary neumorphic-btn';
        document.getElementById('btnEs').className = 'btn btn-secondary';
    } else {
        document.getElementById('btnEs').className = 'btn btn-primary neumorphic-btn';
        document.getElementById('btnEn').className = 'btn btn-secondary';
    }
    localStorage.setItem('lang', l);
    alert(l === 'en' ? 'Language set to English (page reload required)' : 'Idioma configurado a Español (recargue la página)');
}

function uploadAvatar(input) {
    if (!input.files || !input.files[0]) return;
    
    var file = input.files[0];
    var allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    var maxSize = 2 * 1024 * 1024; // 2MB
    
    if (allowedTypes.indexOf(file.type) === -1) {
        showAlert('Formato no permitido. Use JPG, PNG, GIF o WebP', 'error');
        input.value = '';
        return;
    }
    
    if (file.size > maxSize) {
        showAlert('La imagen no debe superar 2MB', 'error');
        input.value = '';
        return;
    }
    
    var form = document.getElementById('avatarForm');
    var formData = new FormData(form);
    
    var overlay = showLoadingOverlay();
    
    fetch(form.action, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(response) {
        if (!response.ok) throw new Error('Error del servidor');
        return response.json();
    })
    .then(function(data) {
        hideLoadingOverlay(overlay);
        if (data.success) {
            // Actualizar preview
            var reader = new FileReader();
            reader.onload = function(e) {
                var preview = document.getElementById('avatarPreview');
                preview.innerHTML = '<img src="' + e.target.result + '" alt="Avatar" style="width:100%;height:100%;object-fit:cover;">';
            };
            reader.readAsDataURL(file);
            showAlert(data.message, 'success');
            // Recargar para que el header también se actualice
            setTimeout(function() { window.location.reload(); }, 800);
        } else {
            showAlert(data.message, 'error');
            input.value = '';
        }
    })
    .catch(function(error) {
        hideLoadingOverlay(overlay);
        console.error('Error:', error);
        showAlert('Error de conexión al subir la imagen', 'error');
        input.value = '';
    });
}

(function() {
    if (localStorage.getItem('theme') === 'dark') setTheme('dark');
    else document.getElementById('btnLight').className = 'btn btn-primary neumorphic-btn';
    if (localStorage.getItem('lang') === 'en') setLang('en');
    else document.getElementById('btnEs').className = 'btn btn-primary neumorphic-btn';
})();
