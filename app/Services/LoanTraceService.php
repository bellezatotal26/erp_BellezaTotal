<?php

namespace SoftNova\Services;

/**
 * Consulta de préstamos (salida y recibidos) con filtros y trazabilidad de movimientos.
 * Solo lectura: no altera deudas ni inventario.
 */
class LoanTraceService
{
    public const AGING_DAYS = 30;

    private const MOVEMENT_TYPES = ['loan', 'loan_return', 'loan_received', 'loan_received_return', 'loan_received_settle'];

    private \PDO $db;

    /** @var array<string,string> */
    private array $labelCache = [];

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @param array<string,mixed> $in
     * @return array{q:string,type:string,status:string,reason:string,person:string,from:string,to:string}
     */
    public static function normalizeFilters(array $in, string $defaultStatus = 'pending'): array
    {
        $type = strtolower(trim((string)($in['type'] ?? '')));
        if (!in_array($type, [ProductLoan::SOURCE, ProductLoan::SOURCE_RECEIVED], true)) {
            $type = '';
        }
        $status = strtolower(trim((string)($in['status'] ?? '')));
        if (!in_array($status, ['pending', 'partial', 'closed', 'all'], true)) {
            $status = $defaultStatus;
        }
        $date = static function ($v): string {
            $v = trim((string)$v);
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
        };
        $from = $date($in['from'] ?? '');
        $to = $date($in['to'] ?? '');
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [
            'q' => mb_substr(trim((string)($in['q'] ?? '')), 0, 80),
            'type' => $type,
            'status' => $status,
            'reason' => mb_substr(strtolower(trim((string)($in['reason'] ?? ''))), 0, 40),
            'person' => mb_substr(trim((string)($in['person'] ?? '')), 0, 120),
            'from' => $from,
            'to' => $to,
        ];
    }

