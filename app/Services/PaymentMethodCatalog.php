<?php

namespace SoftNova\Services;

/**
 * Catálogo central de medios de pago (ventas, abonos, gastos).
 * La opción "Otro" (__other__) no es un medio real: dispara alta permanente.
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
            'nequi' => [
                'label' => 'Nequi',
                'account' => 'bank',
                'affects_cash' => false,
                'group' => 'bank',
            ],
            'mixed' => [
                'label' => 'Pago mixto',
                'account' => 'cash',
                'affects_cash' => false,
                'group' => 'mixed',
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
        if ($method === '' || $method === 'other' || $method === '__other__') {
            return 'cash';
        }
        if (isset(self::all()[$method])) {
            return $method;
        }
        // Medios personalizados (pm_*) u otros códigos alfanuméricos
        if (preg_match('/^[a-z][a-z0-9_]{1,59}$/', $method)) {
            return $method;
        }
        return 'cash';
    }

    public static function label(string $method, ?\PDO $db = null): string
    {
        $method = strtolower(trim($method));
        $all = self::all();
        if (isset($all[$method])) {
            return $all[$method]['label'];
        }
        if ($method === 'inventory_loss') {
            return 'Pérdida inventario';
        }
        if ($method === 'other' || $method === '__other__') {
            return 'Otro';
        }
        if ($db) {
            try {
                $lbl = (new CustomOptionService($db))->label(CustomOptionService::GROUP_PAYMENT_METHOD, $method);
                if ($lbl !== '' && $lbl !== $method) {
                    return $lbl;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        return ucwords(str_replace('_', ' ', preg_replace('/^pm_/', '', $method) ?? $method));
    }

    public static function affectsCash(string $method): bool
    {
        $all = self::all();
        $code = self::normalize($method);
        if (isset($all[$code])) {
            return !empty($all[$code]['affects_cash']);
        }
        return false;
    }

    public static function usesBank(string $method): bool
    {
        $all = self::all();
        $code = self::normalize($method);
        if (isset($all[$code])) {
            return ($all[$code]['account'] ?? 'cash') === 'bank';
        }
        return true;
    }

    /**
     * HTML options para select. Incluye personalizados de DB y "Otro" al final.
     */
    public static function optionsHtml(string $selected = 'cash', bool $includeOther = true, ?\PDO $db = null): string
    {
        $html = '';
        $seen = [];
        foreach (self::all() as $code => $meta) {
            if ($code === 'mixed') {
                // "Pago mixto" no se elige como medio unitario en el select.
                continue;
            }
            $seen[$code] = true;
            $sel = $code === $selected ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($code) . '"' . $sel . '>'
                . htmlspecialchars($meta['label']) . '</option>';
        }
        if ($db) {
            try {
                foreach ((new CustomOptionService($db))->custom(CustomOptionService::GROUP_PAYMENT_METHOD) as $opt) {
                    $code = (string)$opt['value'];
                    if ($code === '' || isset($seen[$code])) {
                        continue;
                    }
                    $seen[$code] = true;
                    $sel = $code === $selected ? ' selected' : '';
                    $html .= '<option value="' . htmlspecialchars($code) . '"' . $sel . '>'
                        . htmlspecialchars((string)$opt['label']) . '</option>';
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        if ($includeOther) {
            $html .= '<option value="__other__">Otro</option>';
        }
        return $html;
    }
}
