// Seri ERP - Módulo Proveedores
'use strict';

function openModal(data) {
    if (data) {
        document.getElementById('modalTitle').textContent = 'Editar Proveedor';
        document.getElementById('supplierForm').action = window.provRouteEdit;
        document.getElementById('submitBtn').textContent = 'Guardar';
        document.getElementById('supId').value = data.id;
        document.getElementById('supName').value = data.name || '';
        document.getElementById('supContact').value = data.contact_name || '';
        document.getElementById('supDocType').value = data.document_type || 'NIT';
        document.getElementById('supDocNum').value = data.document_number || '';
        document.getElementById('supEmail').value = data.email || '';
        document.getElementById('supPhone').value = data.phone || '';
        document.getElementById('supAddress').value = data.address || '';
        document.getElementById('supNotes').value = data.notes || '';
    } else {
        document.getElementById('modalTitle').textContent = 'Nuevo Proveedor';
        document.getElementById('supplierForm').action = window.provRouteCreate;
        document.getElementById('submitBtn').textContent = 'Crear Proveedor';
        ['supId','supName','supContact','supDocNum','supEmail','supPhone','supAddress','supNotes'].forEach(function(id) {
            document.getElementById(id).value = '';
        });
        document.getElementById('supDocType').value = 'NIT';
        document.getElementById('supImage').value = '';
    }
    document.getElementById('supplierModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('supplierModal').style.display = 'none';
}
