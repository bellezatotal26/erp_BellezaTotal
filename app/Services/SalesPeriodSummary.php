<?php

namespace SoftNova\Services;

/**
 * Resumen de ventas de un rango de fechas: cantidad, total facturado y cómo se pagaron
 * (sale_payments por medio; ventas pagadas sin filas de pago usan sales.payment_method).
 * Incluye ventas a crédito (status 'pending'); excluye anuladas.
 */
final class SalesPeriodSummary
{
    /**
     * @return array{
     *   from:string,to:string,count:int,total:float,discount:float,
     *   by_method:list<array{method:string,label:string,amount:float,count:int}>,
     *   collected:float,pending:float,
     *   cash_other_payable:array{amount:float,count:int}
     * }
     */
    public static function build(\PDO $db, string $from, string $to): array
    {
        $out = [
            'from' => $from,
            'to' => $to,
            'count' => 0,
            'total' => 0.0,
            'discount' => 0.0,
            'by_method' => [],
            'collected' => 0.0,
            'pending' => 0.0,
            'cash_other_payable' => ['amount' => 0.0, 'count' => 0],
        ];
        $range = [$from . ' 00:00:00', $to . ' 23:59:59'];
        $active = "COALESCE(s.status, '') <> 'cancelled' AND s.sale_date BETWEEN ? AND ?";

        // Abonos a proveedores en efectivo desde «otro fondo» (no salieron del cajón).
        $out['cash_other_payable'] = self::cashOtherPayableTotals($db, $from, $to);

        foreach ([
            "SELECT COUNT(*) c, COALESCE(SUM(s.total), 0) t, COALESCE(SUM(s.discount), 0) d FROM sales s WHERE {$active}",
            "SELECT COUNT(*) c, COALESCE(SUM(s.total), 0) t, 0 d FROM sales s WHERE {$active}",
        ] as $sql) {
            try {
                $st = $db->prepare($sql);
                $st->execute($range);
                $row = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
                $out['count'] = (int)($row['c'] ?? 0);
                $out['total'] = round((float)($row['t'] ?? 0), 2);
                $out['discount'] = round((float)($row['d'] ?? 0), 2);
                break;
            } catch (\Throwable $e) {
                continue;
            }
        }
        if ($out['count'] === 0) {
            return $out;
        }

        $methods = [];
        $add = static function (string $method, float $amount, int $count) use (&$methods): void {
            $m = PaymentMethodCatalog::normalize($method !== '' ? $method : 'cash');
            if (!isset($methods[$m])) {
                $methods[$m] = ['amount' => 0.0, 'count' => 0];
            }
            $methods[$m]['amount'] += $amount;
            $methods[$m]['count'] += $count;
        };

        try {
            $st = $db->prepare(
                "SELECT sp.payment_method m, COUNT(DISTINCT sp.sale_id) c, COALESCE(SUM(sp.amount), 0) a
                 FROM sale_payments sp
                 INNER JOIN sales s ON s.id = sp.sale_id
                 WHERE {$active}
                 GROUP BY sp.payment_method"
            );
            $st->execute($range);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
                $add((string)$r['m'], (float)$r['a'], (int)$r['c']);
            }

            $st = $db->prepare(
                "SELECT s.payment_method m, COUNT(*) c, COALESCE(SUM(s.total), 0) a
                 FROM sales s
                 WHERE {$active}
                   AND s.payment_status = 'paid'
                   AND NOT EXISTS (SELECT 1 FROM sale_payments sp WHERE sp.sale_id = s.id)
                 GROUP BY s.payment_method"
            );
            $st->execute($range);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
                $add((string)$r['m'], (float)$r['a'], (int)$r['c']);
            }
        } catch (\Throwable $e) {
            $methods = [];
            try {
                $st = $db->prepare(
                    "SELECT s.payment_method m, COUNT(*) c, COALESCE(SUM(s.total), 0) a
                     FROM sales s WHERE {$active} GROUP BY s.payment_method"
                );
                $st->execute($range);
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
                    $add((string)$r['m'], (float)$r['a'], (int)$r['c']);
                }
            } catch (\Throwable $e2) {
                $methods = [];
            }
        }

        $collected = 0.0;
        foreach ($methods as $m => $v) {
            if (abs($v['amount']) < 0.005) {
                continue;
            }
            $collected += $v['amount'];
            $out['by_method'][] = [
                'method' => (string)$m,
                'label' => PaymentMethodCatalog::label((string)$m, $db),
                'amount' => round($v['amount'], 2),
                'count' => $v['count'],
            ];
        }
        usort($out['by_method'], static fn(array $a, array $b) => $b['amount'] <=> $a['amount']);
        $out['collected'] = round($collected, 2);
        $out['pending'] = round(max(0, $out['total'] - $collected), 2);
        return $out;
    }

    /**
     * Pagos de facturas/CxP en efectivo que NO salieron del cajón (cash_from_drawer = 0).
     * Fallback: notas «Efectivo fuera del cajón» (abonos antiguos / columna faltante).
     *
     * @return array{amount:float,count:int}
     */
    public static function cashOtherPayableTotals(\PDO $db, string $from, string $to): array
    {
        $empty = ['amount' => 0.0, 'count' => 0];
        $queries = [
            "SELECT COUNT(*) AS c, COALESCE(SUM(sp.amount), 0) AS a
             FROM supplier_payments sp
             WHERE sp.status = 'applied'
               AND sp.payment_method = 'cash'
               AND sp.cash_from_drawer = 0
               AND sp.payment_date BETWEEN ? AND ?",
            // Legado sin columna o con flag vacío pero nota de origen.
            "SELECT COUNT(*) AS c, COALESCE(SUM(sp.amount), 0) AS a
             FROM supplier_payments sp
             WHERE sp.status = 'applied'
               AND sp.payment_method = 'cash'
               AND sp.payment_date BETWEEN ? AND ?
               AND (
                    COALESCE(sp.notes, '') LIKE '%Efectivo fuera del cajón%'
                    OR TRIM(COALESCE(sp.cash_source_note, '')) <> ''
               )",
            "SELECT COUNT(*) AS c, COALESCE(SUM(sp.amount), 0) AS a
             FROM supplier_payments sp
             WHERE sp.status = 'applied'
               AND sp.payment_method = 'cash'
               AND sp.payment_date BETWEEN ? AND ?
               AND COALESCE(sp.notes, '') LIKE '%Efectivo fuera del cajón%'",
        ];
        foreach ($queries as $sql) {
            try {
                $st = $db->prepare($sql);
                $st->execute([$from, $to]);
                $row = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
                return [
                    'amount' => round((float)($row['a'] ?? 0), 2),
                    'count' => (int)($row['c'] ?? 0),
                ];
            } catch (\Throwable $e) {
                continue;
            }
        }
        return $empty;
    }

    /**
     * Utilidad bruta del rango (misma regla que el detalle de venta):
     * ingreso = total − domicilio; costo = líneas visibles × costo registrado (o costo del producto).
     *
     * @return array{revenue:float,cost:float,profit:float,margin_pct:float,items_without_cost:int}|null
     */
    public static function profit(\PDO $db, string $from, string $to): ?array
    {
        $range = [$from . ' 00:00:00', $to . ' 23:59:59'];
        $active = "COALESCE(s.status, '') <> 'cancelled' AND s.sale_date BETWEEN ? AND ?";

        $revenue = null;
        foreach ([
            "SELECT COALESCE(SUM(s.total - COALESCE(s.delivery_fee, 0)), 0) r FROM sales s WHERE {$active}",
            "SELECT COALESCE(SUM(s.total), 0) r FROM sales s WHERE {$active}",
        ] as $sql) {
            try {
                $st = $db->prepare($sql);
                $st->execute($range);
                $revenue = max(0.0, (float)$st->fetchColumn());
                break;
            } catch (\Throwable $e) {
                continue;
            }
        }
        if ($revenue === null) {
            return null;
        }

        $costExpr = 'COALESCE(NULLIF(si.unit_cost, 0), p.purchase_price, 0)';
        $base = "FROM sale_items si
                 INNER JOIN sales s ON s.id = si.sale_id
                 LEFT JOIN products p ON p.id = si.product_id
                 WHERE {$active}";
        $row = null;
        foreach ([
            // Las líneas ocultas de un combo no llevan costo propio: el costo va en la línea visible.
            "SELECT COALESCE(SUM(si.quantity * {$costExpr}), 0) cost,
                    SUM(CASE WHEN {$costExpr} <= 0 THEN 1 ELSE 0 END) wc
             {$base} AND COALESCE(si.document_hidden, 0) = 0",
            "SELECT COALESCE(SUM(si.quantity * {$costExpr}), 0) cost,
                    SUM(CASE WHEN {$costExpr} <= 0 THEN 1 ELSE 0 END) wc
             {$base}",
            "SELECT COALESCE(SUM(si.quantity * COALESCE(p.purchase_price, 0)), 0) cost,
                    SUM(CASE WHEN COALESCE(p.purchase_price, 0) <= 0 THEN 1 ELSE 0 END) wc
             {$base}",
        ] as $sql) {
            try {
                $st = $db->prepare($sql);
                $st->execute($range);
                $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
                break;
            } catch (\Throwable $e) {
                $row = null;
            }
        }
        if ($row === null) {
            return null;
        }

        $revenue = round($revenue, 2);
        $cost = round((float)($row['cost'] ?? 0), 2);
        $profit = round($revenue - $cost, 2);
        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $profit,
            'margin_pct' => $revenue > 0.009 ? round(($profit / $revenue) * 100, 1) : 0.0,
            'items_without_cost' => (int)($row['wc'] ?? 0),
        ];
    }
}
