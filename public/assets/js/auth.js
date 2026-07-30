// Seri ERP - JavaScript de Autenticación

document.addEventListener('DOMContentLoaded', function() {
    const loginForm = document.querySelector('form[action="/login"]');
    
    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            const emailField = document.getElementById('email');
            const passwordField = document.getElementById('password');
            
            if (!emailField || !passwordField) return;
            
            const email = emailField.value.trim();
            const password = passwordField.value;
            
            if (!email || !password) {
                e.preventDefault();
                showAlert('Por favor complete todos los campos', 'error');
                return;
            }
            
            // Validación básica de formato email
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                e.preventDefault();
                showAlert('Por favor ingrese un email válido', 'error');
                return;
            }
        });
    }
});
