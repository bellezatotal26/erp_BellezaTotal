<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;

class TenantProveedoresController extends TenantController
{
    public function __construct()
    {
        parent::__construct();
        TenantMiddleware::authorize('proveedores');
    }
    
    public function index(): void
    {
        $action = $this->request->get('action');
        if ($action === 'create' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('proveedores', 'create');
            $this->create();
            return;
        }
        if ($action === 'edit' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('proveedores', 'edit');
            $this->edit();
            return;
        }
        if ($action === 'delete' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('proveedores', 'delete');
            $this->delete();
            return;
        }
        if ($action === 'export' && $this->request->method() === 'GET') {
            TenantMiddleware::authorize('proveedores', 'export');
            $this->exportSuppliers();
            return;
        }
        
        $total = (int)$this->query("SELECT COUNT(*) as c FROM suppliers WHERE status = 'active'")->fetch()['c'];
        $pagination = $this->paginate($total);
        
        $suppliers = $this->query(
            "SELECT * FROM suppliers WHERE status = 'active' ORDER BY name ASC
             LIMIT {$pagination['perPage']} OFFSET {$pagination['offset']}"
        )->fetchAll();
        
        $this->view('tenant.proveedores', $this->tenantViewData([
            'suppliers' => $suppliers,
            'pagination' => $pagination,
        ]));
    }
    
    private function create(): void
    {
        if (!$this->validateCsrfOrFail('/app/proveedores')) return;
        
        $name = trim($this->request->post('name') ?? '');
        if (empty($name)) { $this->respond(false, 'El nombre de la empresa es requerido', '/app/proveedores'); return; }
        
        $documentNumber = trim((string)$this->request->post('document_number'));
        $phone = trim((string)$this->request->post('phone'));
        if ($documentNumber === '' || $phone === '') {
            $this->respond(false, 'El número de documento y el teléfono son obligatorios', '/app/proveedores');
            return;
        }
        
        $image = $this->uploadImage();
        // Asegurar columnas aliado
        try {
            $cols = [];
            foreach ($this->db->query('SHOW COLUMNS FROM suppliers')->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                $cols[strtolower((string)$c['Field'])] = true;
            }
            $alters = [];
            if (!isset($cols['is_ally'])) $alters[] = 'ADD COLUMN is_ally TINYINT(1) NOT NULL DEFAULT 0';
            if (!isset($cols['discount_percent'])) $alters[] = 'ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0';
            if ($alters) $this->db->exec('ALTER TABLE suppliers ' . implode(', ', $alters));
        } catch (\Throwable $e) {}

        $isAlly = $this->request->post('is_ally') === '1' ? 1 : 0;
        $discountPct = max(0, min(100, (float)$this->request->post('discount_percent', 0)));
        
        $this->query("INSERT INTO suppliers (name, document_type, document_number, contact_name, email, phone, address, notes, image, is_ally, discount_percent) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [$name, $this->request->post('document_type','NIT'), $documentNumber, $this->request->post('contact_name'), $this->request->post('email'), $phone, $this->request->post('address'), $this->request->post('notes'), $image, $isAlly, $discountPct]);
        
        $this->respond(true, 'Proveedor creado: ' . $name, '/app/proveedores');
    }
    
    private function edit(): void
    {
        if (!$this->validateCsrfOrFail('/app/proveedores')) return;
        $id = (int)$this->request->post('id');
        $name = trim($this->request->post('name') ?? '');
        if (empty($name)) { $this->respond(false, 'El nombre de la empresa es requerido', '/app/proveedores'); return; }
        
        $documentNumber = trim((string)$this->request->post('document_number'));
        $phone = trim((string)$this->request->post('phone'));
        if ($documentNumber === '' || $phone === '') {
            $this->respond(false, 'El número de documento y el teléfono son obligatorios', '/app/proveedores');
            return;
        }
        
        $image = $this->uploadImage();
        $isAlly = $this->request->post('is_ally') === '1' ? 1 : 0;
        $discountPct = max(0, min(100, (float)$this->request->post('discount_percent', 0)));
        $sql = "UPDATE suppliers SET name=?, document_type=?, document_number=?, contact_name=?, email=?, phone=?, address=?, notes=?, is_ally=?, discount_percent=?";
        $params = [$name, $this->request->post('document_type','NIT'), $documentNumber, $this->request->post('contact_name'), $this->request->post('email'), $phone, $this->request->post('address'), $this->request->post('notes'), $isAlly, $discountPct];
        
        if ($image) {
            $sql .= ", image=?";
            $params[] = $image;
        }
        $sql .= " WHERE id=?";
        $params[] = $id;
        
        $this->query($sql, $params);
        $this->respond(true, 'Proveedor actualizado', '/app/proveedores');
    }
    
    private function uploadImage(): ?string
    {
        if (empty($_FILES['image']['tmp_name'])) {
            return null;
        }
        if (!is_uploaded_file($_FILES['image']['tmp_name'])) {
            return null;
        }
        if (($_FILES['image']['size'] ?? 0) > 2097152) {
            return null;
        }
        
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['image']['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) {
            return null;
        }
        
        $uploadDir = PUBLIC_PATH . '/uploads/suppliers/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $filename = 'sup_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
        $dest = $uploadDir . $filename;
        
        if (move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
            $baseUrl = \SoftNova\Core\config('app.url', 'http://localhost/SoftNova/public');
            return rtrim($baseUrl, '/') . '/uploads/suppliers/' . $filename;
        }
        return null;
    }
    
    private function delete(): void
    {
        if (!$this->validateCsrfOrFail('/app/proveedores')) {
            return;
        }
        $id = (int)$this->request->post('id');
        if ($id <= 0) {
            $this->respond(false, 'Proveedor invalido', '/app/proveedores');
            return;
        }
        
        $stmt = $this->query("UPDATE suppliers SET status = 'inactive' WHERE id = ?", [$id]);
        if ($stmt->rowCount() < 1) {
            $this->respond(false, 'No se pudo eliminar el proveedor', '/app/proveedores');
            return;
        }
        
        $this->respond(true, 'Proveedor eliminado', '/app/proveedores');
    }
    
    private function exportSuppliers(): void
    {
        $rows = $this->query(
            "SELECT name, document_type, document_number, contact_name, email, phone, status
             FROM suppliers ORDER BY name"
        )->fetchAll();
        
        $csvRows = [];
        foreach ($rows as $r) {
            $csvRows[] = [
                $r['name'], $r['document_type'], $r['document_number'], $r['contact_name'],
                $r['email'], $r['phone'], $r['status'],
            ];
        }
        
        $this->exportCsv(
            'proveedores_' . date('Ymd_His') . '.csv',
            ['Nombre', 'Tipo Doc', 'Documento', 'Contacto', 'Email', 'Telefono', 'Estado'],
            $csvRows
        );
    }
}
