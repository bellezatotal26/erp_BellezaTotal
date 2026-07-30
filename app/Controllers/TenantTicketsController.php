<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;
use SoftNova\Core\Database;
use SoftNova\Core\TenantMiddleware;

class TenantTicketsController extends Controller
{
    private \PDO $db;
    
    public function __construct()
    {
        parent::__construct();
        TenantMiddleware::auth();
        $this->db = TenantMiddleware::getDb();
    }
    
    public function index(): void
    {
        $action = $this->request->get('action');
        if ($action === 'create' && $this->request->method() === 'POST') { $this->create(); return; }
        if ($action === 'reply' && $this->request->method() === 'POST') { $this->reply(); return; }
        
        $masterDb = Database::getInstance();
        $tenantId = $_SESSION['tenant_id'] ?? 0;
        $viewId = $this->request->get('id');
        
        // Si se solicita ver un ticket específico
        if ($viewId) {
            $ticket = $masterDb->query(
                "SELECT t.*, tn.company_name FROM tickets t LEFT JOIN tenants tn ON t.tenant_id=tn.id WHERE t.id=? AND t.tenant_id=?",
                [(int)$viewId, $tenantId]
            )->fetch();
            
            if (!$ticket) {
                $_SESSION['error'] = 'Ticket no encontrado';
                header('Location: ' . (new \SoftNova\Core\View())->route('app/soporte'));
                exit;
            }
            
            $messages = $masterDb->query(
                "SELECT * FROM ticket_messages WHERE ticket_id=? ORDER BY created_at ASC",
                [(int)$viewId]
            )->fetchAll();
            
            $this->view('tenant.ticket_chat', [
                'ticket' => $ticket, 'messages' => $messages,
                'tenantName' => $_SESSION['tenant_name'] ?? '', 'userName' => $_SESSION['tenant_user_name'] ?? '',
            ]);
            return;
        }
        
        $tickets = $masterDb->query(
            "SELECT t.*, (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id=t.id) as msg_count
             FROM tickets t WHERE t.tenant_id=? ORDER BY t.updated_at DESC LIMIT 20",
            [$tenantId]
        )->fetchAll();
        
        $this->view('tenant.tickets', [
            'tickets' => $tickets, 'tenantName' => $_SESSION['tenant_name'] ?? '', 'userName' => $_SESSION['tenant_user_name'] ?? '',
        ]);
    }
    
    private function create(): void
    {
        if (!$this->validateCsrfOrFail('/app/soporte')) return;
        
        $subject = trim($this->request->post('subject') ?? '');
        $description = trim($this->request->post('description') ?? '');
        $priority = $this->request->post('priority', 'medium');
        
        if (empty($subject)) { $this->respond(false, 'El asunto es requerido', '/app/soporte'); return; }
        
        $masterDb = Database::getInstance();
        $tenantId = $_SESSION['tenant_id'] ?? 0;
        $ticketCode = 'TKT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        
        try {
            $masterDb->query(
                "INSERT INTO tickets (tenant_id, user_id, ticket_code, subject, description, priority, category) VALUES (?,?,?,?,?,?,'support')",
                [$tenantId, $_SESSION['tenant_user_id'], $ticketCode, $subject, $description, $priority]
            );
            $ticketId = $masterDb->lastInsertId();
            $masterDb->query(
                "INSERT INTO ticket_messages (ticket_id, user_name, message, is_staff) VALUES (?,?,?,0)",
                [$ticketId, $_SESSION['tenant_user_name'] ?? 'Cliente', $description ?: $subject]
            );
            $this->respond(true, 'Ticket creado: ' . $ticketCode, '/app/soporte');
        } catch (\Exception $e) {
            $this->respond(false, 'Error: ' . $e->getMessage(), '/app/soporte');
        }
    }
    
    private function reply(): void
    {
        if (!$this->validateCsrfOrFail('/app/soporte')) return;
        
        $ticketId = (int)$this->request->post('ticket_id');
        $message = trim($this->request->post('message') ?? '');
        
        if (empty($message)) { $this->respond(false, 'El mensaje no puede estar vacío', '/app/soporte?id='.$ticketId); return; }
        
        $masterDb = Database::getInstance();
        $masterDb->query(
            "INSERT INTO ticket_messages (ticket_id, user_name, message, is_staff) VALUES (?,?,?,0)",
            [$ticketId, $_SESSION['tenant_user_name'] ?? 'Cliente', $message]
        );
        
        // Si está cerrado, reabrir
        $masterDb->query("UPDATE tickets SET status='open', updated_at=NOW() WHERE id=? AND status IN ('resolved','closed')", [$ticketId]);
        
        $this->respond(true, 'Mensaje enviado', '/app/soporte?id='.$ticketId);
    }
}
