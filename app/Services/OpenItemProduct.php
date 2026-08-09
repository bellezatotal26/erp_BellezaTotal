<?php

namespace SoftNova\Services;

/**
 * Producto comodín / mágico: servicio de catálogo para vender ítems
 * que no están en inventario. El nombre y precio se capturan por línea.
 */
class OpenItemProduct
{
    public const CODE = 'COMODIN';
    public const DEFAULT_NAME = 'Producto comodín';

    /**
     * Crea o reactiva el producto comodín. Idempotente.
     *
     * @return array<string,mixed>
     */
    public static function ensure(\PDO $db): array
    {
        $stmt = $db->prepare(
            "SELECT * FROM products WHERE code = ? LIMIT 1"
        );
        $stmt->execute([self::CODE]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            if (($row['status'] ?? '') !== 'active' || ($row['product_type'] ?? '') !== 'service') {
                $upd = $db->prepare(
                    "UPDATE products
                     SET status = 'active', product_type = 'service', stock = 0,
                         sale_price = 0, purchase_price = 0, min_stock = 0,
                         name = COALESCE(NULLIF(TRIM(name), ''), ?)
                     WHERE id = ?"
                );
                $upd->execute([self::DEFAULT_NAME, (int)$row['id']]);
                $stmt->execute([self::CODE]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: $row;
            }
            return $row;
        }

        $desc = 'Ítem libre: al vender se pide nombre y precio. No descuenta inventario.';
        try {
            $ins = $db->prepare(
                "INSERT INTO products
                    (code, name, product_type, description, purchase_price, sale_price, stock, min_stock, unit, status)
                 VALUES (?, ?, 'service', ?, 0, 0, 0, 0, 'UNIDAD', 'active')"
            );
            $ins->execute([self::CODE, self::DEFAULT_NAME, $desc]);
        } catch (\Throwable $e) {
            // Esquemas con columnas extra / sin unit
            $ins = $db->prepare(
                "INSERT INTO products (code, name, product_type, description, purchase_price, sale_price, stock, status)
                 VALUES (?, ?, 'service', ?, 0, 0, 0, 'active')"
            );
            $ins->execute([self::CODE, self::DEFAULT_NAME, $desc]);
        }
        $id = (int)$db->lastInsertId();
        try {
            $db->prepare("UPDATE products SET notes = ? WHERE id = ?")->execute([
                'Sistema · no eliminar. Usar para ventas puntuales fuera de catálogo.',
                $id,
            ]);
        } catch (\Throwable $e) {
            // ignore
        }
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [
            'id' => $id,
            'code' => self::CODE,
            'name' => self::DEFAULT_NAME,
            'product_type' => 'service',
            'sale_price' => 0,
            'stock' => 0,
            'status' => 'active',
        ];
    }

    public static function isOpenItem(?array $product): bool
    {
        if (!$product) {
            return false;
        }
        $code = strtoupper(trim((string)($product['code'] ?? '')));
        return $code === self::CODE;
    }

    public static function isOpenItemId(\PDO $db, int $productId): bool
    {
        if ($productId <= 0) {
            return false;
        }
        $stmt = $db->prepare("SELECT code FROM products WHERE id = ? LIMIT 1");
        $stmt->execute([$productId]);
        $code = strtoupper(trim((string)$stmt->fetchColumn()));
        return $code === self::CODE;
    }

    /**
     * Nombre de línea para comodín (obligatorio).
     */
    public static function sanitizeLineName(?string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', (string)$name) ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('Indique el nombre del producto comodín');
        }
        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 255);
        }
        return $name;
    }

    public static function sanitizeLinePrice($price): float
    {
        $price = round((float)$price, 2);
        if ($price < 0) {
            throw new \InvalidArgumentException('El precio del comodín no puede ser negativo');
        }
        if ($price <= 0) {
            throw new \InvalidArgumentException('Indique el precio del producto comodín');
        }
        return $price;
    }
}
