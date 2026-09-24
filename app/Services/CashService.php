<?php

namespace SoftNova\Services;

/**
 * Servicio de caja / movimientos de efectivo para BD tenant
 */
class CashService
{
    private \PDO $db;

    /** Ingresos que anulan un egreso previo (no deben inflar KPI de ingresos/egresos). */
    public const EXPENSE_REVERSAL_TYPES = [
        'expense_cancel',
        'purchase_credit_note',
        'supplier_payment_cancel',
        'purchase_order_cancel',
    ];
    
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
            "SELECT id FROM cash_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1"
        )->fetch();
        
        return $session ?: null;
    }
    
    /**
     * Registrar ingreso o egreso en la caja abierta (si existe).
     *
     * @return bool true si se registró el movimiento
     */
    /**
     * Totales de movimientos de una sesión.
     * Ingresos/egresos mostrados son netos (restan reversiones: gastos eliminados,
     * NC de compra en efectivo, reverso de abonos, etc.).
     *
     * @return array{incomes:float,expenses:float,movements_count:int,reversals:float}
     */
    public function totalsForSession(int $sessionId): array
    {
        if ($sessionId <= 0) {
            return ['incomes' => 0.0, 'expenses' => 0.0, 'movements_count' => 0, 'reversals' => 0.0];
        }
        $revIn = $this->reversalTypesSqlIn();
        $row = $this->query(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS incomes_gross,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expenses_gross,
                COALESCE(SUM(CASE WHEN type = 'income' AND reference_type IN ({$revIn}) THEN amount ELSE 0 END), 0) AS expense_reversals,
                COUNT(*) AS movements_count,
                COALESCE(SUM(CASE WHEN type = 'income' AND reference_type IN ({$revIn}) THEN 1 ELSE 0 END), 0) AS reversal_rows
             FROM cash_movements
             WHERE cash_session_id = ?",
            [$sessionId]
        )->fetch() ?: [];

        return $this->normalizeMovementTotals($row);
    }

    /**
     * @param list<int> $sessionIds
     * @return array{incomes:float,expenses:float,movements_count:int,reversals:float}
     */
    public function totalsForSessions(array $sessionIds): array
    {
        $sessionIds = array_values(array_filter(array_map('intval', $sessionIds), static fn($id) => $id > 0));
        if ($sessionIds === []) {
            return ['incomes' => 0.0, 'expenses' => 0.0, 'movements_count' => 0, 'reversals' => 0.0];
        }
        $in = implode(',', array_fill(0, count($sessionIds), '?'));
        $revIn = $this->reversalTypesSqlIn();
        $row = $this->query(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS incomes_gross,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expenses_gross,
                COALESCE(SUM(CASE WHEN type = 'income' AND reference_type IN ({$revIn}) THEN amount ELSE 0 END), 0) AS expense_reversals,
                COUNT(*) AS movements_count,
                COALESCE(SUM(CASE WHEN type = 'income' AND reference_type IN ({$revIn}) THEN 1 ELSE 0 END), 0) AS reversal_rows
             FROM cash_movements
             WHERE cash_session_id IN ({$in})",
            $sessionIds
        )->fetch() ?: [];

        return $this->normalizeMovementTotals($row);
    }

    /** @return list<string> */
    public static function expenseReversalTypes(): array
    {
        return self::EXPENSE_REVERSAL_TYPES;
    }

    private function reversalTypesSqlIn(): string
    {
        $parts = [];
        foreach (self::EXPENSE_REVERSAL_TYPES as $t) {
            $parts[] = $this->db->quote($t);
        }
        return implode(',', $parts);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{incomes:float,expenses:float,movements_count:int,reversals:float}
     */
    private function normalizeMovementTotals(array $row): array
    {
        $reversals = (float)($row['expense_reversals'] ?? 0);
        $incomesGross = (float)($row['incomes_gross'] ?? 0);
        $expensesGross = (float)($row['expenses_gross'] ?? 0);
        $count = (int)($row['movements_count'] ?? 0);
        $revRows = (int)($row['reversal_rows'] ?? 0);
        // Cada reverso suele emparejar un egreso: no contar el par en el KPI de cantidad.
        $activeCount = max(0, $count - (2 * $revRows));

        return [
            'incomes' => max(0.0, round($incomesGross - $reversals, 2)),
            'expenses' => max(0.0, round($expensesGross - $reversals, 2)),
            'movements_count' => $activeCount,
            'reversals' => round($reversals, 2),
        ];
    }

    public function registerMovement(
        float $amount,
        string $description,
        string $type = 'income',
        string $referenceType = 'sale',
        ?int $referenceId = null,
        ?int $sessionId = null
    ): bool {
        if ($amount <= 0) {
            return false;
        }

        if (!in_array($type, ['income', 'expense'], true)) {
            $type = 'income';
        }

        try {
            if ($sessionId === null || $sessionId <= 0) {
                $openSession = $this->getOpenSession();
                if (!$openSession) {
                    return false;
                }
                $sessionId = (int)$openSession['id'];
            } else {
                $open = $this->query(
                    "SELECT id FROM cash_sessions WHERE id = ? AND status = 'open' LIMIT 1",
                    [$sessionId]
                )->fetch();
                if (!$open) {
                    return false;
                }
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
                    [$sessionId, $type, $amount, $description, $referenceType, $referenceId, $userId]
                );
            } else {
                $this->query(
                    "INSERT INTO cash_movements
                        (cash_session_id, type, amount, description, reference_type, reference_id, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())",
                    [$sessionId, $type, $amount, $description, $referenceType, $referenceId]
                );
            }
            return true;
        } catch (\Exception $e) {
            error_log('CashService::registerMovement error: ' . $e->getMessage());
            return false;
        }
    }
    
    public function registerIncome(
        float $amount,
        string $description,
        string $referenceType = 'sale',
        ?int $referenceId = null,
        bool $required = false
    ): bool {
        $ok = $this->registerMovement($amount, $description, 'income', $referenceType, $referenceId);
        if (!$ok && $required) {
            throw new \RuntimeException(
                'La venta se registró pero no se pudo anotar el ingreso en caja. Revise la sesión de caja abierta.'
            );
        }
        return $ok;
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
        if ($expenseId <= 0) {
            return 0.0;
        }

        try {
            $rows = $this->query(
                "SELECT cm.cash_session_id, COALESCE(SUM(cm.amount), 0) AS total
                 FROM cash_movements cm
                 INNER JOIN cash_sessions cs ON cs.id = cm.cash_session_id AND cs.status = 'open'
                 WHERE cm.type = 'expense'
                   AND cm.reference_type = 'expense'
                   AND cm.reference_id = ?
                 GROUP BY cm.cash_session_id",
                [$expenseId]
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('CashService::reverseExpenseMovements: ' . $e->getMessage());
            return 0.0;
        }

        $returned = 0.0;
        foreach ($rows as $row) {
            $sessionId = (int)($row['cash_session_id'] ?? 0);
            $expenseTotal = (float)($row['total'] ?? 0);
            if ($sessionId <= 0 || $expenseTotal <= 0) {
                continue;
            }
            $already = (float)$this->query(
                "SELECT COALESCE(SUM(amount), 0)
                 FROM cash_movements
                 WHERE cash_session_id = ?
                   AND type = 'income'
                   AND reference_type = 'expense_cancel'
                   AND reference_id = ?",
                [$sessionId, $expenseId]
            )->fetchColumn();
            $pending = round($expenseTotal - $already, 2);
            if ($pending <= 0) {
                continue;
            }
            $ok = $this->registerMovement(
                $pending,
                'Reverso gasto eliminado: ' . $description,
                'income',
                'expense_cancel',
                $expenseId,
                $sessionId
            );
            if ($ok) {
                $returned += $pending;
            }
        }

        return round($returned, 2);
    }

    /**
     * Revierte egresos de caja ligados a gastos administrativos (arriendo, IVA, etc.)
     * en la sesión abierta actual.
     *
     * @return array{reversed:int,amount:float}
     */
    public function repairAdministrativeExpenseMovements(): array
    {
        $openSession = $this->getOpenSession();
        if (!$openSession) {
            return ['reversed' => 0, 'amount' => 0.0];
        }
        $sessionId = (int)$openSession['id'];
        $adminCodes = ExpenseCategoryService::ADMINISTRATIVE_CODES;
        $placeholders = implode(',', array_fill(0, count($adminCodes), '?'));

        $acctWhere = ExpenseCategoryService::accountingKindsWhere('ec');
        try {
            $rows = $this->db->prepare(
                "SELECT DISTINCT e.id, e.description
                 FROM expenses e
                 INNER JOIN cash_movements cm
                    ON cm.reference_type = 'expense' AND cm.reference_id = e.id
                   AND cm.type = 'expense' AND cm.cash_session_id = ?
                 LEFT JOIN expense_categories ec ON ec.id = e.category_id
                 WHERE ({$acctWhere})
                    OR ec.code IN ($placeholders)
                    OR LOWER(TRIM(e.category)) IN ('arrendamientos','arriendo')"
            );
            $rows->execute(array_merge([$sessionId], $adminCodes));
            $rows = $rows->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('CashService::repairAdministrativeExpenseMovements: ' . $e->getMessage());
            return ['reversed' => 0, 'amount' => 0.0];
        }

        $reversed = 0;
        $amount = 0.0;
        foreach ($rows as $row) {
            $expenseId = (int)($row['id'] ?? 0);
            if ($expenseId <= 0) {
                continue;
            }
            $returned = $this->reverseExpenseMovements($expenseId, (string)($row['description'] ?? ''));
            if ($returned > 0) {
                $reversed++;
                $amount += $returned;
            }
        }

        return ['reversed' => $reversed, 'amount' => round($amount, 2)];
    }
}
