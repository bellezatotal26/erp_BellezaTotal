// Seri ERP - Módulo Clientes
'use strict';

function openModal(data) {
    if (data) {
        document.getElementById('modalTitle').textContent = 'Editar Cliente';
        document.getElementById('customerForm').action = window.cliRouteEdit;
        document.getElementById('submitBtn').textContent = 'Guardar';
        document.getElementById('custId').value = data.id;
        document.getElementById('custFirstName').value = data.first_name || data.name || '';
        document.getElementById('custLastName').value = data.last_name || '';
        document.getElementById('custCompany').value = data.company_name || '';
        document.getElementById('custSource').value = data.source || '';
        document.getElementById('custDocType').value = data.document_type || 'CC';
        document.getElementById('custDocNum').value = data.document_number || '';
        document.getElementById('custEmail').value = data.email || '';
        document.getElementById('custPhone').value = data.phone || '';
        document.getElementById('custAddress').value = data.address || '';
    } else {
        document.getElementById('modalTitle').textContent = 'Nuevo Cliente';
        document.getElementById('customerForm').action = window.cliRouteCreate;
        document.getElementById('submitBtn').textContent = 'Crear Cliente';
        ['custId','custFirstName','custLastName','custCompany','custDocNum','custEmail','custPhone','custAddress'].forEach(function(id) {
            document.getElementById(id).value = '';
        });
        document.getElementById('custSource').value = '';
        document.getElementById('custDocType').value = 'CC';
    }
    document.getElementById('customerModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('customerModal').style.display = 'none';
}

function viewDetail(id) {
    fetch(window.cliRouteDetail + '&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.error) return alert(d.error);
        var c = d.customer, p = d.purchases || [];
        var html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">' +
            '<div><strong>Nombre:</strong> ' + esc(c.first_name || c.name) + '</div>' +
            '<div><strong>Apellido:</strong> ' + esc(c.last_name || '-') + '</div>' +
            '<div><strong>Empresa:</strong> ' + esc(c.company_name || '-') + '</div>' +
            '<div><strong>Origen:</strong> ' + esc(c.source || '-') + '</div>' +
            '<div><strong>Documento:</strong> ' + esc((c.document_type || '') + ' ' + (c.document_number || '')) + '</div>' +
            '<div><strong>Email:</strong> ' + esc(c.email || '-') + '</div>' +
            '<div><strong>Teléfono:</strong> ' + esc(c.phone || '-') + '</div>' +
            '<div><strong>Dirección:</strong> ' + esc(c.address || '-') + '</div></div>';
        html += '<h4 style="margin-bottom:10px;">📋 Historial de Compras (' + p.length + ')</h4>';
        if (p.length === 0) {
            html += '<p style="color:var(--color-text-secondary);text-align:center;">Sin compras registradas</p>';
        } else {
            html += '<div class="table-container"><table><thead><tr><th>Factura</th><th>Fecha</th><th>Total</th><th>Método</th><th>Vendedor</th></tr></thead><tbody>';
            p.forEach(function(s) {
                html += '<tr><td>' + esc(s.invoice_number) + '</td><td>' + (s.sale_date || '').substr(0, 16) + '</td><td>$' + parseFloat(s.total).toFixed(0) + '</td><td>' + esc(s.payment_method) + '</td><td>' + esc(s.user_name || '-') + '</td></tr>';
            });
            html += '</tbody></table></div>';
        }
        document.getElementById('detailContent').innerHTML = html;
        document.getElementById('detailModal').style.display = 'flex';
    });
}
