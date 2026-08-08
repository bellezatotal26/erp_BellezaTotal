<?php

namespace SoftNova\Services;

/**
 * Importador CSV de productos (reutilizable sync/async).
 */
class ProductCsvImporter
{
    public function __construct(private \PDO $db)
    {
        $this->stock = new StockService($db);
    }

    private StockService $stock;

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Un fallo contable no debe abortar la importación de la fila. */
    private function postStockAccounting(int $movementId): void
    {
        if ($movementId <= 0) {
            return;
        }
        try {
            (new AccountingService($this->db))->postStockMovement($movementId);
        } catch (\Throwable $e) {
            error_log('Contabilidad importación CSV, movimiento ' . $movementId . ': ' . $e->getMessage());
        }
    }

    /**
     * Normaliza texto de encabezado: encoding, acentos, basura Excel.
     */
    private function normalizeHeader(string $h): string
    {
        $h = trim($h);
        // Comillas tipográficas / basura invisible
        $h = str_replace(["\xEF\xBB\xBF", "\xC2\xA0", "\xE2\x80\x8B", "\x00"], '', $h);
        $h = trim($h, " \t\n\r\0\x0B\"'`");
        if (function_exists('mb_strtolower')) {
            $h = mb_strtolower($h, 'UTF-8');
        } else {
            $h = strtolower($h);
        }
        $from = ['á', 'à', 'ä', 'â', 'ã', 'é', 'è', 'ë', 'ê', 'í', 'ì', 'ï', 'î', 'ó', 'ò', 'ö', 'ô', 'õ', 'ú', 'ù', 'ü', 'û', 'ñ', 'ç'];
        $to   = ['a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'n', 'c'];
        $h = str_replace($from, $to, $h);
        // Espacios / guiones → guion bajo para comparar
        $h = preg_replace('/[\s\-]+/u', '_', $h) ?? $h;
        $h = preg_replace('/[^a-z0-9_]/', '', $h) ?? $h;
        return $h;
    }

    /** Longitud máxima de products.code (VARCHAR(255)). */
    private const CODE_MAX_LEN = 255;

    /**
     * Detecta y convierte UTF-16 / Latin-1 a UTF-8.
     */
    private function normalizeEncoding(string $raw): string
    {
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }
        // UTF-16 LE / BE BOM
        if (strncmp($raw, "\xFF\xFE", 2) === 0 || strncmp($raw, "\xFE\xFF", 2) === 0) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        // UTF-16 sin BOM (muchos nulls entre letras ASCII)
        if (substr_count($raw, "\x00") > max(10, (int)(strlen($raw) * 0.2))) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        return $raw;
    }

    /**
     * SKU / código de barras: siempre texto. Nunca cast a int/float
     * (pierde precisión en EAN-13 y similares).
     *
     * @return array{0: string, 1: ?string} [código limpio, error o null]
     */
    private function normalizeProductCode(string $raw): array
    {
        $code = trim($raw);
        // Basura típica de Excel / CSV
        $code = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\x00"], '', $code);
        $code = trim($code);
        // Marcador de texto en Excel: '6952872152261
        if (str_starts_with($code, "'")) {
            $code = substr($code, 1);
        }
        // Tab forzado en export para que Excel no convierta a número
        $code = ltrim($code, "\t");
        // Fórmula Excel ="6952872152261"
        if (preg_match('/^=\s*"([^"]*)"\s*$/', $code, $m)) {
            $code = $m[1];
        }
        $code = trim($code, " \t\"'");
        if ($code === '') {
            return ['', null];
        }

        // Notación científica de Excel (6.95287E+12): la precisión YA se perdió.
        // No expandir vía float (genera ceros: 6952870000000). Rechazar la fila.
        if (preg_match('/^[+-]?\d+(?:[.,]\d+)?[eE][+-]?\d+$/', $code)) {
            return [
                '',
                'SKU en notación científica de Excel ("' . $code . '"). '
                . 'Formatee la columna SKU como Texto, vuelva a pegar los códigos '
                . 'completos y exporte de nuevo el CSV.',
            ];
        }

        // Excel a veces deja "6952872152261.0"
        if (preg_match('/^(\d+)\.0+$/', $code, $m)) {
            $code = $m[1];
        }

        if (function_exists('mb_strlen')) {
            if (mb_strlen($code, 'UTF-8') > self::CODE_MAX_LEN) {
                return ['', 'SKU supera ' . self::CODE_MAX_LEN . ' caracteres'];
            }
        } elseif (strlen($code) > self::CODE_MAX_LEN) {
            return ['', 'SKU supera ' . self::CODE_MAX_LEN . ' caracteres'];
        }

        return [$code, null];
    }

