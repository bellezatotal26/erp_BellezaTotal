<?php

namespace SoftNova\Services;

/**
 * Catálogo central de medios de pago (ventas, abonos, gastos).
 */
class PaymentMethodCatalog
{
    /**
     * @return array<string,array{label:string,account:string,affects_cash:bool,group:string}>
     */
    public static function all(): array
    {
        return [
            'cash' => [
                'label' => 'Efectivo',
                'account' => 'cash',
                'affects_cash' => true,
                'group' => 'cash',
            ],
            'transfer' => [
                'label' => 'Transferencia',
                'account' => 'bank',
                'affects_cash' => false,
                'group' => 'bank',
            ],
            'card' => [
                'label' => 'Tarjeta',
                'account' => 'bank',
                'affects_cash' => false,
                'group' => 'bank',
            ],
            'debit_card' => [
                'label' => 'Tarjeta débito',
                'account' => 'bank',
                'affects_cash' => false,
                'group' => 'bank',
            ],
            'credit_card' => [
                'label' => 'Tarjeta crédito',
                'account' => 'bank',
                'affects_cash' => false,
                'group' => 'bank',
            ],
            'dataphone' => [
                'label' => 'Datáfono',
                'account' => 'bank',
                'affects_cash' => false,
                'group' => 'bank',
            ],
            'payment_link' => [
                'label' => 'Link de pago',
                'account' => 'bank',
                'affects_cash' => false,
                'group' => 'bank',
            ],
            'other' => [
                'label' => 'Otro',
                'account' => 'bank',
                'affects_cash' => false,
                'group' => 'other',
            ],
        ];
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    public static function normalize(string $method): string
    {
        $method = strtolower(trim($method));
        return isset(self::all()[$method]) ? $method : 'cash';
    }

    public static function label(string $method): string
    {
        $all = self::all();
        $code = self::normalize($method);
        return $all[$code]['label'] ?? ucfirst($method);
    }

    public static function affectsCash(string $method): bool
    {
        $all = self::all();
        $code = self::normalize($method);
        return !empty($all[$code]['affects_cash']);
    }

    public static function usesBank(string $method): bool
    {
        $all = self::all();
        $code = self::normalize($method);
        return ($all[$code]['account'] ?? 'cash') === 'bank';
    }

    /** HTML options para select */
    public static function optionsHtml(string $selected = 'cash', bool $includeOther = true): string
    {
        $html = '';
        foreach (self::all() as $code => $meta) {
            if (!$includeOther && $code === 'other') {
                continue;
            }
            $sel = $code === $selected ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($code) . '"' . $sel . '>'
                . htmlspecialchars($meta['label']) . '</option>';
        }
        return $html;
    }
}
