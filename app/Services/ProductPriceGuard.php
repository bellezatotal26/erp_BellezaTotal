<?php

namespace SoftNova\Services;

/**
 * Regla de negocio: el precio de venta debe superar el costo de compra.
 * Evita vender productos mal cargados (p. ej. importación con precios invertidos).
 */
class ProductPriceGuard
{
    /**
     * @return true si se puede vender a ese precio
     */
    public static function saleExceedsCost(float $salePrice, float $purchasePrice): bool
    {
        $sale = round($salePrice, 2);
        $cost = round($purchasePrice, 2);
        if ($cost <= 0.009) {
            return true; // sin costo cargado: no bloquear
        }
        return $sale > $cost;
    }

    public static function message(string $productName, float $salePrice, float $purchasePrice): string
    {
        $name = trim($productName) !== '' ? trim($productName) : 'Este producto';
        $fmt = static function (float $n): string {
            return '$ ' . number_format($n, 2, ',', '.');
        };
        return $name
            . ' no se puede vender: el precio de venta (' . $fmt(round($salePrice, 2))
            . ') no supera el costo (' . $fmt(round($purchasePrice, 2))
            . '). Corrija el producto en Inventario (posible error de importación).';
    }
}