    public static function loanCode(int $id): string
    {
        return 'PR-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param list<string> $extraSearchCols columnas adicionales para la búsqueda libre
     */
    private function buildWhere(array $f, array &$params, string $dateColumn, array $extraSearchCols = []): string
    {
        $w = ["d.source IN ('loan','loan_received')"];
        if ($f['type'] !== '') {
            $w[] = 'd.source = ?';
            $params[] = $f['type'];
        }
        switch ($f['status']) {
            case 'pending':
                $w[] = "d.status IN ('open','partial') AND d.qty_remaining > 0";
                break;
            case 'partial':
                $w[] = "d.status IN ('open','partial') AND d.qty_remaining > 0 AND d.qty_remaining < d.qty_owed";
                break;
            case 'closed':
                $w[] = "(d.status NOT IN ('open','partial') OR d.qty_remaining <= 0)";
                break;
        }
        if ($f['reason'] !== '') {
            $w[] = 'd.reason = ?';
            $params[] = $f['reason'];
        }
        if ($f['person'] !== '') {
            $w[] = 'd.borrower LIKE ?';
            $params[] = '%' . $f['person'] . '%';
        }
        if ($f['from'] !== '') {
            $w[] = "{$dateColumn} >= ?";
            $params[] = $f['from'] . ' 00:00:00';
        }
        if ($f['to'] !== '') {
            $w[] = "{$dateColumn} <= ?";
            $params[] = $f['to'] . ' 23:59:59';
        }
        if ($f['q'] !== '') {
            $like = '%' . $f['q'] . '%';
            $cols = array_merge(['p.name', 'p.code', 'p.barcode', 'd.borrower', 'd.note'], $extraSearchCols);
            $or = [];
            foreach ($cols as $col) {
                $or[] = "{$col} LIKE ?";
                $params[] = $like;
            }
            if (preg_match('/^(?:PR-?|#)?0*(\d{1,9})$/i', $f['q'], $m)) {
                $or[] = 'd.id = ?';
                $params[] = (int)$m[1];
            }
            $w[] = '(' . implode(' OR ', $or) . ')';
        }
        return implode(' AND ', $w);
    }

    private function reasonLabel(string $source, string $reason): string
    {
        $key = $source . '|' . $reason;
        if (!isset($this->labelCache[$key])) {
            $this->labelCache[$key] = $source === ProductLoan::SOURCE_RECEIVED
                ? ProductLoan::receivedLabel($reason, $this->db)
                : ProductLoan::label($reason, $this->db);
        }
        return $this->labelCache[$key];
    }

    /** @param array<string,mixed> $row */
    private function enrichLoan(array $row): array
    {
        $owed = (int)($row['qty_owed'] ?? 0);
        $remaining = max(0, (int)($row['qty_remaining'] ?? 0));
        $isOpen = in_array((string)($row['status'] ?? ''), ['open', 'partial'], true) && $remaining > 0;
        $returned = max(0, $owed - $remaining);
        $source = (string)($row['source'] ?? ProductLoan::SOURCE);

        $row['code'] = self::loanCode((int)$row['id']);
        $row['reason_label'] = $this->reasonLabel($source, (string)($row['reason'] ?? ''));
        $row['qty_returned'] = $returned;
        $row['is_open'] = $isOpen;
        $row['type_label'] = $source === ProductLoan::SOURCE_RECEIVED ? 'Nos prestaron' : 'Prestamos';
        if (!$isOpen) {
            $row['status_label'] = 'Cerrado';
            $row['status_badge'] = 'success';
        } elseif ($returned > 0) {
            $row['status_label'] = 'Parcial';
            $row['status_badge'] = 'info';
        } else {
            $row['status_label'] = 'Pendiente';
            $row['status_badge'] = 'warning';
        }
        $created = strtotime((string)($row['created_at'] ?? '')) ?: time();
        $end = $isOpen ? time() : (strtotime((string)($row['updated_at'] ?? '')) ?: time());
        $row['days_open'] = max(0, (int)floor(($end - $created) / 86400));
        $row['is_aged'] = $isOpen && $row['days_open'] > self::AGING_DAYS;
        return $row;
    }

    private function loanSelectSql(): string
    {
        return "SELECT d.id, d.product_id, d.qty_owed, d.qty_remaining, d.reason, d.note, d.status, d.source,
                       d.borrower, d.created_at, d.updated_at, d.movement_id,
                       p.name AS product_name, p.code AS product_code, p.barcode AS product_barcode,
                       p.stock AS product_stock, COALESCE(p.ownership,'owned') AS ownership,
                       u.name AS created_by_name
                FROM product_stock_debts d
                JOIN products p ON p.id = d.product_id
                LEFT JOIN stock_movements m0 ON m0.id = d.movement_id
                LEFT JOIN users u ON u.id = m0.user_id";
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function listLoans(array $f, int $limit = 300): array
    {
        $params = [];
        $where = $this->buildWhere($f, $params, 'd.created_at');
        try {
            $total = (int)$this->query(
                "SELECT COUNT(*) FROM product_stock_debts d JOIN products p ON p.id = d.product_id WHERE {$where}",
                $params
            )->fetchColumn();
            $rows = $this->query(
                $this->loanSelectSql() . " WHERE {$where}
                 ORDER BY (d.status IN ('open','partial') AND d.qty_remaining > 0) DESC, d.id DESC
                 LIMIT " . max(1, min(1000, $limit)),
                $params
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return ['rows' => [], 'total' => 0];
        }
        return ['rows' => array_map([$this, 'enrichLoan'], $rows), 'total' => $total];
    }

    /** @param array<string,mixed> $m */
    private function describeMovement(array $m): array
    {
        $ref = (string)($m['reference_type'] ?? '');
        $qty = (int)($m['quantity'] ?? 0);
        $moved = (int)($m['product_id'] ?? 0);
        $orig = (int)($m['loan_product_id'] ?? 0);
        $m['substitution'] = $ref === 'loan_return' && $moved > 0 && $orig > 0 && $moved !== $orig;
        switch ($ref) {
            case 'loan':
                $m['movement_label'] = 'Salida por préstamo';
                $m['movement_badge'] = 'warning';
                $m['qty_signed'] = '−' . $qty;
                break;
            case 'loan_return':
                $m['movement_label'] = $m['substitution'] ? 'Nos devolvieron (otro producto)' : 'Nos devolvieron';
                $m['movement_badge'] = 'success';
                $m['qty_signed'] = '+' . $qty;
                break;
            case 'loan_received':
                $m['movement_label'] = 'Ingreso: nos prestaron';
                $m['movement_badge'] = 'info';
                $m['qty_signed'] = '+' . $qty;
                break;
            case 'loan_received_return':
                $m['movement_label'] = 'Devolvimos al prestamista';
                $m['movement_badge'] = 'success';
                $m['qty_signed'] = '−' . $qty;
                break;
            case 'loan_received_settle':
                $m['movement_label'] = 'Cierre sin devolución física';
                $m['movement_badge'] = 'info';
                $m['qty_signed'] = $qty . ' (sin mover stock)';
                break;
            default:
                $m['movement_label'] = $ref;
                $m['movement_badge'] = 'info';
                $m['qty_signed'] = (string)$qty;
        }
        $m['loan_code'] = self::loanCode((int)($m['debt_id'] ?? 0));
        return $m;
    }

    private function movementSelectSql(): string
    {
        $types = "'" . implode("','", self::MOVEMENT_TYPES) . "'";
        return "SELECT sm.id, sm.product_id, sm.type, sm.quantity, sm.stock_after, sm.reference_type, sm.notes,
                       sm.created_at, d.id AS debt_id, d.source, d.product_id AS loan_product_id, d.borrower,
                       d.reason, d.qty_owed, d.qty_remaining, d.status AS debt_status,
                       mp.name AS product_name, mp.code AS product_code,
                       p.name AS loan_product_name, p.code AS loan_product_code,
                       u.name AS user_name
                FROM stock_movements sm
                JOIN product_stock_debts d
                  ON (d.id = sm.reference_id OR (sm.reference_id IS NULL AND d.movement_id = sm.id))
                JOIN products p ON p.id = d.product_id
                LEFT JOIN products mp ON mp.id = sm.product_id
                LEFT JOIN users u ON u.id = sm.user_id
                WHERE sm.reference_type IN ({$types})
                  AND (
                       (sm.reference_type IN ('loan','loan_return') AND d.source = 'loan')
                    OR (sm.reference_type LIKE 'loan_received%' AND d.source = 'loan_received')
                  )";
    }

    /**
     * Bitácora cronológica de movimientos de préstamos (salidas, ingresos, devoluciones, liquidaciones).
     *
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function movements(array $f, int $limit = 400): array
    {
        $params = [];
        $where = $this->buildWhere($f, $params, 'sm.created_at', ['mp.name', 'mp.code', 'mp.barcode']);
        $base = $this->movementSelectSql() . " AND {$where}";
        try {
            $total = (int)$this->query("SELECT COUNT(*) FROM ({$base}) t", $params)->fetchColumn();
            $rows = $this->query(
                $base . ' ORDER BY sm.created_at DESC, sm.id DESC LIMIT ' . max(1, min(2000, $limit)),
                $params
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return ['rows' => [], 'total' => 0];
        }
        foreach ($rows as &$r) {
            $r = $this->describeMovement($r);
            $r['reason_label'] = $this->reasonLabel((string)$r['source'], (string)($r['reason'] ?? ''));
        }
        unset($r);
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Expediente de un préstamo: datos + línea de tiempo de movimientos.
     *
     * @return array{loan:array<string,mixed>,movements:list<array<string,mixed>>}|null
     */
    public function history(int $debtId): ?array
    {
        if ($debtId <= 0) {
            return null;
        }
        try {
            $row = $this->query(
                $this->loanSelectSql() . " WHERE d.source IN ('loan','loan_received') AND d.id = ? LIMIT 1",
                [$debtId]
            )->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $movs = $this->query(
                $this->movementSelectSql() . ' AND d.id = ? ORDER BY sm.created_at ASC, sm.id ASC',
                [$debtId]
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }
        return [
            'loan' => $this->enrichLoan($row),
            'movements' => array_map([$this, 'describeMovement'], $movs),
        ];
    }

    /**
     * Indicadores de préstamos abiertos.
     *
     * @return array{out_count:int,out_units:int,in_count:int,in_units:int,aged_count:int}
     */
    public function summary(): array
    {
        $out = ['out_count' => 0, 'out_units' => 0, 'in_count' => 0, 'in_units' => 0, 'aged_count' => 0];
        try {
            $row = $this->query(
                "SELECT
                    SUM(source = 'loan') AS out_count,
                    COALESCE(SUM(CASE WHEN source = 'loan' THEN qty_remaining END), 0) AS out_units,
                    SUM(source = 'loan_received') AS in_count,
                    COALESCE(SUM(CASE WHEN source = 'loan_received' THEN qty_remaining END), 0) AS in_units,
                    SUM(created_at < (NOW() - INTERVAL " . self::AGING_DAYS . " DAY)) AS aged_count
                 FROM product_stock_debts
                 WHERE source IN ('loan','loan_received')
                   AND status IN ('open','partial') AND qty_remaining > 0"
            )->fetch(\PDO::FETCH_ASSOC) ?: [];
            foreach ($out as $k => $v) {
                $out[$k] = (int)($row[$k] ?? 0);
            }
        } catch (\Throwable $e) {
            // tabla aún no creada
        }
        return $out;
    }

    /**
     * Opciones para los filtros (motivos usados y personas/empresas registradas).
     *
     * @return array{reasons:array<string,string>,people:list<string>}
     */
    public function filterOptions(): array
    {
        $reasons = [];
        $people = [];
        try {
            $rows = $this->query(
                "SELECT DISTINCT source, reason FROM product_stock_debts
                 WHERE source IN ('loan','loan_received') AND reason <> ''"
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $reason = (string)$r['reason'];
                if (!isset($reasons[$reason])) {
                    $reasons[$reason] = $this->reasonLabel((string)$r['source'], $reason);
                }
            }
            asort($reasons, SORT_NATURAL | SORT_FLAG_CASE);
            $people = $this->query(
                "SELECT DISTINCT TRIM(borrower) AS b FROM product_stock_debts
                 WHERE source IN ('loan','loan_received') AND borrower IS NOT NULL AND TRIM(borrower) <> ''
                 ORDER BY b ASC LIMIT 300"
            )->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            // ignore
        }
        return ['reasons' => $reasons, 'people' => array_map('strval', $people)];
    }
}