    /**
     * Corrige mojibake típico (UÃ‘AS → UÑAS): UTF-8 mal interpretado como Latin-1.
     */
    private function fixMojibake(string $text): string
    {
        if ($text === '' || (!str_contains($text, 'Ã') && !str_contains($text, 'Â'))) {
            return $text;
        }
        $recovered = @mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
        if (
            is_string($recovered)
            && $recovered !== ''
            && mb_check_encoding($recovered, 'UTF-8')
            && !str_contains($recovered, 'Ã')
        ) {
            return $recovered;
        }
        return $text;
    }

    private function detectDelimiter(string $raw): string
    {
        $sample = substr($raw, 0, 4096);
        $firstLine = preg_split("/\r\n|\n|\r/", $sample, 2)[0] ?? $sample;
        $counts = [
            ';' => substr_count($firstLine, ';'),
            ',' => substr_count($firstLine, ','),
            "\t" => substr_count($firstLine, "\t"),
        ];
        arsort($counts);
        $best = array_key_first($counts);
        return ($counts[$best] ?? 0) > 0 ? (string)$best : ';';
    }

    /**
     * Mapea alias de columnas → claves internas.
     *
     * @return array<string, int|null>
     */
    private function mapHeaders(array $header): array
    {
        $map = [
            'sku' => null, 'ean' => null, 'nombre' => null, 'tipo' => null, 'categoria' => null,
            'costo' => null, 'precio' => null, 'stock' => null, 'min' => null,
            'unidad' => null, 'estado' => null, 'ubicacion' => null,
        ];

        $aliases = [
            'sku' => ['sku', 'codigo', 'code', 'cod', 'referencia', 'ref'],
            'ean' => ['ean', 'barcode', 'codigo_barras', 'codigobarras', 'upc', 'gtin'],
            'nombre' => [
                'nombre', 'name', 'producto', 'product', 'descripcion', 'description',
                'product_name', 'nombre_producto', 'articulo', 'item', 'titulo', 'title',
            ],
            'tipo' => ['tipo', 'type', 'product_type', 'clase'],
            'categoria' => ['categoria', 'category', 'cat', 'rubro', 'grupo'],
            'costo' => ['costo', 'cost', 'purchase_price', 'precio_compra', 'coste'],
            'precio' => ['precio', 'price', 'sale_price', 'precio_venta', 'pvp'],
            'stock' => ['stock', 'cantidad', 'qty', 'existencia', 'inventario'],
            'min' => ['min', 'min_stock', 'stock_min', 'minimo', 'stock_minimo'],
            'unidad' => ['unidad', 'unit', 'um', 'medida'],
            'estado' => ['estado', 'status', 'activo'],
            'ubicacion' => ['ubicacion', 'location', 'bodega', 'almacen', 'warehouse', 'local'],
        ];

        foreach ($header as $i => $col) {
            $norm = $this->normalizeHeader((string)$col);
            if ($norm === '') {
                continue;
            }
            $matched = false;
            foreach ($aliases as $key => $names) {
                if (in_array($norm, $names, true) && $map[$key] === null) {
                    $map[$key] = $i;
                    $matched = true;
                    break;
                }
            }
            // Heurística: "Nombre del producto", "Producto/SKU", etc.
            if (!$matched && $map['nombre'] === null) {
                if (
                    str_starts_with($norm, 'nombre')
                    || str_starts_with($norm, 'producto')
                    || str_contains($norm, 'nombre_producto')
                    || str_contains($norm, 'product_name')
                ) {
                    $map['nombre'] = $i;
                }
            }
        }

        // Excel a veces pone "sep=;" como primera línea: reintentar no aplica aquí;
        // si nombre sigue null pero hay ≥2 columnas, usar la 2ª como nombre (formato export).
        if ($map['nombre'] === null && count($header) >= 2 && $map['sku'] !== null) {
            foreach ($header as $i => $_) {
                if ($i !== $map['sku'] && !in_array($i, array_filter($map), true)) {
                    $map['nombre'] = $i;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * @return array{created:int,updated:int,skipped:int,errors:list<string>}
     */
    public function importFromFile(string $path, int $userId = 0): array
    {
        SupplyChainSchema::ensure($this->db);
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw new \RuntimeException('No se pudo leer el CSV');
        }
        $raw = $this->normalizeEncoding($raw);
        if (trim($raw) === '') {
            throw new \RuntimeException('El CSV está vacío');
        }

        $delimiter = $this->detectDelimiter($raw);
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $raw);
        rewind($handle);

        $header = fgetcsv($handle, 0, $delimiter);
        // Excel: primera línea "sep=;"
        if ($header && count($header) === 1 && preg_match('/^sep=/i', trim((string)$header[0]))) {
            $header = fgetcsv($handle, 0, $delimiter);
        }
        if (!$header || count(array_filter($header, static fn ($h) => trim((string)$h) !== '')) < 1) {
            fclose($handle);
            throw new \RuntimeException('CSV inválido: falta encabezado');
        }

        $map = $this->mapHeaders($header);
        if ($map['nombre'] === null) {
            fclose($handle);
            $seen = array_map(fn ($h) => $this->normalizeHeader((string)$h), $header);
            $seen = array_values(array_filter($seen));
            throw new \RuntimeException(
                'CSV inválido: falta columna Nombre (o Producto/Name). Encabezados detectados: '
                . (empty($seen) ? '(vacío)' : implode(', ', $seen))
            );
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $categoryCache = [];
        $rowNum = 1;

        $cell = static function (array $map, array $row, string $key): string {
            $i = $map[$key];
            if ($i === null || !isset($row[$i])) {
                return '';
            }
            return trim((string)$row[$i]);
        };

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNum++;
            if ($row === [null] || (count($row) === 1 && trim((string)$row[0]) === '')) {
                continue;
            }
            $name = $this->fixMojibake($cell($map, $row, 'nombre'));
            if ($name === '') {
                $skipped++;
                $errors[] = "Fila {$rowNum}: nombre obligatorio";
                continue;
            }

            [$sku, $skuError] = $this->normalizeProductCode($cell($map, $row, 'sku'));
            if ($skuError !== null) {
                $skipped++;
                $errors[] = "Fila {$rowNum}: {$skuError}";
                continue;
            }
            [$ean, $eanError] = $this->normalizeProductCode($cell($map, $row, 'ean'));
            if ($eanError !== null) {
                $skipped++;
                $errors[] = "Fila {$rowNum}: EAN — {$eanError}";
                continue;
            }
            if (mb_strlen($ean, 'UTF-8') > 64) {
                $skipped++;
                $errors[] = "Fila {$rowNum}: EAN demasiado largo";
                continue;
            }
            $type = mb_strtolower($cell($map, $row, 'tipo'));
            if ($type !== 'service') {
                $type = 'product';
            }
            $status = mb_strtolower($cell($map, $row, 'estado'));
            if ($status !== 'inactive') {
                $status = 'active';
            }
            $cost = (float)str_replace(',', '.', $cell($map, $row, 'costo') ?: '0');
            $price = (float)str_replace(',', '.', $cell($map, $row, 'precio') ?: '0');
            $stock = $type === 'service' ? 0 : (int)$cell($map, $row, 'stock');
            $minStock = (int)($cell($map, $row, 'min') !== '' ? $cell($map, $row, 'min') : '5');
            $unit = $cell($map, $row, 'unidad') ?: 'UNIDAD';
            $catName = $this->fixMojibake($cell($map, $row, 'categoria'));
            $location = $this->fixMojibake($cell($map, $row, 'ubicacion'));
            if ($location !== '' && mb_strlen($location) > 120) {
                $location = mb_substr($location, 0, 120);
            }
            $locationVal = $location !== '' ? $location : null;
            $eanVal = $ean !== '' ? $ean : null;

            $categoryId = null;
            if ($catName !== '') {
                $catKey = mb_strtolower($catName);
                if (!isset($categoryCache[$catKey])) {
                    $existing = $this->query(
                        "SELECT id FROM categories WHERE LOWER(name) = ? LIMIT 1",
                        [$catKey]
                    )->fetch();
                    if ($existing) {
                        $categoryCache[$catKey] = (int)$existing['id'];
                    } else {
                        try {
                            $this->query("INSERT INTO categories (name, status) VALUES (?, 'active')", [$catName]);
                            $categoryCache[$catKey] = (int)$this->db->lastInsertId();
                        } catch (\Throwable $e) {
                            $categoryCache[$catKey] = null;
                        }
                    }
                }
                $categoryId = $categoryCache[$catKey];
            }

            try {
                $existingProduct = null;
                if ($sku !== '') {
                    $existingProduct = $this->query(
                        "SELECT id, stock FROM products WHERE code = ? LIMIT 1",
                        [$sku]
                    )->fetch();
                }
                if (!$existingProduct && $eanVal) {
                    $existingProduct = $this->query(
                        "SELECT id, stock FROM products WHERE barcode = ? LIMIT 1",
                        [$eanVal]
                    )->fetch();
                }
                if ($existingProduct) {
                    $id = (int)$existingProduct['id'];
                    $oldStock = (int)$existingProduct['stock'];
                    $this->query(
                        "UPDATE products SET name = ?, product_type = ?, category_id = ?,
                                purchase_price = ?, sale_price = ?, stock = ?, min_stock = ?,
                                unit = ?, status = ?, location = COALESCE(?, location),
                                barcode = COALESCE(?, barcode) WHERE id = ?",
                        [$name, $type, $categoryId, $cost, $price, $stock, $minStock, $unit, $status, $locationVal, $eanVal, $id]
                    );
                    if ($type === 'product' && $stock !== $oldStock) {
                        $diff = $stock - $oldStock;
                        $movementId = $this->stock->addMovement(
                            $id,
                            $diff > 0 ? 'in' : 'out',
                            abs($diff),
                            'csv_import',
                            null,
                            ($diff > 0 ? 'Entrada (+): ' : 'Salida (−): ') . 'importación CSV',
                            $userId ?: null,
                            $cost,
                            $stock,
                            null,
                            $diff > 0 ? 'equity' : 'loss'
                        );
                        $this->postStockAccounting($movementId);
                    }
                    $updated++;
                } else {
                    if ($sku === '') {
                        $sku = 'SKU-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                    }
                    $this->query(
                        "INSERT INTO products
                            (code, barcode, name, product_type, category_id, purchase_price, sale_price,
                             stock, min_stock, unit, status, location, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$sku, $eanVal, $name, $type, $categoryId, $cost, $price, $stock, $minStock, $unit, $status, $locationVal, $userId ?: null]
                    );
                    $newId = (int)$this->db->lastInsertId();
                    if ($stock > 0 && $type === 'product') {
                        $movementId = $this->stock->addMovement(
                            $newId,
                            'in',
                            $stock,
                            'csv_import',
                            null,
                            'Stock inicial importación CSV',
                            $userId ?: null,
                            $cost,
                            $stock,
                            null,
                            'equity'
                        );
                        $this->postStockAccounting($movementId);
                    }
                    $created++;
                }
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "Fila {$rowNum}: " . $e->getMessage();
            }
        }
        fclose($handle);

        return compact('created', 'updated', 'skipped', 'errors');
    }
}
