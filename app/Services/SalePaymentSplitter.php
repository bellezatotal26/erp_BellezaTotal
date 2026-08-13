<?php

namespace SoftNova\Services;

/**
 * Resuelve cobros de una venta: un solo medio o varios (pago mixto).
 * Contabilidad ya asienta cada fila de sale_payments por separado.
 */
class SalePaymentSplitter
{
    /**
     * @param mixed $raw Lista asociativa/plana de payment_splits
     * @return array{
     *   method:string,
     *   splits:list<array{method:string,amount:float}>,
     *   cash_total:float,
     *   label:string
     * }
     */
    public static function resolve($raw, string $fallbackMethod, float $expectedAmount, ?\PDO $db = null): array
    {
        $expectedAmount = round(max(0, $expectedAmount), 2);
        $splits = self::normalizeList($raw);

        if ($splits === []) {
            $method = PaymentMethodCatalog::normalize($fallbackMethod);
            if ($expectedAmount <= 0.009) {
                return [
                    'method' => $method,
                    'splits' => [],
                    'cash_total' => 0.0,
                    'label' => PaymentMethodCatalog::label($method, $db),
                ];
            }
            $splits = [['method' => $method, 'amount' => $expectedAmount]];
        }

        $merged = [];
        foreach ($splits as $row) {
            $m = $row['method'];
            $a = $row['amount'];
            if (!isset($merged[$m])) {
                $merged[$m] = 0.0;
            }
            $merged[$m] = round($merged[$m] + $a, 2);
        }

        $out = [];
        $cashTotal = 0.0;
        $parts = [];
        foreach ($merged as $method => $amount) {
            if ($amount <= 0.009) {
                continue;
            }
            $amount = round($amount, 2);
            $out[] = ['method' => $method, 'amount' => $amount];
            if (PaymentMethodCatalog::affectsCash($method)) {
                $cashTotal = round($cashTotal + $amount, 2);
            }
            $parts[] = PaymentMethodCatalog::label($method, $db) . ' '
                . number_format($amount, 2, '.', '');
        }

        if ($out === [] && $expectedAmount > 0.009) {
            throw new \InvalidArgumentException('Debe indicar al menos un medio de pago con monto');
        }

        $sum = 0.0;
        foreach ($out as $row) {
            $sum = round($sum + $row['amount'], 2);
        }

        if ($expectedAmount > 0.009 && abs($sum - $expectedAmount) > 0.02) {
            throw new \InvalidArgumentException(
                'La suma de los pagos (' . number_format($sum, 2, '.', '')
                . ') debe igualar el monto a cobrar (' . number_format($expectedAmount, 2, '.', '') . ')'
            );
        }

        $unique = array_values(array_unique(array_column($out, 'method')));
        $primary = count($unique) > 1 ? 'mixed' : ($unique[0] ?? PaymentMethodCatalog::normalize($fallbackMethod));

        return [
            'method' => $primary,
            'splits' => $out,
            'cash_total' => $cashTotal,
            'label' => count($unique) > 1
                ? ('Mixto: ' . implode(' + ', $parts))
                : PaymentMethodCatalog::label($primary, $db),
        ];
    }

    /**
     * Extrae payment_splits de un array POST / payload (incluye claves planas).
     *
     * @param array<string,mixed> $source
     * @return list<array{method:string,amount:float}>
     */
    public static function extractFromArray(array $source): array
    {
        $raw = $source['payment_splits'] ?? null;
        if (!is_array($raw) || $raw === []) {
            $raw = [];
            foreach ($source as $key => $value) {
                if (!is_string($key) || !preg_match('/^payment_splits\[(\d+)\]\[(\w+)\]$/', $key, $m)) {
                    continue;
                }
                $idx = (int)$m[1];
                if (!isset($raw[$idx]) || !is_array($raw[$idx])) {
                    $raw[$idx] = [];
                }
                $raw[$idx][$m[2]] = $value;
            }
        }
        return self::normalizeList($raw);
    }

    /**
     * @param mixed $raw
     * @return list<array{method:string,amount:float}>
     */
    public static function normalizeList($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $method = strtolower(trim((string)($row['method'] ?? $row['payment_method'] ?? '')));
            if ($method === '' || $method === '__other__' || $method === 'other' || $method === 'mixed') {
                continue;
            }
            $method = PaymentMethodCatalog::normalize($method);
            $amount = round(\SoftNova\Core\parse_money($row['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            $out[] = ['method' => $method, 'amount' => $amount];
        }
        return $out;
    }
}
