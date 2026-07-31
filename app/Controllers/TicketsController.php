<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;
use SoftNova\Core\Database;
use SoftNova\Core\Middleware;
use SoftNova\Services\AuditService;
use function SoftNova\Core\redirect;

/**
 * Controlador del módulo Tickets de Soporte
 * Gestiona tickets y chat interno entre clientes y super admin
 */
class TicketsController extends Controller
{
    private Database $db;
    
    public function __construct()
    {
        parent::__construct();
        Middleware::auth();
        $this->db = Database::getInstance();
    }
    
    private function generateTicketCode(): string
    {
        $prefix = 'TKT-' . date('Ymd') . '-';
        $random = strtoupper(bin2hex(random_bytes(3)));
        return $prefix . $random;
    }
    
    /**
     * Lista todos los tickets
     */
    public function index(): void
    {
        $action = $this->request->post('action') ?? $this->request->get('action');
        
        if ($this->request->method() === 'POST') {
            if ($action === 'create') {
                $this->createTicket();
                return;
            }
            if ($action === 'update_status') {
                $this->updateStatus();
                return;
            }
            if ($action === 'assign') {
                $this->assignTicket();
                return;
            }
            if ($action === 'message') {
                $this->addMessage();
                return;
            }
        }
        
        // Filtros
        $statusFilter = $this->request->get('status', '');
        $priorityFilter = $this->request->get('priority', '');
        $tenantFilter = $this->request->get('tenant_id', '');
        
        $where = [];
        $params = [];
        
        if ($statusFilter) {
            $where[] = 't.status = ?';
            $params[] = $statusFilter;
        }
        if ($priorityFilter) {
            $where[] = 't.priority = ?';
            $params[] = $priorityFilter;
        }
        if ($tenantFilter) {
            $where[] = 't.tenant_id = ?';
            $params[] = (int) $tenantFilter;
        }
        
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $tickets = $this->db->query("
            SELECT t.*,
                   tn.company_name,
                   tn.email as tenant_email,
                   (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = t.id) as message_count
            FROM tickets t
            LEFT JOIN tenants tn ON t.tenant_id = tn.id
            {$whereClause}
            ORDER BY
                FIELD(t.priority, 'urgent', 'high', 'medium', 'low'),
                FIELD(t.status, 'open', 'in_progress', 'resolved', 'closed'),
                t.created_at DESC
        ", $params)->fetchAll();
        
        $tenants = $this->db->query("SELECT id, company_name FROM tenants ORDER BY company_name ASC")->fetchAll();
        
        $stats = [
            'total' => $this->db->query("SELECT COUNT(*) as c FROM tickets")->fetch()['c'],
            'open' => $this->db->query("SELECT COUNT(*) as c FROM tickets WHERE status = 'open'")->fetch()['c'],
            'in_progress' => $this->db->query("SELECT COUNT(*) as c FROM tickets WHERE status = 'in_progress'")->fetch()['c'],
            'resolved' => $this->db->query("SELECT COUNT(*) as c FROM tickets WHERE status = 'resolved'")->fetch()['c'],
            'urgent' => $this->db->query("SELECT COUNT(*) as c FROM tickets WHERE priority = 'urgent' AND status IN ('open', 'in_progress')")->fetch()['c'],
        ];
        
        // Logs de actividad de clientes (últimas acciones)
        $activityLogs = $this->db->query("
            SELECT al.*, t.company_name as tenant_name
            FROM audit_logs al
            LEFT JOIN tenants t ON al.tenant_id = t.id
            WHERE al.tenant_id IS NOT NULL
            ORDER BY al.created_at DESC
            LIMIT 30
        ")->fetchAll();
        
        $this->view('superadmin.tickets', [
            'tickets' => $tickets,
            'tenants' => $tenants,
            'stats' => $stats,
            'activityLogs' => $activityLogs,
            'filters' => [
                'status' => $statusFilter,
                'priority' => $priorityFilter,
                'tenant_id' => $tenantFilter,
            ]
        ]);
    }
    
    /**
     * Ver detalle de un ticket con mensajes
     */
    public function show(int $id): void
    {
        $ticket = $this->db->query("
            SELECT t.*, tn.company_name, tn.email as tenant_email
            FROM tickets t
            LEFT JOIN tenants tn ON t.tenant_id = tn.id
            WHERE t.id = ?
        ", [$id])->fetch();
        
        if (!$ticket) {
            $_SESSION['error'] = 'Ticket no encontrado';
            redirect('/superadmin/tickets');
            return;
        }
        
        $messages = $this->db->query("
            SELECT * FROM ticket_messages
            WHERE ticket_id = ?
            ORDER BY created_at ASC
        ", [$id])->fetchAll();
        
        $tenants = $this->db->query("SELECT id, company_name FROM tenants ORDER BY company_name ASC")->fetchAll();
        
        $this->view('superadmin.ticket_detail', [
            'ticket' => $ticket,
            'messages' => $messages,
            'tenants' => $tenants
        ]);
    }
    
    /**
     * Crea un nuevo ticket
     */
    private function createTicket(): void
    {
        if (!$this->validateCsrfOrFail('/superadmin/tickets')) {
            return;
        }
        
        $tenantId = (int) $this->request->post('tenant_id');
        $subject = trim($this->request->post('subject'));
        $description = trim($this->request->post('description'));
        $priority = $this->request->post('priority', 'medium');
        $category = $this->request->post('category', 'support');
        
        if ($tenantId <= 0 || empty($subject)) {
            $this->respond(false, 'Cliente y asunto son obligatorios', '/superadmin/tickets');
            return;
        }
        
        $ticketCode = $this->generateTicketCode();
        
        try {
            $this->db->query("
                INSERT INTO tickets (tenant_id, ticket_code, subject, description, priority, category)
                VALUES (?, ?, ?, ?, ?, ?)
            ", [$tenantId, $ticketCode, $subject, $description, $priority, $category]);
            
            $ticketId = $this->db->lastInsertId();
            
            // Agregar mensaje inicial con la descripción
            $this->db->query("
                INSERT INTO ticket_messages (ticket_id, user_name, message, is_staff)
                VALUES (?, ?, ?, ?)
            ", [$ticketId, $_SESSION['super_admin_name'] ?? 'Super Admin', $description ?: $subject, 1]);
            
            AuditService::log('create', 'tickets', "Ticket creado: {$ticketCode}", $tenantId);
            
            $this->respond(true, 'Ticket creado exitosamente', '/superadmin/tickets');
        } catch (\Exception $e) {
            $this->respond(false, 'Error al crear el ticket: ' . $e->getMessage(), '/superadmin/tickets');
        }
    }
    
    /**
     * Actualiza el estado de un ticket
     */
    private function updateStatus(): void
    {
        if (!$this->validateCsrfOrFail('/superadmin/tickets')) {
            return;
        }
        
        $id = (int) $this->request->post('id');
        $status = $this->request->post('status');
        
        $ticket = $this->db->query("SELECT * FROM tickets WHERE id = ?", [$id])->fetch();
        
        if (!$ticket) {
            $this->respond(false, 'Ticket no encontrado', '/superadmin/tickets');
            return;
        }
        
        $resolvedAt = ($status === 'resolved' || $status === 'closed') ? date('Y-m-d H:i:s') : null;
        
        $this->db->query("
            UPDATE tickets SET status = ?, resolved_at = ?, updated_at = NOW() WHERE id = ?
        ", [$status, $resolvedAt, $id]);
        
        AuditService::log('update', 'tickets', "Ticket #{$id} estado cambiado a: {$status}", $ticket['tenant_id']);
        
        $this->respond(true, 'Estado actualizado exitosamente', '/superadmin/tickets');
    }
    
    /**
     * Asigna un ticket a un super admin
     */
    private function assignTicket(): void
    {
        if (!$this->validateCsrfOrFail('/superadmin/tickets')) {
            return;
        }
        
        $id = (int) $this->request->post('id');
        $assignedTo = (int) $this->request->post('assigned_to');
        
        $this->db->query("UPDATE tickets SET assigned_to = ?, updated_at = NOW() WHERE id = ?", [$assignedTo, $id]);
        
        AuditService::log('update', 'tickets', "Ticket #{$id} asignado a super admin ID: {$assignedTo}");
        
        $this->respond(true, 'Ticket asignado exitosamente', '/superadmin/tickets');
    }
    
    /**
     * Agrega un mensaje al chat del ticket
     */
    private function addMessage(): void
    {
        if (!$this->validateCsrfOrFail('/superadmin/tickets')) {
            return;
        }
        
        $ticketId = (int) $this->request->post('ticket_id');
        $message = trim($this->request->post('message'));
        
        if (empty($message)) {
            $this->respond(false, 'El mensaje no puede estar vacío', '/superadmin/tickets');
            return;
        }
        
        $ticket = $this->db->query("SELECT * FROM tickets WHERE id = ?", [$ticketId])->fetch();
        
        if (!$ticket) {
            $this->respond(false, 'Ticket no encontrado', '/superadmin/tickets');
            return;
        }
        
        $this->db->query("
            INSERT INTO ticket_messages (ticket_id, user_name, message, is_staff)
            VALUES (?, ?, ?, ?)
        ", [$ticketId, $_SESSION['super_admin_name'] ?? 'Super Admin', $message, 1]);
        
        // Si el ticket está resuelto/cerrado, reabrir
        if (in_array($ticket['status'], ['resolved', 'closed'])) {
            $this->db->query("UPDATE tickets SET status = 'open', updated_at = NOW() WHERE id = ?", [$ticketId]);
        }
        
        $redirect = '/superadmin/tickets/view/' . $ticketId;
        $this->respond(true, 'Mensaje enviado exitosamente', $redirect);
    }
}