// Software Belleza Total — autenticación (login)

document.addEventListener('DOMContentLoaded', function() {
    const loginForm = document.querySelector('.login-form') || document.querySelector('.login-card form');

    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            const emailField = document.getElementById('email');
            const passwordField = document.getElementById('password');

            if (!emailField || !passwordField) return;

            const email = emailField.value.trim();
            const password = passwordField.value;

            if (!email || !password) {
                e.preventDefault();
                if (typeof showAlert === 'function') {
                    showAlert('Por favor complete todos los campos', 'error');
                }
                return;
            }

            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                e.preventDefault();
                if (typeof showAlert === 'function') {
                    showAlert('Por favor ingrese un email válido', 'error');
                }
            }
        });
    }

    const toggle = document.getElementById('togglePassword');
    const passwordField = document.getElementById('password');
    if (toggle && passwordField) {
        toggle.addEventListener('click', function() {
            const show = passwordField.type === 'password';
            passwordField.type = show ? 'text' : 'password';
            toggle.classList.toggle('is-visible', show);
            toggle.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
        });
    }
});
