<?php

namespace SoftNova\Services;

/**
 * Motivos tipificados para vender sin stock (o por debajo de cero).
 * La deuda queda en product_stock_debts y se cubre al reponer inventario.
 */
class StockOversell
{
    public const REASON_CAMBIO = 'cambio';
    public const REASON_PRESTAMO = 'prestamo';
    public const REASON_VENTA_PENDIENTE = 'venta_pendiente';
    public const REASON_OTRO = 'otro';

    /** @return array<string,string> value => etiqueta */
    public static function reasons(): array
    {
        return [
            self::REASON_CAMBIO => 'Cambio',
            self::REASON_PRESTAMO => 'Préstamo',
            self::REASON_VENTA_PENDIENTE => 'Venta pendiente',
            self::REASON_OTRO => 'Otro',
        ];
    }

    public static function label(string $reason): string
    {
        $map = self::reasons();
        return $map[$reason] ?? $reason;
    }

    public static function isValid(string $reason): bool
    {
        return isset(self::reasons()[$reason]);
    }

    /**
     * Normaliza motivo + nota. Exige nota cuando el motivo es "otro".
     *
     * @return array{reason:string,note:string}|null null si no hay motivo (venta normal)
     */
    public static function parse(?string $reason, ?string $note = null): ?array
    {
        $reason = strtolower(trim((string)$reason));
        if ($reason === '' || $reason === '0' || $reason === 'none') {
            return null;
        }
        if (!self::isValid($reason)) {
            throw new \InvalidArgumentException('Motivo de venta sin stock inválido');
        }
        $note = trim(preg_replace('/\s+/', ' ', (string)$note) ?? '');
        if (mb_strlen($note) > 180) {
            $note = mb_substr($note, 0, 180);
        }
        if ($reason === self::REASON_OTRO && $note === '') {
            throw new \InvalidArgumentException('Indique el detalle del motivo "Otro"');
        }
        return ['reason' => $reason, 'note' => $note];
    }

    /** HTML de opciones para selects. */
    public static function optionsHtml(?string $selected = null): string
    {
        $html = '<option value="">Seleccione motivo…</option>';
        foreach (self::reasons() as $value => $label) {
            $sel = $selected === $value ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        return $html;
    }
}
