<?php

namespace SoftNova\Models;

use SoftNova\Core\Model;

/**
 * Modelo para la tabla tenants
 */
class Tenant extends Model
{
    protected string $table = 'tenants';
    protected string $primaryKey = 'id';

    /**
     * Obtener tenants con su plan asociado
     */
    public function allWithPlan(): array
    {
        $sql = "SELECT t.*, sp.name as plan_name, sp.monthly_price
                FROM {$this->table} t
                LEFT JOIN subscription_plans sp ON t.subscription_plan_id = sp.id
                ORDER BY t.created_at DESC";
        return $this->db->query($sql)->fetchAll();
    }

    /**
     * Buscar tenant por email
     */
    public function findByEmail(string $email): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE email = ? LIMIT 1";
        return $this->db->query($sql, [$email])->fetch() ?: null;
    }

    /**
     * Buscar tenant por email excluyendo un ID
     */
    public function findByEmailExcept(string $email, int $excludeId): ?array
    {
        $sql = "SELECT id FROM {$this->table} WHERE email = ? AND id != ? LIMIT 1";
        return $this->db->query($sql, [$email, $excludeId])->fetch() ?: null;
    }

    /**
     * Suspender tenants con planes vencidos
     */
    public function suspendExpired(): int
    {
        $today = date('Y-m-d');
        $stmt = $this->db->query("
            UPDATE {$this->table}
            SET status = 'suspended', updated_at = NOW()
            WHERE status = 'active'
              AND subscription_end_date IS NOT NULL
              AND subscription_end_date < ?
        ", [$today]);
        return $stmt->rowCount();
    }

    /**
     * Contar tenants por estado
     */
    public function countByStatus(string $status): int
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE status = ?";
        return (int) $this->db->query($sql, [$status])->fetch()['count'];
    }

    /**
     * Contar total de tenants
     */
    public function countAll(): int
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table}";
        return (int) $this->db->query($sql)->fetch()['count'];
    }
}