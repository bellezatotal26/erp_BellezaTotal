<?php

namespace SoftNova\Models;

use SoftNova\Core\Model;

/**
 * Modelo para la tabla subscription_plans
 */
class Plan extends Model
{
    protected string $table = 'subscription_plans';
    protected string $primaryKey = 'id';

    /**
     * Obtener planes activos
     */
    public function getActive(): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY monthly_price ASC";
        return $this->db->query($sql)->fetchAll();
    }

    /**
     * Obtener todos los planes ordenados por precio
     */
    public function allOrdered(): array
    {
        $sql = "SELECT * FROM {$this->table} ORDER BY monthly_price ASC";
        return $this->db->query($sql)->fetchAll();
    }

    /**
     * Contar total de planes
     */
    public function countAll(): int
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table}";
        return (int) $this->db->query($sql)->fetch()['count'];
    }
}