<?php

namespace SoftNova\Models;

use SoftNova\Core\Model;

/**
 * Modelo para la tabla license_sales
 */
class License extends Model
{
    protected string $table = 'license_sales';
    protected string $primaryKey = 'id';

    /**
     * Obtener ventas con datos de tenant, plan y total pagado
     */
    public function allWithDetails(): array
    {
        $sql = "SELECT ls.*, 
                       t.company_name,
                       t.email as tenant_email,
                       t.status as tenant_status,
                       sp.name as plan_name,
                       sp.monthly_price,
                       sp.annual_price,
                       COALESCE(SUM(lp.amount), 0) as paid_amount
                FROM {$this->table} ls
                LEFT JOIN tenants t ON ls.tenant_id = t.id
                LEFT JOIN subscription_plans sp ON ls.plan_id = sp.id
                LEFT JOIN license_payments lp ON ls.id = lp.license_sale_id
                GROUP BY ls.id
                ORDER BY ls.created_at DESC";
        return $this->db->query($sql)->fetchAll();
    }

    /**
     * Registrar un pago
     */
    public function registerPayment(int $saleId, float $amount, string $paymentDate, string $paymentMethod, ?string $referenceNumber, ?string $notes, ?int $createdBy): void
    {
        $this->db->query("
            INSERT INTO license_payments (license_sale_id, payment_date, amount, payment_method, reference_number, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ", [$saleId, $paymentDate, $amount, $paymentMethod, $referenceNumber, $notes, $createdBy]);
    }

    /**
     * Actualizar estado de pago basado en total pagado
     */
    public function updatePaymentStatus(int $saleId): void
    {
        $sale = $this->find($saleId);
        if (!$sale) return;

        $totalPaid = $this->db->query("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM license_payments
            WHERE license_sale_id = ?
        ", [$saleId])->fetch()['total'];

        $newStatus = 'pending';
        if ($totalPaid >= $sale['amount']) {
            $newStatus = 'paid';
        } elseif ($totalPaid > 0) {
            $newStatus = 'partial';
        }

        $this->db->query("UPDATE {$this->table} SET payment_status = ? WHERE id = ?", [$newStatus, $saleId]);
    }

    /**
     * Estadísticas de licencias
     */
    public function getStats(): array
    {
        return [
            'total_sales' => (int) $this->db->query("SELECT COUNT(*) as count FROM {$this->table} WHERE status = 'active'")->fetch()['count'],
            'total_revenue' => (float) $this->db->query("SELECT COALESCE(SUM(amount), 0) as total FROM {$this->table} WHERE status = 'active'")->fetch()['total'],
            'pending_payments' => (int) $this->db->query("SELECT COUNT(*) as count FROM {$this->table} WHERE payment_status = 'pending'")->fetch()['count'],
            'active_subscriptions' => (int) $this->db->query("SELECT COUNT(*) as count FROM {$this->table} WHERE status = 'active' AND end_date >= CURDATE()")->fetch()['count'],
        ];
    }
}