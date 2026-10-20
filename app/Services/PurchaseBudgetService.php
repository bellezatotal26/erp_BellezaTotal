<?php

namespace SoftNova\Services;

/**
 * Semáforo de compras: OC abiertas + facturas recibidas + facturas leídas por confirmar
 * frente a un tope mensual (prorrateado cuando el rango no es un mes exacto).
 */
class PurchaseBudgetService
{
    public const KEY_LIMIT = 'purchase_monthly_limit';
    public const KEY_WARN_PCT = 'purchase_limit_warn_pct';
    public const DEFAULT_WARN_PCT = 80;

    public function __construct(private \PDO $db)
    {
    }

    public function limit(): float
    {
        return max(0.0, (float)SettingsCache::get($this->db, self::KEY_LIMIT, '0'));
    }

    public function warnPct(): int
    {
        $pct = (int)SettingsCache::get($this->db, self::KEY_WARN_PCT, (string)self::DEFAULT_WARN_PCT);
        return ($pct >= 1 && $pct <= 99) ? $pct : self::DEFAULT_WARN_PCT;
    }

    /**
     * @param string|null $from Y-m-d (por defecto: primer día del mes actual)
     * @param string|null $to   Y-m-d (por defecto: último día del mes actual)
     * @return array<string,mixed>
     */
    public function status(?string $from = null, ?string $to = null): array
    {
        [$from, $to, $isDefault] = self::normalizeRange($from, $to);

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS c, COALESCE(SUM(total), 0) AS t FROM purchase_orders
             WHERE status IN ('draft', 'ordered')
               AND DATE(order_date) BETWEEN ? AND ?"
        );
        $stmt->execute([$from, $to]);
        $orders = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['c' => 0, 't' => 0];

        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS c,
                        COALESCE(SUM(GREATEST(total - COALESCE(credit_total, 0), 0)), 0) AS t,
                        COALESCE(SUM(
                            CASE
                                WHEN COALESCE(commercial_discount_amount, 0) > 0.009
                                    THEN commercial_discount_amount
                                WHEN COALESCE(commercial_discount_pct, 0) > 0.009
                                    THEN ROUND(COALESCE(subtotal, 0) * commercial_discount_pct / 100, 2)
                                ELSE 0
                            END
                        ), 0) AS disc
                 FROM purchase_orders
                 WHERE status = 'received'
                   AND DATE(COALESCE(warehouse_date, invoice_date, order_date)) BETWEEN ? AND ?"
            );
            $stmt->execute([$from, $to]);
            $invoices = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['c' => 0, 't' => 0, 'disc' => 0];
        } catch (\Throwable $e) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS c, COALESCE(SUM(GREATEST(total - COALESCE(credit_total, 0), 0)), 0) AS t
                 FROM purchase_orders
                 WHERE status = 'received'
                   AND DATE(COALESCE(warehouse_date, invoice_date, order_date)) BETWEEN ? AND ?"
            );
            $stmt->execute([$from, $to]);
            $invoices = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['c' => 0, 't' => 0];
            $invoices['disc'] = 0;
        }

        $pending = $this->pendingImports($from, $to);

        $monthlyLimit = $this->limit();
        $limit = round($monthlyLimit * self::monthsFactor($from, $to), 2);
        $warn = $this->warnPct();
        $ordersTotal = round((float)$orders['t'], 2);
        $invoicesTotal = round((float)$invoices['t'], 2);
        $pendingTotal = round($pending['total'], 2);
        $discountsTotal = round((float)($invoices['disc'] ?? 0) + (float)($pending['discounts'] ?? 0), 2);
        $used = round($ordersTotal + $invoicesTotal + $pendingTotal, 2);
        $pct = $limit > 0 ? round($used / $limit * 100, 1) : 0.0;

        if ($limit <= 0) {
            $level = 'none';
            $label = 'Sin límite configurado';
        } elseif ($pct >= 100) {
            $level = 'red';
            $label = $used > $limit ? 'Límite superado' : 'Límite alcanzado';
        } elseif ($pct >= $warn) {
            $level = 'yellow';
            $label = 'Cerca del límite';
        } else {
            $level = 'green';
            $label = 'Dentro del presupuesto';
        }

        return [
            'limit' => $limit,
            'monthly_limit' => $monthlyLimit,
            'prorated' => $monthlyLimit > 0 && abs($limit - $monthlyLimit) > 0.009,
            'warn_pct' => $warn,
            'orders_total' => $ordersTotal,
            'orders_count' => (int)$orders['c'],
            'invoices_total' => $invoicesTotal,
            'invoices_count' => (int)$invoices['c'],
            'pending_total' => $pendingTotal,
            'pending_count' => $pending['count'],
            'discounts_total' => $discountsTotal,
            'used' => $used,
            'available' => round($limit - $used, 2),
            'pct' => $pct,
            'level' => $level,
            'label' => $label,
            'from' => $from,
            'to' => $to,
            'is_default_range' => $isDefault,
            'month_label' => self::rangeLabel($from, $to),
        ];
    }

    /**
     * Facturas leídas (CSV/IA/manual) aún sin confirmar, a su neto a pagar.
     * No cuenta las enlazadas a una OC abierta: esa OC ya suma en el semáforo.
     *
     * @return array{count:int,total:float,discounts:float}
     */
    private function pendingImports(string $from, string $to): array
    {
        $out = ['count' => 0, 'total' => 0.0, 'discounts' => 0.0];
        $dateExpr = 'DATE(COALESCE(i.warehouse_date, i.invoice_date, i.created_at))';
        try {
            $stmt = $this->db->prepare(
                "SELECT i.subtotal, i.tax_total,
                        COALESCE(i.early_payment_discount_pct, 0) early_pct,
                        COALESCE(i.commercial_discount_pct, 0) commercial_pct,
                        COALESCE(i.commercial_discount_amount, 0) commercial_amount
                 FROM purchase_invoice_imports i
                 LEFT JOIN purchase_orders po
                   ON po.id = i.linked_order_id AND po.status IN ('draft', 'ordered')
                 WHERE i.status = 'pending'
                   AND po.id IS NULL
                   AND {$dateExpr} BETWEEN ? AND ?"
            );
            $stmt->execute([$from, $to]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $disc = PurchasingService::resolvePurchaseDiscounts(
                    (float)$row['subtotal'],
                    (float)$row['tax_total'],
                    (float)$row['early_pct'],
                    (float)$row['commercial_pct'],
                    (float)$row['commercial_amount']
                );
                $out['count']++;
                $out['total'] += $disc['total'];
                $out['discounts'] += (float)$disc['commercial_amount'];
            }
        } catch (\Throwable $e) {
            // Instalación sin columnas de descuento / enlace: total bruto.
            try {
                $stmt = $this->db->prepare(
                    "SELECT COUNT(*) c, COALESCE(SUM(i.total), 0) t
                     FROM purchase_invoice_imports i
                     WHERE i.status = 'pending' AND {$dateExpr} BETWEEN ? AND ?"
                );
                $stmt->execute([$from, $to]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['c' => 0, 't' => 0];
                $out = ['count' => (int)$row['c'], 'total' => (float)$row['t'], 'discounts' => 0.0];
            } catch (\Throwable $e2) {
                // Tabla de importación aún no creada.
            }
        }
        return $out;
    }

    /**
     * @return array{0:string,1:string,2:bool} [desde, hasta, es_mes_actual]
     */
    public static function normalizeRange(?string $from, ?string $to): array
    {
        $defFrom = date('Y-m-01');
        $defTo = date('Y-m-t');
        $valid = static function (?string $d): ?string {
            $d = trim((string)$d);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                return null;
            }
            [$y, $m, $day] = array_map('intval', explode('-', $d));
            return checkdate($m, $day, $y) ? $d : null;
        };
        $from = $valid($from) ?? $defFrom;
        $to = $valid($to) ?? $defTo;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to, $from === $defFrom && $to === $defTo];
    }

    /** Fracción de meses del rango (mes completo = 1; 1–15 oct ≈ 0,48). */
    private static function monthsFactor(string $from, string $to): float
    {
        $cursor = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        $factor = 0.0;
        while ($cursor <= $end) {
            $monthEnd = $cursor->modify('last day of this month');
            $sliceEnd = $monthEnd < $end ? $monthEnd : $end;
            $days = (int)$cursor->diff($sliceEnd)->days + 1;
            $factor += $days / (int)$cursor->format('t');
            $cursor = $monthEnd->modify('+1 day');
        }
        return $factor;
    }

    private static function rangeLabel(string $from, string $to): string
    {
        $f = new \DateTimeImmutable($from);
        $t = new \DateTimeImmutable($to);
        if ($f->format('d') === '01' && $t->format('Y-m') === $f->format('Y-m') && $t->format('d') === $t->format('t')) {
            return self::monthLabel((int)$f->format('n'), (int)$f->format('Y'));
        }
        return $f->format('d/m/Y') . ' – ' . $t->format('d/m/Y');
    }

    public function save(float $limit, int $warnPct): void
    {
        if ($limit < 0 || $limit > 999999999999) {
            throw new \RuntimeException('Monto límite inválido');
        }
        if ($warnPct < 1 || $warnPct > 99) {
            throw new \RuntimeException('El porcentaje de alerta debe estar entre 1 y 99');
        }
        $this->upsert(self::KEY_LIMIT, (string)round($limit, 2));
        $this->upsert(self::KEY_WARN_PCT, (string)$warnPct);
        SettingsCache::invalidate();
    }

    private function upsert(string $key, string $value): void
    {
        $stmt = $this->db->prepare("SELECT id FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        if ($stmt->fetchColumn()) {
            $this->db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$value, $key]);
        } else {
            $this->db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $value]);
        }
    }

    private static function monthLabel(int $month, int $year): string
    {
        $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
            'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return ucfirst($months[$month - 1]) . ' ' . $year;
    }
}
