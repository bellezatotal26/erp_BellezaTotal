<?php

namespace SoftNova\Services;

/**
 * Parte del domicilio absorbida por la empresa → gasto operacional Transporte.
 */
class DeliveryCompanyExpenseService
{
    public function __construct(private \PDO $db)
    {
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Registra el aporte de la empresa al domicilio como gasto "Transporte".
     * Afecta caja física (egreso) cuando hay sesión de caja abierta.
     *
     * @return int ID del gasto o 0 si no aplica
     */
    public function postForSale(
        int $saleId,
        string $invoiceNumber,
        float $companyAmount,
        ?int $userId = null,
        ?bool $affectCash = null
    ): int {
        $companyAmount = round(max(0, $companyAmount), 2);
        if ($saleId <= 0 || $companyAmount <= 0.009) {
            return 0;
        }

        $cashSvc = new CashService($this->db);
        $hasOpenSession = $cashSvc->getOpenSession() !== null;
        if ($affectCash === null) {
            $affectCash = $hasOpenSession;
        } elseif ($affectCash && !$hasOpenSession) {
            error_log('DeliveryCompanyExpense venta ' . $saleId . ': sin caja abierta, egreso no registrado en cajón');
            $affectCash = false;
        }

        $cats = new ExpenseCategoryService($this->db);
        $cat = $cats->findByCode('transport');
        $categoryId = $cat ? (int)$cat['id'] : null;
        $categoryName = $cat ? (string)$cat['name'] : 'Transporte';
        $description = 'Transporte domicilio venta ' . ($invoiceNumber !== '' ? $invoiceNumber : ('#' . $saleId));
        $notes = 'Aporte empresa al domicilio (venta #' . $saleId . ')';
        $userId = $userId ?: (int)($_SESSION['tenant_user_id'] ?? 0);

        try {
            $this->query(
                "INSERT INTO expenses
                    (description, amount, category, category_id, expense_date, supplier_id,
                     payment_method, receipt_number, notes, user_id)
                 VALUES (?, ?, ?, ?, CURDATE(), NULL, 'cash', NULL, ?, ?)",
                [
                    $description,
                    $companyAmount,
                    $categoryName,
                    $categoryId,
                    $notes,
                    $userId > 0 ? $userId : null,
                ]
            );
            $expenseId = (int)$this->db->lastInsertId();
        } catch (\Throwable $e) {
            error_log('DeliveryCompanyExpense insert: ' . $e->getMessage());
            return 0;
        }

        if ($expenseId <= 0) {
            return 0;
        }

        try {
            (new AccountingService($this->db))->postExpense($expenseId, $affectCash);
        } catch (\Throwable $e) {
            error_log('DeliveryCompanyExpense accounting ' . $expenseId . ': ' . $e->getMessage());
        }

        if ($affectCash) {
            $moved = $cashSvc->registerMovement(
                $companyAmount,
                'Gasto: ' . $description,
                'expense',
                'expense',
                $expenseId
            );
            if (!$moved) {
                error_log('DeliveryCompanyExpense cash ' . $expenseId . ': no se pudo registrar egreso en caja');
            }
        }

        return $expenseId;
    }

    /**
     * Anula el gasto de transporte ligado a una venta (contabilidad + caja).
     *
     * @return int Gastos anulados
     */
    public function reverseForSale(int $saleId, string $reason = 'Venta cancelada'): int
    {
        if ($saleId <= 0) {
            return 0;
        }
        $needle = '%(venta #' . $saleId . ')%';
        try {
            $rows = $this->query(
                "SELECT id, description, amount FROM expenses
                 WHERE notes LIKE ? OR description LIKE ?
                 ORDER BY id DESC",
                [$needle, '%venta #' . $saleId . '%']
            )->fetchAll();
        } catch (\Throwable $e) {
            return 0;
        }

        $n = 0;
        $accounting = new AccountingService($this->db);
        $cash = new CashService($this->db);
        foreach ($rows as $row) {
            $expenseId = (int)$row['id'];
            try {
                $accounting->reverseSource('expense', $expenseId, $reason . ' · transporte domicilio');
            } catch (\Throwable $e) {
                error_log('DeliveryCompanyExpense reverse acct ' . $expenseId . ': ' . $e->getMessage());
            }
            try {
                $cash->reverseExpenseMovements(
                    $expenseId,
                    (string)($row['description'] ?? ('gasto #' . $expenseId))
                );
            } catch (\Throwable $e) {
                error_log('DeliveryCompanyExpense reverse cash ' . $expenseId . ': ' . $e->getMessage());
            }
            try {
                $this->query("DELETE FROM expenses WHERE id = ?", [$expenseId]);
            } catch (\Throwable $e) {
                error_log('DeliveryCompanyExpense reverse row ' . $expenseId . ': ' . $e->getMessage());
            }
            $n++;
        }
        return $n;
    }
}
