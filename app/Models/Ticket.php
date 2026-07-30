<?php

namespace SoftNova\Models;

use SoftNova\Core\Model;

/**
 * Modelo para la tabla tickets
 */
class Ticket extends Model
{
    protected string $table = 'tickets';
    protected string $primaryKey = 'id';

    /**
     * Obtener tickets con información del tenant y conteo de mensajes
     */
    public function allWithDetails(string $whereClause = '', array $params = []): array
    {
        $sql = "SELECT t.*, 
                       tn.company_name,
                       tn.email as tenant_email,
                       (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = t.id) as message_count
                FROM {$this->table} t
                LEFT JOIN tenants tn ON t.tenant_id = tn.id
                {$whereClause}
                ORDER BY 
                    FIELD(t.priority, 'urgent', 'high', 'medium', 'low'),
                    FIELD(t.status, 'open', 'in_progress', 'resolved', 'closed'),
                    t.created_at DESC";
        return $this->db->query($sql, $params)->fetchAll();
    }

    /**
     * Obtener ticket con datos del tenant
     */
    public function findWithTenant(int $id): ?array
    {
        $sql = "SELECT t.*, tn.company_name, tn.email as tenant_email
                FROM {$this->table} t
                LEFT JOIN tenants tn ON t.tenant_id = tn.id
                WHERE t.id = ?";
        return $this->db->query($sql, [$id])->fetch() ?: null;
    }

    /**
     * Obtener mensajes de un ticket
     */
    public function getMessages(int $ticketId): array
    {
        $sql = "SELECT * FROM ticket_messages
                WHERE ticket_id = ?
                ORDER BY created_at ASC";
        return $this->db->query($sql, [$ticketId])->fetchAll();
    }

    /**
     * Agregar mensaje a un ticket
     */
    public function addMessage(int $ticketId, string $userName, string $message, bool $isStaff = true): int
    {
        $this->db->query("
            INSERT INTO ticket_messages (ticket_id, user_name, message, is_staff)
            VALUES (?, ?, ?, ?)
        ", [$ticketId, $userName, $message, $isStaff ? 1 : 0]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Actualizar estado del ticket
     */
    public function updateStatus(int $id, string $status): void
    {
        $resolvedAt = ($status === 'resolved' || $status === 'closed') ? date('Y-m-d H:i:s') : null;
        $this->db->query("
            UPDATE {$this->table} SET status = ?, resolved_at = ?, updated_at = NOW() WHERE id = ?
        ", [$status, $resolvedAt, $id]);
    }

    /**
     * Reabrir ticket si está cerrado/resuelto
     */
    public function reopenIfClosed(int $id): void
    {
        $this->db->query("
            UPDATE {$this->table} SET status = 'open', updated_at = NOW() 
            WHERE id = ? AND status IN ('resolved', 'closed')
        ", [$id]);
    }

    /**
     * Estadísticas de tickets
     */
    public function getStats(): array
    {
        return [
            'total' => (int) $this->db->query("SELECT COUNT(*) as c FROM {$this->table}")->fetch()['c'],
            'open' => (int) $this->db->query("SELECT COUNT(*) as c FROM {$this->table} WHERE status = 'open'")->fetch()['c'],
            'in_progress' => (int) $this->db->query("SELECT COUNT(*) as c FROM {$this->table} WHERE status = 'in_progress'")->fetch()['c'],
            'resolved' => (int) $this->db->query("SELECT COUNT(*) as c FROM {$this->table} WHERE status = 'resolved'")->fetch()['c'],
            'urgent' => (int) $this->db->query("SELECT COUNT(*) as c FROM {$this->table} WHERE priority = 'urgent' AND status IN ('open', 'in_progress')")->fetch()['c'],
        ];
    }
}