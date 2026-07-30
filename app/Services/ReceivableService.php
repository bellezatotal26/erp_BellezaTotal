<?php

namespace SoftNova\Services;

/**
 * Cuentas por cobrar generadas desde ventas a credito / pago parcial
 */
class ReceivableService
{
    private \PDO $db;
    
    public function __construct(\PDO $db)
    {
        $this->db = $db;
        $this->ensureTable();
    }
    
    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    private function ensureTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $this->query("SELECT 1 FROM receivables LIMIT 0");
        } catch (\Throwable $e) {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS receivables (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    sale_id INT UNSIGNED NOT NULL,
                    customer_id INT UNSIGNED NULL,
                    invoice_number VARCHAR(50) NOT NULL,
                    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                    balance DECIMAL(12,2) NOT NULL DEFAULT 0,
                    due_date DATE NULL,
                    status ENUM('open', 'partial', 'paid', 'cancelled') NOT NULL DEFAULT 'open',
                    notes TEXT NULL,
                    created_by INT UNSIGNED NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_receivable_sale (sale_id),
                    INDEX idx_status (status),
                    INDEX idx_customer (customer_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        $done = true;
    }
    
    /**
     * Crear o actualizar tarea de CxC al registrar venta a credito
     */
    public function upsertFromSale(
        int $saleId,
        ?int $customerId,
        string $invoiceNumber,
        float $total,
        float $paid,
        ?string $notes = null,
        ?string $dueDate = null
    ): void {
        $balance = max(0, round($total - $paid, 2));
        if ($balance <= 0) {
            $this->markPaid($saleId, $total);
            return;
        }
        
        $status = $paid > 0 ? 'partial' : 'open';
        if ($dueDate === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            $dueDate = date('Y-m-d', strtotime('+15 days'));
        }
        $taskNote = $notes ?: ('Cobrar saldo pendiente de factura ' . $invoiceNumber);
        
        $existing = $this->query("SELECT id FROM receivables WHERE sale_id = ?", [$saleId])->fetch();
        if ($existing) {
            $this->query(
                "UPDATE receivables
                 SET customer_id = ?, invoice_number = ?, total_amount = ?, paid_amount = ?,
                     balance = ?, status = ?, due_date = ?, notes = ?, updated_at = NOW()
                 WHERE sale_id = ?",
                [$customerId, $invoiceNumber, $total, $paid, $balance, $status, $dueDate, $taskNote, $saleId]
            );
            return;
        }
        
        $this->query(
            "INSERT INTO receivables
                (sale_id, customer_id, invoice_number, total_amount, paid_amount, balance, due_date, status, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $saleId,
                $customerId,
                $invoiceNumber,
                $total,
                $paid,
                $balance,
                $dueDate,
                $status,
                $taskNote,
                $_SESSION['tenant_user_id'] ?? null,
            ]
        );
    }
    
    public function applyPayment(int $saleId, float $totalPaid, float $saleTotal): void
    {
        $balance = max(0, round($saleTotal - $totalPaid, 2));
        $status = $balance <= 0 ? 'paid' : ($totalPaid > 0 ? 'partial' : 'open');
        
        $this->query(
            "UPDATE receivables
             SET paid_amount = ?, balance = ?, status = ?, updated_at = NOW()
             WHERE sale_id = ? AND status != 'cancelled'",
            [$totalPaid, $balance, $status, $saleId]
        );
    }
    
    public function markPaid(int $saleId, float $total): void
    {
        $this->query(
            "UPDATE receivables
             SET paid_amount = ?, balance = 0, status = 'paid', updated_at = NOW()
             WHERE sale_id = ?",
            [$total, $saleId]
        );
    }
    
    public function cancelBySale(int $saleId): void
    {
        $this->query(
            "UPDATE receivables SET status = 'cancelled', balance = 0, updated_at = NOW() WHERE sale_id = ?",
            [$saleId]
        );
    }
    
    public function listOpen(int $limit = 10): array
    {
        // Fuente de verdad: ventas con saldo pendiente (aunque no exista fila en receivables)
        return $this->query(
            "SELECT
                s.id as sale_id,
                s.invoice_number,
                s.customer_id,
                s.total as total_amount,
                COALESCE(p.paid_amount, 0) as paid_amount,
                GREATEST(s.total - COALESCE(p.paid_amount, 0), 0) as balance,
                COALESCE(r.due_date, DATE_ADD(DATE(s.sale_date), INTERVAL 15 DAY)) as due_date,
                GREATEST(DATEDIFF(CURDATE(), COALESCE(r.due_date, DATE_ADD(DATE(s.sale_date), INTERVAL 15 DAY))), 0) as days_overdue,
                CASE
                    WHEN COALESCE(p.paid_amount, 0) <= 0 THEN 'open'
                    WHEN COALESCE(p.paid_amount, 0) < s.total THEN 'partial'
                    ELSE 'paid'
                END as status,
                COALESCE(
                    NULLIF(TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))), ''),
                    c.name,
                    'Consumidor Final'
                ) as customer_name
             FROM sales s
             LEFT JOIN (
                SELECT sale_id, COALESCE(SUM(amount), 0) as paid_amount
                FROM sale_payments
                GROUP BY sale_id
             ) p ON p.sale_id = s.id
             LEFT JOIN receivables r ON r.sale_id = s.id AND r.status != 'cancelled'
             LEFT JOIN customers c ON c.id = s.customer_id
             WHERE s.payment_status IN ('pending', 'partial')
               AND s.status NOT IN ('cancelled')
               AND (s.total - COALESCE(p.paid_amount, 0)) > 0.009
             ORDER BY due_date ASC, s.sale_date DESC
             LIMIT " . (int)$limit
        )->fetchAll();
    }

    /**
     * Totales reales de cartera (no limitados por listado).
     * @return array{total:float,count:int,overdue_total:float,overdue_count:int}
     */
    public function summary(): array
    {
        $row = $this->query(
            "SELECT
                COALESCE(SUM(bal), 0) total,
                COUNT(*) cnt,
                COALESCE(SUM(CASE WHEN days_overdue > 0 THEN bal ELSE 0 END), 0) overdue_total,
                COALESCE(SUM(CASE WHEN days_overdue > 0 THEN 1 ELSE 0 END), 0) overdue_count
             FROM (
                SELECT GREATEST(s.total - COALESCE(p.paid_amount, 0), 0) bal,
                       GREATEST(DATEDIFF(CURDATE(), COALESCE(r.due_date, DATE_ADD(DATE(s.sale_date), INTERVAL 15 DAY))), 0) days_overdue
                FROM sales s
                LEFT JOIN (
                    SELECT sale_id, COALESCE(SUM(amount), 0) as paid_amount
                    FROM sale_payments GROUP BY sale_id
                ) p ON p.sale_id = s.id
                LEFT JOIN receivables r ON r.sale_id = s.id AND r.status != 'cancelled'
                WHERE s.payment_status IN ('pending', 'partial')
                  AND s.status NOT IN ('cancelled')
                  AND (s.total - COALESCE(p.paid_amount, 0)) > 0.009
             ) x"
        )->fetch() ?: [];

        return [
            'total' => (float)($row['total'] ?? 0),
            'count' => (int)($row['cnt'] ?? 0),
            'overdue_total' => (float)($row['overdue_total'] ?? 0),
            'overdue_count' => (int)($row['overdue_count'] ?? 0),
        ];
    }

    /**
     * Aging por fecha de vencimiento (días vencidos, no desde la venta).
     * @return array{total:float,d0:float,d30:float,d60:float,d90:float,cnt:int,current:float}
     */
    public function agingByDueDate(): array
    {
        $row = $this->query(
            "SELECT
                COALESCE(SUM(bal), 0) total,
                COALESCE(SUM(CASE WHEN days_overdue <= 0 THEN bal ELSE 0 END), 0) current_due,
                COALESCE(SUM(CASE WHEN days_overdue BETWEEN 1 AND 30 THEN bal ELSE 0 END), 0) d0,
                COALESCE(SUM(CASE WHEN days_overdue BETWEEN 31 AND 60 THEN bal ELSE 0 END), 0) d30,
                COALESCE(SUM(CASE WHEN days_overdue BETWEEN 61 AND 90 THEN bal ELSE 0 END), 0) d60,
                COALESCE(SUM(CASE WHEN days_overdue > 90 THEN bal ELSE 0 END), 0) d90,
                COUNT(*) cnt
             FROM (
                SELECT GREATEST(s.total - COALESCE(p.paid_amount, 0), 0) bal,
                       GREATEST(DATEDIFF(CURDATE(), COALESCE(r.due_date, DATE_ADD(DATE(s.sale_date), INTERVAL 15 DAY))), 0) days_overdue
                FROM sales s
                LEFT JOIN (
                    SELECT sale_id, COALESCE(SUM(amount), 0) as paid_amount
                    FROM sale_payments GROUP BY sale_id
                ) p ON p.sale_id = s.id
                LEFT JOIN receivables r ON r.sale_id = s.id AND r.status != 'cancelled'
                WHERE s.payment_status IN ('pending', 'partial')
                  AND s.status NOT IN ('cancelled')
                  AND (s.total - COALESCE(p.paid_amount, 0)) > 0.009
             ) x"
        )->fetch() ?: [];

        return [
            'total' => (float)($row['total'] ?? 0),
            'current' => (float)($row['current_due'] ?? 0),
            'd0' => (float)($row['d0'] ?? 0),
            'd30' => (float)($row['d30'] ?? 0),
            'd60' => (float)($row['d60'] ?? 0),
            'd90' => (float)($row['d90'] ?? 0),
            'cnt' => (int)($row['cnt'] ?? 0),
        ];
    }

    public function updateDueDate(int $saleId, string $dueDate): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            throw new \InvalidArgumentException('Fecha de vencimiento inválida');
        }
        $exists = $this->query("SELECT id FROM receivables WHERE sale_id = ?", [$saleId])->fetch();
        if ($exists) {
            $this->query(
                "UPDATE receivables SET due_date = ?, updated_at = NOW() WHERE sale_id = ?",
                [$dueDate, $saleId]
            );
            return;
        }
        // Crear fila mínima si no existe
        $sale = $this->query(
            "SELECT id, invoice_number, customer_id, total,
                    COALESCE((SELECT SUM(amount) FROM sale_payments WHERE sale_id = s.id), 0) paid
             FROM sales s WHERE id = ?",
            [$saleId]
        )->fetch();
        if (!$sale) {
            throw new \RuntimeException('Venta no encontrada');
        }
        $this->upsertFromSale(
            (int)$sale['id'],
            $sale['customer_id'] ? (int)$sale['customer_id'] : null,
            (string)$sale['invoice_number'],
            (float)$sale['total'],
            (float)$sale['paid']
        );
        $this->query(
            "UPDATE receivables SET due_date = ?, updated_at = NOW() WHERE sale_id = ?",
            [$dueDate, $saleId]
        );
    }

    /**
     * Ultimas ventas ya saldadas (pago completo)
     */
    public function listRecentlyPaid(int $limit = 5): array
    {
        return $this->query(
            "SELECT
                s.id as sale_id,
                s.invoice_number,
                s.total as total_amount,
                s.sale_date,
                s.payment_method,
                COALESCE(
                    NULLIF(TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))), ''),
                    c.name,
                    'Consumidor Final'
                ) as customer_name,
                COALESCE(p.last_payment_at, s.sale_date) as paid_at
             FROM sales s
             LEFT JOIN customers c ON c.id = s.customer_id
             LEFT JOIN (
                SELECT sale_id, MAX(payment_date) as last_payment_at
                FROM sale_payments
                GROUP BY sale_id
             ) p ON p.sale_id = s.id
             WHERE s.payment_status = 'paid'
               AND s.status NOT IN ('cancelled')
             ORDER BY COALESCE(p.last_payment_at, s.sale_date) DESC, s.id DESC
             LIMIT " . (int)$limit
        )->fetchAll();
    }
    
    /**
     * Sincroniza CxC con ventas pendientes/parciales existentes
     */
    public function syncFromOpenSales(): void
    {
        $rows = $this->query(
            "SELECT s.id, s.invoice_number, s.customer_id, s.total, s.notes,
                    COALESCE(p.paid_amount, 0) as paid_amount
             FROM sales s
             LEFT JOIN (
                SELECT sale_id, COALESCE(SUM(amount), 0) as paid_amount
                FROM sale_payments
                GROUP BY sale_id
             ) p ON p.sale_id = s.id
             WHERE s.payment_status IN ('pending', 'partial')
               AND s.status NOT IN ('cancelled')
               AND (s.total - COALESCE(p.paid_amount, 0)) > 0.009"
        )->fetchAll();
        
        foreach ($rows as $row) {
            $this->upsertFromSale(
                (int)$row['id'],
                !empty($row['customer_id']) ? (int)$row['customer_id'] : null,
                (string)$row['invoice_number'],
                (float)$row['total'],
                (float)$row['paid_amount'],
                $row['notes'] ?? null
            );
        }
    }
}
