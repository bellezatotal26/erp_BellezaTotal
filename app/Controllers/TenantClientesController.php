<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;

class TenantClientesController extends TenantController
{
    public function __construct()
    {
        parent::__construct();
        TenantMiddleware::authorize('clientes');
    }
    
    public function index(): void
    {
        $action = $this->request->get('action');
        
        if ($action === 'create' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('clientes', 'create');
            $this->create();
            return;
        }
        if ($action === 'edit' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('clientes', 'edit');
            $this->edit();
            return;
        }
        if ($action === 'delete' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('clientes', 'delete');
            $this->delete();
            return;
        }
        if ($action === 'detail' && $this->request->method() === 'GET') {
            $this->detail();
            return;
        }
        if ($action === 'export' && $this->request->method() === 'GET') {
            TenantMiddleware::authorize('clientes', 'export');
            $this->exportCustomers();
            return;
        }
        
        $total = (int)$this->query("SELECT COUNT(*) as c FROM customers WHERE status = 'active'")->fetch()['c'];
        $pagination = $this->paginate($total);
        
        $customers = $this->query(
            "SELECT * FROM customers WHERE status = 'active' ORDER BY name ASC
             LIMIT {$pagination['perPage']} OFFSET {$pagination['offset']}"
        )->fetchAll();
        
        $this->view('tenant.clientes', $this->tenantViewData([
            'customers' => $customers,
            'pagination' => $pagination,
        ]));
    }
    
    public function detail(): void
    {
        $id = (int)$this->request->get('id');
        $customer = $this->query("SELECT * FROM customers WHERE id = ?", [$id])->fetch();
        if (!$customer) {
            $this->json(['error' => 'Cliente no encontrado']);
            return;
        }
        
        $purchases = $this->query(
            "SELECT s.*, u.name as user_name FROM sales s LEFT JOIN users u ON s.user_id = u.id
             WHERE s.customer_id = ? AND s.status = 'completed' ORDER BY s.created_at DESC LIMIT 20",
            [$id]
        )->fetchAll();
        
        $this->json(['customer' => $customer, 'purchases' => $purchases]);
    }
    
    private function create(): void
    {
        if (!$this->validateCsrfOrFail('/app/clientes')) {
            return;
        }
        
        $name = trim($this->request->post('name') ?? '');
        $firstName = trim($this->request->post('first_name') ?? '');
        $lastName = trim($this->request->post('last_name') ?? '');
        $companyName = trim($this->request->post('company_name') ?? '');
        $source = $this->request->post('source');
        
        if ($name === '' && $firstName === '') {
            $this->respond(false, 'El nombre es requerido', '/app/clientes');
            return;
        }
        
        $documentNumber = trim((string)$this->request->post('document_number'));
        $phone = trim((string)$this->request->post('phone'));
        if ($documentNumber === '' || $phone === '') {
            $this->respond(false, 'El número de documento y el teléfono son obligatorios', '/app/clientes');
            return;
        }
        
        if ($name === '' && $firstName !== '') {
            $name = trim($firstName . ' ' . $lastName);
        }
        
        $this->query(
            "INSERT INTO customers (name, first_name, last_name, company_name, document_type, document_number, email, phone, address, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $name,
                $firstName ?: null,
                $lastName ?: null,
                $companyName ?: null,
                $this->request->post('document_type', 'CC'),
                $documentNumber,
                $this->request->post('email'),
                $phone,
                $this->request->post('address'),
                $source ?: null,
            ]
        );
        
        $newId = (int)$this->db->lastInsertId();
        if ($this->wantsJson()) {
            $this->json([
                'success' => true,
                'message' => 'Cliente creado',
                'data' => ['id' => $newId, 'name' => $name],
                'redirect' => \SoftNova\Core\base_url('/app/clientes'),
            ]);
            return;
        }
        
        $this->respond(true, 'Cliente creado', '/app/clientes');
    }
    
    private function edit(): void
    {
        if (!$this->validateCsrfOrFail('/app/clientes')) {
            return;
        }
        
        $id = (int)$this->request->post('id');
        $firstName = trim((string)($this->request->post('first_name') ?? ''));
        $lastName = trim((string)($this->request->post('last_name') ?? ''));
        $name = trim((string)($this->request->post('name') ?? ''));
        if ($name === '' && $firstName !== '') {
            $name = trim($firstName . ' ' . $lastName);
        }
        if ($name === '') {
            $this->respond(false, 'El nombre es requerido', '/app/clientes');
            return;
        }
        
        $documentNumber = trim((string)$this->request->post('document_number'));
        $phone = trim((string)$this->request->post('phone'));
        if ($documentNumber === '' || $phone === '') {
            $this->respond(false, 'El número de documento y el teléfono son obligatorios', '/app/clientes');
            return;
        }
        
        $this->query(
            "UPDATE customers SET name = ?, first_name = ?, last_name = ?, company_name = ?, document_type = ?,
                document_number = ?, email = ?, phone = ?, address = ?, source = ?, status = ?
             WHERE id = ?",
            [
                $name,
                $firstName ?: null,
                $lastName ?: null,
                $this->request->post('company_name') ?: null,
                $this->request->post('document_type', 'CC'),
                $documentNumber,
                $this->request->post('email'),
                $phone,
                $this->request->post('address'),
                $this->request->post('source') ?: null,
                $this->request->post('status', 'active'),
                $id,
            ]
        );
        
        $this->respond(true, 'Cliente actualizado', '/app/clientes');
    }
    
    private function delete(): void
    {
        if (!$this->validateCsrfOrFail('/app/clientes')) {
            return;
        }
        $id = (int)$this->request->post('id');
        if ($id <= 0) {
            $this->respond(false, 'Cliente invalido', '/app/clientes');
            return;
        }
        
        // Soft-delete: se oculta del listado (status inactive)
        $stmt = $this->query("UPDATE customers SET status = 'inactive' WHERE id = ?", [$id]);
        if ($stmt->rowCount() < 1) {
            $this->respond(false, 'No se pudo eliminar el cliente', '/app/clientes');
            return;
        }
        
        $this->respond(true, 'Cliente eliminado', '/app/clientes');
    }
    
    private function exportCustomers(): void
    {
        $rows = $this->query(
            "SELECT name, document_type, document_number, email, phone, city, status, created_at
             FROM customers ORDER BY name"
        )->fetchAll();
        
        $csvRows = [];
        foreach ($rows as $r) {
            $csvRows[] = [
                $r['name'], $r['document_type'], $r['document_number'], $r['email'],
                $r['phone'], $r['city'], $r['status'], $r['created_at'],
            ];
        }
        
        $this->exportCsv(
            'clientes_' . date('Ymd_His') . '.csv',
            ['Nombre', 'Tipo Doc', 'Documento', 'Email', 'Telefono', 'Ciudad', 'Estado', 'Creado'],
            $csvRows
        );
    }
}
