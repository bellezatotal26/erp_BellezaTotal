<?php

namespace SoftNova\Services;

/**
 * Servicio de caja / movimientos de efectivo para BD tenant
 */
class CashService
{
    private \PDO $db;
    
    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }
    
    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    public function getOpenSession(): ?array
    {
        $session = $this->query(
            "SELECT id FROM cash_sessions WHERE status = 'open' ORDER BY opening_date DESC LIMIT 1"
        )->fetch();
        
        return $session ?: null;
    }
    
    /**
     * Registrar ingreso o egreso en la caja abierta (si existe)
     */
    public function registerMovement(
        float $amount,
        string $description,
        string $type = 'income',
        string $referenceType = 'sale',
        ?int $referenceId = null
    ): void {
        if ($amount <= 0) {
            return;
        }
        
        if (!in_array($type, ['income', 'expense'], true)) {
            $type = 'income';
        }
        
        try {
            $openSession = $this->getOpenSession();
            if (!$openSession) {
                return;
            }
            
            $userId = $_SESSION['tenant_user_id'] ?? null;
            
            $hasUserId = true;
            try {
                $this->query("SELECT user_id FROM cash_movements LIMIT 0");
            } catch (\Exception $e) {
                $hasUserId = false;
            }
            
            if ($hasUserId && $userId) {
                $this->query(
                    "INSERT INTO cash_movements
                        (cash_session_id, type, amount, description, reference_type, reference_id, user_id, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                    [$openSession['id'], $type, $amount, $description, $referenceType, $referenceId, $userId]
                );
            } else {
                $this->query(
                    "INSERT INTO cash_movements
                        (cash_session_id, type, amount, description, reference_type, reference_id, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())",
                    [$openSession['id'], $type, $amount, $description, $referenceType, $referenceId]
                );
            }
        } catch (\Exception $e) {
            error_log('CashService::registerMovement error: ' . $e->getMessage());
        }
    }
    
    public function registerIncome(
        float $amount,
        string $description,
        string $referenceType = 'sale',
        ?int $referenceId = null
    ): void {
        $this->registerMovement($amount, $description, 'income', $referenceType, $referenceId);
    }
    
    /**
     * Revertir ingresos de una venta cancelada en la caja abierta
     */
    public function reverseSaleMovements(int $saleId, string $invoiceNumber): float
    {
        $openSession = $this->getOpenSession();
        if (!$openSession) {
            return 0.0;
        }

        // Neto de ingresos/egresos ligados a la venta (incluye ajustes de medio de pago).
        $row = $this->query(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS incomes,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expenses
             FROM cash_movements
             WHERE cash_session_id = ?
               AND reference_id = ?
               AND reference_type IN ('sale', 'sale_payment', 'sale_payment_adjust')",
            [$openSession['id'], $saleId]
        )->fetch();

        $net = round((float)($row['incomes'] ?? 0) - (float)($row['expenses'] ?? 0), 2);
        if (abs($net) <= 0.009) {
            return 0.0;
        }

        if ($net > 0) {
            $this->registerMovement(
                $net,
                'Reverso cancelacion venta: ' . $invoiceNumber,
                'expense',
                'sale_cancel',
                $saleId
            );
            return $net;
        }

        $this->registerMovement(
            abs($net),
            'Reverso cancelacion venta (ajuste): ' . $invoiceNumber,
            'income',
            'sale_cancel',
            $saleId
        );
        return $net;
    }

    public function reverseExpenseMovements(int $expenseId, string $description): float
    {
        $openSession = $this->getOpenSession();
        if (!$openSession) {
            return 0.0;
        }
        $total = (float)$this->query(
            "SELECT COALESCE(SUM(amount), 0)
             FROM cash_movements
             WHERE cash_session_id = ? AND type = 'expense'
               AND reference_type = 'expense' AND reference_id = ?",
            [$openSession['id'], $expenseId]
        )->fetchColumn();
        if ($total <= 0) {
            return 0.0;
        }
        $this->registerMovement(
            $total,
            'Reverso gasto eliminado: ' . $description,
            'income',
            'expense_cancel',
            $expenseId
        );
        return $total;
    }
}
