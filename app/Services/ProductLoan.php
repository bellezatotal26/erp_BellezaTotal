<?php

namespace SoftNova\Services;

/**
 * Motivos tipificados para salida de inventario por préstamo (no es venta).
 * La devolución / reposición del préstamo es manual (no se cubre al comprar).
 */
class ProductLoan
{
    public const REASON_EMPLEADO = 'prestamo_empleado';
    public const REASON_CLIENTE = 'prestamo_cliente';
    public const REASON_PROVEEDOR = 'prestamo_proveedor';
    public const REASON_MUESTRA = 'muestra';
    public const REASON_OTRO = 'otro';

    public const SOURCE = 'loan';

    /** @return array<string,string> */
    public static function reasons(): array
    {
        return [
            self::REASON_EMPLEADO => 'Préstamo a empleado',
            self::REASON_CLIENTE => 'Préstamo a cliente',
            self::REASON_PROVEEDOR => 'Préstamo a proveedor',
            self::REASON_MUESTRA => 'Muestra / demostración',
            self::REASON_OTRO => 'Otro',
        ];
    }

    public static function label(string $reason): string
    {
        $map = self::reasons();
        if (isset($map[$reason])) {
            return $map[$reason];
        }
        // Compatibilidad con deudas de sobreventa
        return StockOversell::label($reason);
    }

    public static function isValid(string $reason): bool
    {
        return isset(self::reasons()[$reason]);
    }

    /**
     * @return array{reason:string,note:string,borrower:string}
     */
    public static function parse(?string $reason, ?string $note = null, ?string $borrower = null): array
    {
        $reason = strtolower(trim((string)$reason));
        if ($reason === '' || !self::isValid($reason)) {
            throw new \InvalidArgumentException('Seleccione el motivo del préstamo');
        }
        $note = trim(preg_replace('/\s+/', ' ', (string)$note) ?? '');
        if (mb_strlen($note) > 180) {
            $note = mb_substr($note, 0, 180);
        }
        $borrower = trim(preg_replace('/\s+/', ' ', (string)$borrower) ?? '');
        if (mb_strlen($borrower) > 120) {
            $borrower = mb_substr($borrower, 0, 120);
        }
        if ($reason === self::REASON_OTRO && $note === '') {
            throw new \InvalidArgumentException('Indique el detalle del motivo "Otro"');
        }
        if ($borrower === '' && $note === '') {
            throw new \InvalidArgumentException('Indique a quién se presta o una nota con el motivo');
        }
        return ['reason' => $reason, 'note' => $note, 'borrower' => $borrower];
    }

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
