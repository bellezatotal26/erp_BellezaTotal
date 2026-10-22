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
        'supplier_payment_cashfix',
        'purchase_order_cancel',
    ];

    /** Reversos de compras a proveedor (subconjunto de EXPENSE_REVERSAL_TYPES). */
    public const PURCHASE_REVERSAL_TYPES = [
        'purchase_credit_note',
        'supplier_payment_cancel',
        'supplier_payment_cashfix',
        'purchase_order_cancel',
    ];

    /** Egresos de cajón por compras a proveedor (OC de contado y abonos en efectivo). */
    public const PURCHASE_CASH_REF_TYPES = ['purchase_order', 'supplier_payment'];
    
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
    
    public function getOpenSession(?int $userId = null): ?array
    {
        // Preferir la caja abierta del usuario actual (evita registrar cobros en otra sesión).
        if ($userId === null) {
            $userId = (int)($_SESSION['tenant_user_id'] ?? 0);
        }
        if ($userId > 0) {
            $mine = $this->query(
                "SELECT id FROM cash_sessions
                 WHERE status = 'open' AND user_id = ?
                 ORDER BY id DESC LIMIT 1",
                [$userId]
            )->fetch();
            if ($mine) {
                return $mine;
            }
        }

        $session = $this->query(
            "SELECT id FROM cash_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1"
        )->fetch();

        return $session ?: null;
    }

    /**
     * Efectivo esperado en cajón (fuente canónica del arqueo).
     * = apertura + neto de TODOS los movimientos de la sesión + orphans estrictos
     * (ventas cash sin movimiento en NINGUNA sesión).
     */
    public function expectedCashForSession(array $session, ?string $until = null): float
    {
        $sessionId = (int)($session['id'] ?? 0);
        $opening = round((float)($session['opening_amount'] ?? 0), 2);
        if ($sessionId <= 0) {
            return $opening;
        }

        $mov = $this->query(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS incomes,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expenses
             FROM cash_movements
             WHERE cash_session_id = ?",
            [$sessionId]
        )->fetch() ?: ['incomes' => 0, 'expenses' => 0];

        $cash = $opening + (float)$mov['incomes'] - (float)$mov['expenses'];
        $orphans = $this->orphanCashSalesForSession($session, $until);

        return round($cash + $orphans, 2);
    }

    /**
     * Esperado unificado de varias sesiones abiertas (sin doble-contar orphans).
     *
     * @param list<array<string,mixed>> $sessions
     */
    public function expectedCashForSessions(array $sessions): float
    {
        if ($sessions === []) {
            return 0.0;
        }
        $opening = 0.0;
        $ids = [];
        foreach ($sessions as $s) {
            $opening += (float)($s['opening_amount'] ?? 0);
            $id = (int)($s['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        $movNet = 0.0;
        if ($ids !== []) {
            $totals = $this->query(
                "SELECT
                    COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS incomes,
                    COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expenses
                 FROM cash_movements
                 WHERE cash_session_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')',
                $ids
            )->fetch() ?: ['incomes' => 0, 'expenses' => 0];
            $movNet = (float)$totals['incomes'] - (float)$totals['expenses'];
        }

        // Orphans una sola vez, anclados a la apertura más antigua.
        usort($sessions, static fn($a, $b) => strcmp((string)($a['opening_date'] ?? ''), (string)($b['opening_date'] ?? '')));
        $anchor = $sessions[0];
        $anchor['id'] = 0; // forzar orphan "sin sesión" = sin movimiento en ninguna
        $orphans = $this->orphanCashSalesGlobal((string)($anchor['opening_date'] ?? ''));

        return round($opening + $movNet + $orphans, 2);
    }

    /**
     * Ventas cash del periodo sin movimiento de caja en NINGUNA sesión (legacy).
     */
    public function orphanCashSalesForSession(array $session, ?string $until = null): float
    {
        $sessionId = (int)($session['id'] ?? 0);
        $openingDate = (string)($session['opening_date'] ?? '');
        if ($openingDate === '') {
            return 0.0;
        }

        $params = [$openingDate];
        $untilSql = '';
        if ($until) {
            $untilSql = ' AND s.sale_date <= ?';
            $params[] = $until;
        }

        // Cubierta si tiene movimiento sale-linked en CUALQUIER sesión (evita cross-sesión).
        try {
            $orphan = $this->query(
                "SELECT COALESCE(SUM(paid_cash), 0) FROM (
                    SELECT s.id,
                           COALESCE((
                               SELECT SUM(sp.amount)
                               FROM sale_payments sp
                               WHERE sp.sale_id = s.id
                                 AND COALESCE(sp.payment_method, '') IN ('cash', '')
                           ), CASE
                               WHEN COALESCE(s.payment_method, 'cash') IN ('cash', '')
                                    AND s.payment_status = 'paid'
                                    AND COALESCE(s.payment_terms, 'cash') = 'cash'
                                    AND NOT EXISTS (SELECT 1 FROM sale_payments sp2 WHERE sp2.sale_id = s.id)
                               THEN s.total
                               ELSE 0
                           END) AS paid_cash
                    FROM sales s
                    WHERE s.status != 'cancelled'
                      AND s.sale_date >= ?" . $untilSql . "
                      AND NOT EXISTS (
                            SELECT 1 FROM cash_movements cm
                            WHERE cm.reference_type IN ('sale', 'sale_cancel', 'sale_payment_adjust')
                              AND cm.reference_id = s.id
                      )
                      AND NOT EXISTS (
                            SELECT 1 FROM cash_movements cm
                            INNER JOIN sale_payments sp ON sp.id = cm.reference_id
                            WHERE cm.reference_type = 'sale_payment'
                              AND sp.sale_id = s.id
                      )
                 ) orphan",
                $params
            )->fetchColumn();
            return round((float)$orphan, 2);
        } catch (\Throwable $e) {
            error_log('orphanCashSalesForSession: ' . $e->getMessage());
            return 0.0;
        }
    }

    /** Orphans globales desde una fecha (multi-sesión). */
    public function orphanCashSalesGlobal(string $openingDate, ?string $until = null): float
    {
        return $this->orphanCashSalesForSession(
            ['id' => 0, 'opening_date' => $openingDate, 'opening_amount' => 0],
            $until
        );
    }

    /**
     * Registrar ingreso o egreso en la caja abierta (si existe).
     *
     * @return bool true si se registró el movimiento
     */
    /**
     * Totales de movimientos de una sesión.
     * Ingresos/egresos mostrados son netos: un reverso (gasto eliminado, NC/anulación de
     * compra en efectivo, reverso de abono) solo anula su egreso si ambos están en la misma
     * caja. Si el egreso salió en otra caja/día, el reverso es dinero que vuelve al cajón
     * y cuenta como ingreso (unpaired_reversals).
     *
     * @return array{incomes:float,expenses:float,movements_count:int,reversals:float,purchases:float,unpaired_reversals:float,unpaired_purchase_reversals:float}
     */
    public function totalsForSession(int $sessionId): array
    {
        return $this->totalsForSessions([$sessionId]);
    }

    /**
     * @param list<int> $sessionIds
     * @return array{incomes:float,expenses:float,movements_count:int,reversals:float,purchases:float,unpaired_reversals:float,unpaired_purchase_reversals:float}
     */
    public function totalsForSessions(array $sessionIds): array
    {
        $sessionIds = array_values(array_unique(array_filter(array_map('intval', $sessionIds), static fn($id) => $id > 0)));
        if ($sessionIds === []) {
            return $this->normalizeMovementTotals([]);
        }
        $in = implode(',', array_fill(0, count($sessionIds), '?'));
        $purchaseIn = $this->quotedSqlIn(self::PURCHASE_CASH_REF_TYPES);
        $row = $this->query(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS incomes_gross,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expenses_gross,
                COALESCE(SUM(CASE WHEN type = 'expense' AND reference_type IN ({$purchaseIn}) THEN amount ELSE 0 END), 0) AS purchases_gross,
                COUNT(*) AS movements_count
             FROM cash_movements
             WHERE cash_session_id IN ({$in})",
            $sessionIds
        )->fetch() ?: [];

        return $this->normalizeMovementTotals(array_merge($row, $this->reversalSplit($sessionIds)));
    }

    /**
     * Reversos de egreso de las sesiones, separando los que tienen su egreso original
     * en el mismo conjunto de sesiones (se netean) de los que no (ingreso real al cajón).
     *
     * @param list<int> $sessionIds
     * @return array{reversals_total:float,paired_total:float,paired_rows:int,purchase_reversals_total:float,purchase_paired_total:float}
     */
    private function reversalSplit(array $sessionIds): array
    {
        $in = implode(',', array_fill(0, count($sessionIds), '?'));
        $revIn = $this->reversalTypesSqlIn();
        $purchaseRevIn = $this->quotedSqlIn(self::PURCHASE_REVERSAL_TYPES);
        try {
            $rows = $this->query(
                "SELECT x.reference_type IN ({$purchaseRevIn}) AS is_purchase,
                        COALESCE(SUM(x.amount), 0) AS total,
                        COALESCE(SUM(CASE WHEN x.paired = 1 THEN x.amount ELSE 0 END), 0) AS paired_total,
                        COALESCE(SUM(x.paired), 0) AS paired_rows
                 FROM (
                    SELECT r.reference_type, r.amount,
                           CASE WHEN EXISTS (
                               SELECT 1 FROM cash_movements o
                               WHERE o.cash_session_id IN ({$in})
                                 AND o.type = 'expense'
                                 AND (
                                    (r.reference_type = 'expense_cancel' AND o.reference_type = 'expense' AND o.reference_id = r.reference_id)
                                    OR (r.reference_type IN ('supplier_payment_cancel', 'supplier_payment_cashfix') AND o.reference_type = 'supplier_payment' AND o.reference_id = r.reference_id)
                                    OR (r.reference_type = 'purchase_order_cancel' AND o.reference_type = 'purchase_order' AND o.reference_id = r.reference_id)
                                    OR (r.reference_type = 'purchase_credit_note' AND o.reference_type = 'purchase_order'
                                        AND o.reference_id = (SELECT cn.purchase_order_id FROM purchase_credit_notes cn WHERE cn.id = r.reference_id))
                                 )
                           ) THEN 1 ELSE 0 END AS paired
                    FROM cash_movements r
                    WHERE r.cash_session_id IN ({$in})
                      AND r.type = 'income'
                      AND r.reference_type IN ({$revIn})
                 ) x
                 GROUP BY is_purchase",
                array_merge($sessionIds, $sessionIds)
            )->fetchAll() ?: [];
        } catch (\Throwable $e) {
            // Sin tabla de NC de compra: comportamiento legado (todo reverso se netea).
            $rows = $this->query(
                "SELECT reference_type IN ({$purchaseRevIn}) AS is_purchase,
                        COALESCE(SUM(amount), 0) AS total,
                        COALESCE(SUM(amount), 0) AS paired_total,
                        COUNT(*) AS paired_rows
                 FROM cash_movements
                 WHERE cash_session_id IN ({$in})
                   AND type = 'income'
                   AND reference_type IN ({$revIn})
                 GROUP BY is_purchase",
                $sessionIds
            )->fetchAll() ?: [];
        }

        $out = [
            'reversals_total' => 0.0,
            'paired_total' => 0.0,
            'paired_rows' => 0,
            'purchase_reversals_total' => 0.0,
            'purchase_paired_total' => 0.0,
        ];
        foreach ($rows as $r) {
            $total = (float)($r['total'] ?? 0);
            $paired = (float)($r['paired_total'] ?? 0);
            $out['reversals_total'] += $total;
            $out['paired_total'] += $paired;
            $out['paired_rows'] += (int)($r['paired_rows'] ?? 0);
            if ((int)($r['is_purchase'] ?? 0) === 1) {
                $out['purchase_reversals_total'] += $total;
                $out['purchase_paired_total'] += $paired;
            }
        }
        return $out;
    }

    /** @return list<string> */
    public static function expenseReversalTypes(): array
    {
        return self::EXPENSE_REVERSAL_TYPES;
    }

    private function reversalTypesSqlIn(): string
    {
        return $this->quotedSqlIn(self::EXPENSE_REVERSAL_TYPES);
    }

    /** @param list<string> $values */
    private function quotedSqlIn(array $values): string
    {
        $parts = [];
        foreach ($values as $t) {
            $parts[] = $this->db->quote($t);
        }
        return implode(',', $parts);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{incomes:float,expenses:float,movements_count:int,reversals:float,purchases:float,unpaired_reversals:float,unpaired_purchase_reversals:float}
     */
    private function normalizeMovementTotals(array $row): array
    {
        $paired = (float)($row['paired_total'] ?? 0);
        $incomesGross = (float)($row['incomes_gross'] ?? 0);
        $expensesGross = (float)($row['expenses_gross'] ?? 0);
        $purchasesGross = (float)($row['purchases_gross'] ?? 0);
        $count = (int)($row['movements_count'] ?? 0);
        $pairedRows = (int)($row['paired_rows'] ?? 0);
        // Cada reverso emparejado anula un egreso: no contar el par en el KPI de cantidad.
        $activeCount = max(0, $count - (2 * $pairedRows));

        return [
            'incomes' => max(0.0, round($incomesGross - $paired, 2)),
            'expenses' => max(0.0, round($expensesGross - $paired, 2)),
            'movements_count' => $activeCount,
            'reversals' => round($paired, 2),
            // Compras a proveedor pagadas con efectivo del cajón (OC de contado + abonos), netas de anulaciones de la misma caja.
            'purchases' => max(0.0, round($purchasesGross - (float)($row['purchase_paired_total'] ?? 0), 2)),
            // Reversos cuyo egreso salió en otra caja: el dinero regresa a este cajón.
            'unpaired_reversals' => max(0.0, round((float)($row['reversals_total'] ?? 0) - $paired, 2)),
            'unpaired_purchase_reversals' => max(0.0, round(
                (float)($row['purchase_reversals_total'] ?? 0) - (float)($row['purchase_paired_total'] ?? 0),
                2
            )),
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
    
    /** Referencias de movimiento ligadas a una venta (afectan el cajón). */
    public const SALE_CASH_REF_TYPES = ['sale', 'sale_cancel', 'sale_payment', 'sale_payment_adjust'];

    /**
     * Neto de efectivo en cajón por una venta en una sesión (ingresos − egresos).
     * Incluye abonos (sale_payment) aunque las filas de pago se hayan reemplazado:
     * pase $paymentIds conocidos antes de borrarlas.
     *
     * @param list<int> $paymentIds
     */
    public function saleCashNetInSession(int $sessionId, int $saleId, array $paymentIds = []): float
    {
        if ($sessionId <= 0 || $saleId <= 0) {
            return 0.0;
        }

        $paymentIds = array_values(array_unique(array_filter(
            array_map('intval', $paymentIds),
            static fn(int $id): bool => $id > 0
        )));

        try {
            $livePayIds = $this->query(
                'SELECT id FROM sale_payments WHERE sale_id = ?',
                [$saleId]
            )->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            foreach ($livePayIds as $pid) {
                $pid = (int)$pid;
                if ($pid > 0) {
                    $paymentIds[] = $pid;
                }
            }
        } catch (\Throwable $e) {
            // esquema sin sale_payments
        }
        $paymentIds = array_values(array_unique(array_filter($paymentIds, static fn(int $id): bool => $id > 0)));

        $sql = "SELECT
                    COALESCE(SUM(CASE WHEN cm.type = 'income' THEN cm.amount ELSE 0 END), 0) AS incomes,
                    COALESCE(SUM(CASE WHEN cm.type = 'expense' THEN cm.amount ELSE 0 END), 0) AS expenses
                 FROM cash_movements cm
                 WHERE cm.cash_session_id = ?
                   AND (
                        (cm.reference_type IN ('sale', 'sale_cancel', 'sale_payment_adjust')
                         AND cm.reference_id = ?)";
        $params = [$sessionId, $saleId];
        if ($paymentIds !== []) {
            $ph = implode(',', array_fill(0, count($paymentIds), '?'));
            $sql .= " OR (cm.reference_type = 'sale_payment' AND cm.reference_id IN ({$ph}))";
            foreach ($paymentIds as $pid) {
                $params[] = $pid;
            }
        }
        $sql .= ')';

        $row = $this->query($sql, $params)->fetch() ?: [];
        return round((float)($row['incomes'] ?? 0) - (float)($row['expenses'] ?? 0), 2);
    }

    /**
     * Deja el neto de efectivo de la venta en $desiredCash dentro de la caja abierta.
     * cash→transfer: desired=0 (egreso de ajuste). transfer→cash: desired=monto (ingreso).
     */
    public function syncSaleCashInOpenSession(
        int $saleId,
        string $label,
        float $desiredCash,
        array $paymentIdsForNet = []
    ): bool {
        $openSession = $this->getOpenSession();
        if (!$openSession) {
            return false;
        }
        $sessionId = (int)$openSession['id'];
        $desiredCash = round(max(0.0, $desiredCash), 2);
        $current = $this->saleCashNetInSession($sessionId, $saleId, $paymentIdsForNet);
        $delta = round($desiredCash - $current, 2);
        if (abs($delta) < 0.009) {
            return true;
        }
        if ($delta > 0) {
            return $this->registerIncome(
                $delta,
                'Ajuste medio de pago (entra efectivo): ' . $label,
                'sale_payment_adjust',
                $saleId
            );
        }
        return $this->registerMovement(
            abs($delta),
            'Ajuste medio de pago (sale efectivo → otro medio): ' . $label,
            'expense',
            'sale_payment_adjust',
            $saleId
        );
    }

    /**
     * Revertir ingresos de una venta cancelada en la caja abierta
     */
    /**
     * True si la venta movió (o debería mover) efectivo del cajón:
     * pagos en efectivo o movimientos de caja ligados a la venta.
     */
    public function saleAffectsCashDrawer(int $saleId, ?array $sale = null): bool
    {
        if ($saleId <= 0) {
            return false;
        }

        try {
            $mov = (float)$this->query(
                "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END), 0)
                 FROM cash_movements
                 WHERE (reference_type IN ('sale', 'sale_cancel', 'sale_payment_adjust') AND reference_id = ?)
                    OR (reference_type = 'sale_payment' AND reference_id IN (
                        SELECT id FROM sale_payments WHERE sale_id = ?
                    ))",
                [$saleId, $saleId]
            )->fetchColumn();
            if (abs($mov) > 0.009) {
                return true;
            }
        } catch (\Throwable $e) {
            // esquema antiguo sin sale_payments en subconsulta
            try {
                $mov = (float)$this->query(
                    "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END), 0)
                     FROM cash_movements
                     WHERE reference_type IN ('sale', 'sale_cancel', 'sale_payment_adjust')
                       AND reference_id = ?",
                    [$saleId]
                )->fetchColumn();
                if (abs($mov) > 0.009) {
                    return true;
                }
            } catch (\Throwable $e2) {
            }
        }

        try {
            $cashPaid = (float)$this->query(
                "SELECT COALESCE(SUM(amount), 0) FROM sale_payments
                 WHERE sale_id = ? AND payment_method IN ('cash', '')",
                [$saleId]
            )->fetchColumn();
            if ($cashPaid > 0.009) {
                return true;
            }
        } catch (\Throwable $e) {
        }

        if ($sale === null) {
            try {
                $sale = $this->query('SELECT payment_method, paid_amount FROM sales WHERE id = ?', [$saleId])->fetch() ?: null;
            } catch (\Throwable $e) {
                $sale = null;
            }
        }
        if ($sale) {
            $method = (string)($sale['payment_method'] ?? '');
            $paid = (float)($sale['paid_amount'] ?? 0);
            if ($paid > 0.009 && PaymentMethodCatalog::affectsCash($method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exige sesión de caja abierta cuando la operación mueve efectivo físico.
     */
    public function assertOpenSessionForCash(string $actionLabel): void
    {
        if ($this->getOpenSession()) {
            return;
        }
        throw new \RuntimeException(
            'Abra la caja antes de ' . $actionLabel
            . '. Sin sesión abierta el efectivo no se registra en el cajón y los libros quedarían descuadrados.'
        );
    }

    public function reverseSaleMovements(int $saleId, string $invoiceNumber): float
    {
        $openSession = $this->getOpenSession();
        if (!$openSession) {
            return 0.0;
        }

        $payIds = [];
        try {
            $payIds = $this->query(
                'SELECT id FROM sale_payments WHERE sale_id = ?',
                [$saleId]
            )->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            $payIds = [];
        }
        $payIds = array_map('intval', $payIds);

        $net = $this->saleCashNetInSession((int)$openSession['id'], $saleId, $payIds);
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
