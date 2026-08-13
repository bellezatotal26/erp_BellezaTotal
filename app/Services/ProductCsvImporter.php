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
        $this->ensureWholesalePriceColumn();
    }

    private function ensureWholesalePriceColumn(): void
    {
        try {
            $col = $this->db->query("SHOW COLUMNS FROM products LIKE 'wholesale_price'")->fetch();
            if (!$col) {
                $this->db->exec(
                    "ALTER TABLE products ADD COLUMN wholesale_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER sale_price"
                );
            }
        } catch (\Throwable $e) {
            // No bloquear importación si no se puede alterar el esquema.
        }
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
     * Detecta y convierte UTF-16 / Latin-1 / Windows-1252 a UTF-8.
     * Elige la interpretación con mejor puntuación (evita corromper UTF-8
     * al forzar Windows-1252 por un solo byte inválido).
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

        $candidates = [];
        if (mb_check_encoding($raw, 'UTF-8')) {
            $candidates[] = $raw;
        } elseif (function_exists('iconv')) {
            $ignored = @iconv('UTF-8', 'UTF-8//IGNORE', $raw);
            if (is_string($ignored) && $ignored !== '') {
                $candidates[] = $ignored;
            }
        }
        foreach (['Windows-1252', 'ISO-8859-1'] as $from) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', $from);
            if (is_string($converted) && $converted !== '') {
                $candidates[] = $converted;
            }
        }
        if ($candidates === []) {
            return $raw;
        }

        $best = $candidates[0];
        $bestScore = $this->encodingQualityScore($best);
        for ($i = 1, $n = count($candidates); $i < $n; $i++) {
            $score = $this->encodingQualityScore($candidates[$i]);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidates[$i];
            }
        }
        return $best;
    }

    /** Heurística: más letras/acentos válidos y menos basura/mojibake gana. */
    private function encodingQualityScore(string $text): int
    {
        $sample = substr($text, 0, 80000);
        $score = 0;
        // U+FFFD en UTF-8
        $score -= substr_count($sample, "\xEF\xBF\xBD") * 100;
        $score -= substr_count($sample, 'Ã') * 8;
        $score -= substr_count($sample, 'Â') * 4;
        if (preg_match_all('/[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]/u', $sample, $m)) {
            $score += count($m[0]);
        }
        if (preg_match_all('/[ÁÉÍÓÚÜÑáéíóúüñ¿¡]/u', $sample, $m)) {
            $score += count($m[0]) * 4;
        }
        if (preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $sample, $m)) {
            $score -= count($m[0]) * 25;
        }
        return $score;
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
        if ($text === '') {
            return $text;
        }
        $text = \SoftNova\Core\utf8_fix($text);
        if (!str_contains($text, 'Ã') && !str_contains($text, 'Â')) {
            return $text;
        }
        foreach (['Windows-1252', 'ISO-8859-1'] as $from) {
            $recovered = @mb_convert_encoding($text, $from, 'UTF-8');
            if (
                is_string($recovered)
                && $recovered !== ''
                && mb_check_encoding($recovered, 'UTF-8')
                && (
                    preg_match('/[ÑñÁÉÍÓÚáéíóúÜü¿¡]/u', $recovered)
                    || substr_count($recovered, 'Ã') < substr_count($text, 'Ã')
                )
            ) {
                return $recovered;
            }
        }
        return $text;
    }

    /**
     * Normaliza un campo de texto del CSV (encoding + basura Excel).
     */
    private function decodeCsvText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        // Bytes Latin-1/Win-1252 colados en un string “UTF-8”
        if (!mb_check_encoding($text, 'UTF-8')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            if (is_string($converted) && $converted !== '') {
                $text = $converted;
            } elseif (function_exists('iconv')) {
                $ignored = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
                if (is_string($ignored)) {
                    $text = $ignored;
                }
            }
        }
        $text = $this->fixMojibake($text);
        $text = str_replace(["\xEF\xBB\xBF", "\xC2\xA0", "\xE2\x80\x8B", "\x00"], ['', ' ', '', ''], $text);
        // Comillas tipográficas de Excel
        $text = str_replace(["\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x98", "\xE2\x80\x99"], ['"', '"', "'", "'"], $text);
        $text = trim($text);
        // Si quedó casi solo basura de encoding, no importar ese nombre
        $letters = preg_match_all('/\p{L}/u', $text) ?: 0;
        $repl = substr_count($text, "\xEF\xBF\xBD") + substr_count($text, '�');
        if ($letters > 0 && $repl > 0 && $repl >= $letters) {
            return '';
        }
        return $text;
    }

    /**
     * Elige el separador que mejor parte la fila de encabezados
     * (Excel ES suele usar ;, Excel EN/Google Sheets suelen usar ,).
     */
    private function detectDelimiter(string $raw): string
    {
        $sample = substr($raw, 0, 8192);
        $lines = preg_split("/\r\n|\n|\r/", $sample) ?: [];
        $headerLine = '';
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            // Excel a veces escribe "sep=;" / "sep=," en la 1ª línea.
            if (preg_match('/^sep=(.)$/i', $line, $m)) {
                return $m[1];
            }
            $headerLine = $line;
            break;
        }
        if ($headerLine === '') {
            return ';';
        }

        $candidates = [';', ',', "\t", '|', ' '];
        $best = ';';
        $bestScore = -1;
        foreach ($candidates as $sep) {
            $parts = str_getcsv($headerLine, $sep);
            if ($parts === false || count($parts) < 2) {
                continue;
            }
            // Evitar partir por espacios si casi no hay alias (nombres de producto largos).
            $alias = $this->headerAliasScore($parts);
            if ($sep === ' ' && $alias < 2) {
                continue;
            }
            $score = $this->headerAliasScore($parts);
            // Preferir más columnas reconocidas; en empate, más columnas totales.
            $score = ($score * 100) + count($parts);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $sep;
            }
        }

        if ($bestScore < 0) {
            // Fallback por conteo simple (comportamiento anterior).
            $counts = [
                ';' => substr_count($headerLine, ';'),
                ',' => substr_count($headerLine, ','),
                "\t" => substr_count($headerLine, "\t"),
                '|' => substr_count($headerLine, '|'),
            ];
            arsort($counts);
            $fallback = array_key_first($counts);
            return ($counts[$fallback] ?? 0) > 0 ? (string)$fallback : ';';
        }

        return $best;
    }

    /**
     * Cuántos encabezados coinciden con alias conocidos del importador.
     *
     * @param list<string|null> $parts
     */
    private function headerAliasScore(array $parts): int
    {
        $score = 0;
        foreach ($parts as $part) {
            if ($this->headerTokenKey((string)$part) !== null) {
                $score++;
            }
        }
        return $score;
    }

    /**
     * Si el token normalizado corresponde a una columna conocida, devuelve su clave.
     */
    private function headerTokenKey(string $raw): ?string
    {
        $norm = $this->normalizeHeader($raw);
        if ($norm === '') {
            return null;
        }
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
            'precio_mayor' => [
                'precio_mayor', 'preciomayor', 'precio_al_mayor', 'precioalmayor',
                'wholesale', 'wholesale_price', 'pmayoreo', 'mayoreo', 'precio_mayoreo',
            ],
            'stock' => ['stock', 'cantidad', 'qty', 'existencia', 'inventario'],
            'min' => ['min', 'min_stock', 'stock_min', 'minimo', 'stock_minimo'],
            'unidad' => ['unidad', 'unit', 'um', 'medida', 'unidade'],
            'estado' => ['estado', 'status', 'activo'],
            'ubicacion' => ['ubicacion', 'location', 'bodega', 'almacen', 'warehouse', 'local'],
            'lote' => ['lote', 'lot', 'lot_code', 'codigo_lote', 'cod_lote', 'remesa', 'batch'],
            'vence' => [
                'vence', 'vencimiento', 'expiry', 'expiry_date', 'fecha_vencimiento',
                'caducidad', 'fecha_caducidad', 'expira', 'expiration',
            ],
            'modo_stock' => ['modo_stock', 'stock_mode', 'modo', 'tipo_stock'],
        ];
        foreach ($aliases as $key => $names) {
            if (in_array($norm, $names, true)) {
                return $key;
            }
        }
        if (
            str_starts_with($norm, 'nombre')
            || str_starts_with($norm, 'producto')
            || str_contains($norm, 'nombre_producto')
            || str_contains($norm, 'product_name')
        ) {
            return 'nombre';
        }
        if (
            str_contains($norm, 'mayor')
            || str_contains($norm, 'mayoreo')
            || str_contains($norm, 'wholesale')
        ) {
            return 'precio_mayor';
        }
        if (str_starts_with($norm, 'precio')) {
            return 'precio';
        }
        if (str_starts_with($norm, 'costo') || str_starts_with($norm, 'coste')) {
            return 'costo';
        }
        if (str_starts_with($norm, 'lote') || str_starts_with($norm, 'remesa') || str_starts_with($norm, 'batch')) {
            return 'lote';
        }
        if (str_starts_with($norm, 'vence') || str_starts_with($norm, 'caduc') || str_contains($norm, 'vencim')) {
            return 'vence';
        }
        return null;
    }

    /**
     * Parte encabezados pegados sin separador (ej. skueannombretipo...).
     *
     * @return list<string>|null
     */
    private function splitGluedHeaders(string $line): ?array
    {
        $s = str_replace('_', '', $this->normalizeHeader($line));
        if ($s === '' || strlen($s) < 6) {
            return null;
        }

        // Solo tokens “simples” (sin compuestos tipo stock_min → stockmin).
        $tokens = [
            'ubicacion', 'categoria', 'existencia', 'descripcion', 'description',
            'inventario', 'referencia', 'producto', 'product', 'barcode',
            'cantidad', 'location', 'category', 'warehouse', 'almacen',
            'unidad', 'estado', 'status', 'nombre', 'precio', 'activo',
            'articulo', 'titulo', 'bodega', 'costo', 'coste', 'stock',
            'minimo', 'codigo', 'price', 'tipo', 'type', 'clase', 'rubro',
            'grupo', 'unit', 'name', 'item', 'ean', 'upc', 'gtin', 'sku',
            'qty', 'pvp', 'cat', 'cod', 'ref', 'min', 'cost', 'local', 'um',
            'preciomayor', 'mayoreo', 'wholesale',
            'vencimiento', 'caducidad', 'lote', 'remesa', 'batch', 'vence',
        ];
        $tokens = array_values(array_unique($tokens));
        usort($tokens, static fn ($a, $b) => strlen($b) <=> strlen($a));

        $parts = $this->matchGluedTokens($s, $tokens);
        return ($parts !== null && count($parts) >= 2) ? $parts : null;
    }

    /**
     * @param list<string> $tokens
     * @return list<string>|null
     */
    private function matchGluedTokens(string $s, array $tokens): ?array
    {
        $len = strlen($s);
        $memo = [];

        $dfs = function (int $pos) use (&$dfs, &$memo, $s, $len, $tokens): ?array {
            if ($pos === $len) {
                return [];
            }
            if (array_key_exists($pos, $memo)) {
                return $memo[$pos];
            }
            foreach ($tokens as $tok) {
                $tlen = strlen($tok);
                if ($tlen > 0 && substr($s, $pos, $tlen) === $tok) {
                    $rest = $dfs($pos + $tlen);
                    if ($rest !== null) {
                        return $memo[$pos] = array_merge([$tok], $rest);
                    }
                }
            }
            return $memo[$pos] = null;
        };

        return $dfs(0);
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
            'costo' => null, 'precio' => null, 'precio_mayor' => null, 'stock' => null, 'min' => null,
            'unidad' => null, 'estado' => null, 'ubicacion' => null,
            'lote' => null, 'vence' => null, 'modo_stock' => null,
        ];

        foreach ($header as $i => $col) {
            $key = $this->headerTokenKey((string)$col);
            if ($key === null || !array_key_exists($key, $map)) {
                continue;
            }
            if ($map[$key] === null) {
                $map[$key] = $i;
            }
        }

        // Si hay SKU pero no nombre, tomar la primera columna libre como nombre.
        if ($map['nombre'] === null && count($header) >= 2 && $map['sku'] !== null) {
            $used = array_filter($map, static fn ($v) => $v !== null);
            foreach ($header as $i => $_) {
                if (!in_array($i, $used, true)) {
                    $map['nombre'] = $i;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Abre el CSV con el separador que mejor reconoce el encabezado.
     *
     * @return array{0:resource,1:list<string>,2:array<string,int|null>,3:string}
     */
    private function openCsvWithBestDelimiter(string $raw): array
    {
        $preferred = $this->detectDelimiter($raw);
        $candidates = array_values(array_unique([$preferred, ';', ',', "\t", '|', ' ']));

        $bestHandle = null;
        $bestHeader = [];
        $bestMap = [];
        $bestSep = $preferred;
        $bestScore = -1;
        $lastHeader = [];
        $lastSep = $preferred;
        $lastPreview = $this->firstContentLinePreview($raw);

        foreach ($candidates as $sep) {
            [$handle, $header] = $this->readCsvHeader($raw, $sep);
            if ($handle === null || $header === []) {
                continue;
            }

            // Encabezado en 1 celda: intentar reparar por separador o por tokens pegados.
            if (count($header) === 1) {
                $cell = (string)$header[0];
                $repairSep = $this->inferSeparatorFromLine($cell);
                if ($repairSep !== null) {
                    fclose($handle);
                    [$handle, $header] = $this->readCsvHeader($raw, $repairSep);
                    $sep = $repairSep;
                    if ($handle === null || $header === [] || count($header) < 2) {
                        if (is_resource($handle)) {
                            fclose($handle);
                        }
                        continue;
                    }
                } else {
                    $unglued = $this->splitGluedHeaders($cell);
                    if ($unglued !== null) {
                        $header = $unglued;
                        // Sin separador real en el archivo: no se pueden leer filas útiles.
                        // Aun así dejamos el handle para fallar con mensaje de Nombre si aplica,
                        // o exigir nombre en map.
                    } else {
                        // Una sola columna válida (solo "Nombre") también se acepta.
                        $probe = $this->mapHeaders($header);
                        if ($probe['nombre'] === null) {
                            if (is_resource($handle)) {
                                fclose($handle);
                            }
                            continue;
                        }
                    }
                }
            }

            $map = $this->mapHeaders($header);
            $score = $this->headerAliasScore($header);
            if ($map['nombre'] !== null) {
                $score += 50;
            }
            if ($map['sku'] !== null) {
                $score += 10;
            }
            $score += min(20, count($header));

            $lastHeader = $header;
            $lastSep = $sep;

            if ($score > $bestScore) {
                if (is_resource($bestHandle)) {
                    fclose($bestHandle);
                }
                $bestScore = $score;
                $bestHandle = $handle;
                $bestHeader = $header;
                $bestMap = $map;
                $bestSep = $sep;
            } else {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }

        if (!is_resource($bestHandle) || $bestHeader === []) {
            throw new \RuntimeException(
                'CSV inválido: falta encabezado. '
                . 'Use Exportar CSV como plantilla (SKU;EAN;Nombre;...). '
                . 'Primera línea leída: ' . $lastPreview
            );
        }

        if (($bestMap['nombre'] ?? null) === null) {
            fclose($bestHandle);
            $rawCols = array_map(static fn ($h) => trim((string)$h), $lastHeader);
            $seen = array_values(array_filter(array_map(fn ($h) => $this->normalizeHeader((string)$h), $lastHeader)));
            throw new \RuntimeException(
                'CSV inválido: falta columna Nombre (o Producto/Name). Encabezados detectados: '
                . (empty($seen) ? '(vacío)' : implode(', ', $seen))
                . ' · Separador: ' . ($lastSep === "\t" ? 'TAB' : $lastSep)
                . ' · Vista: ' . mb_substr(implode(' | ', $rawCols), 0, 180)
            );
        }

        // Encabezado pegado sin separadores reales → avisar claro.
        if (count($bestHeader) >= 2 && $this->looksLikeGluedOnlyFile($raw, $bestSep)) {
            fclose($bestHandle);
            throw new \RuntimeException(
                'CSV inválido: el archivo no tiene separadores entre columnas. '
                . 'Guárdelo desde Excel como CSV (delimitado por punto y coma) o use Exportar CSV.'
            );
        }

        return [$bestHandle, $bestHeader, $bestMap, $bestSep];
    }

    private function firstContentLinePreview(string $raw): string
    {
        $lines = preg_split("/\r\n|\n|\r/", substr($raw, 0, 4096)) ?: [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || preg_match('/^sep=/i', $line)) {
                continue;
            }
            return mb_substr($line, 0, 160);
        }
        return '(vacía)';
    }

    /**
     * True si tras el encabezado las filas siguen sin el separador elegido
     * (caso típico de encabezado “despegado” artificialmente).
     */
    private function looksLikeGluedOnlyFile(string $raw, string $sep): bool
    {
        if ($sep === ' ') {
            return false;
        }
        if ($sep !== '' && str_contains($raw, $sep)) {
            return false;
        }
        // El sep elegido no aparece en el archivo: encabezado fue unglue artificial.
        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $dataLines = 0;
        foreach ($lines as $i => $line) {
            $line = trim((string)$line);
            if ($i === 0 || $line === '' || preg_match('/^sep=/i', $line)) {
                continue;
            }
            $dataLines++;
            if ($dataLines >= 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{0:?resource,1:list<string>}
     */
    private function readCsvHeader(string $raw, string $sep): array
    {
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            return [null, []];
        }
        fwrite($handle, $raw);
        rewind($handle);

        while (($row = fgetcsv($handle, 0, $sep)) !== false) {
            if ($row === [null] || (count($row) === 1 && trim((string)$row[0]) === '')) {
                continue;
            }
            // Excel escribe "sep=;" — con delimitador ";" queda ["sep=", ""].
            if ($this->isExcelSepHintRow($row)) {
                continue;
            }
            $header = array_map(static fn ($p) => trim((string)$p), $row);
            return [$handle, $header];
        }

        fclose($handle);
        return [null, []];
    }

    /**
     * @param list<string|null> $row
     */
    private function isExcelSepHintRow(array $row): bool
    {
        $compact = strtolower(preg_replace('/\s+/', '', implode('', array_map(static fn ($v) => (string)$v, $row))) ?? '');
        if (preg_match('/^sep=.?$/', $compact)) {
            return true;
        }
        $first = trim((string)($row[0] ?? ''));
        return count($row) === 1 && preg_match('/^sep=/i', $first) === 1;
    }

    private function inferSeparatorFromLine(string $line): ?string
    {
        $best = null;
        $bestScore = -1;
        foreach ([';', ',', "\t", '|', ' '] as $sep) {
            if ($sep !== ' ' && !str_contains($line, $sep)) {
                continue;
            }
            if ($sep === ' ' && !preg_match('/\s/', $line)) {
                continue;
            }
            $parts = str_getcsv($line, $sep);
            if (!is_array($parts) || count($parts) < 2) {
                continue;
            }
            $alias = $this->headerAliasScore($parts);
            if ($sep === ' ' && $alias < 2) {
                continue;
            }
            // Preferir alias conocidos; si no hay, aún así aceptar por # de columnas.
            $score = ($alias * 100) + count($parts);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $sep;
            }
        }
        return $best;
    }

    /**
     * @return array{
     *   created:int,updated:int,skipped:int,remesas:int,movements:int,
     *   errors:list<string>
     * }
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

        [$handle, $header, $map, $delimiter] = $this->openCsvWithBestDelimiter($raw);
        unset($header);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $remesas = 0;
        $movements = 0;
        $errors = [];
        $categoryCache = [];
        $touchedIds = [];
        $rowNum = 1;

        $cell = static function (array $map, array $row, string $key): string {
            $i = $map[$key] ?? null;
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
            $name = $this->decodeCsvText($cell($map, $row, 'nombre'));
            if ($name === '') {
                $skipped++;
                $errors[] = "Fila {$rowNum}: nombre obligatorio o ilegible (revise que el CSV esté en UTF-8 o Windows-1252)";
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
            $cost = $this->parseMoneyCell($cell($map, $row, 'costo'));
            $price = $this->parseMoneyCell($cell($map, $row, 'precio'));
            $wholesale = $this->parseMoneyCell($cell($map, $row, 'precio_mayor'));
            $stockRaw = $cell($map, $row, 'stock');
            $stockSpecified = $stockRaw !== '';
            $stockQty = $type === 'service' ? 0 : $this->parseQtyCell($stockRaw);
            if ($stockQty < 0) {
                $stockQty = 0;
            }
            $minStock = (int)($cell($map, $row, 'min') !== '' ? $cell($map, $row, 'min') : '5');
            $unit = $cell($map, $row, 'unidad') ?: 'UND';
            if (mb_strlen($unit) > 20) {
                $unit = mb_substr($unit, 0, 20);
            }
            $catName = $this->decodeCsvText($cell($map, $row, 'categoria'));
            $location = $this->decodeCsvText($cell($map, $row, 'ubicacion'));
            if ($location !== '' && mb_strlen($location) > 120) {
                $location = mb_substr($location, 0, 120);
            }
            $locationVal = $location !== '' ? $location : null;
            $eanVal = $ean !== '' ? $ean : null;
            $lotCode = substr(trim($cell($map, $row, 'lote')), 0, 60);
            $expiryDate = $this->parseExpiryDate($cell($map, $row, 'vence'));
            $hasLotData = $lotCode !== '' || $expiryDate !== null;
            $stockMode = $this->resolveStockMode($cell($map, $row, 'modo_stock'), $hasLotData);

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
                        "SELECT id, stock, COALESCE(track_lots, 0) AS track_lots, purchase_price
                         FROM products WHERE code = ? LIMIT 1",
                        [$sku]
                    )->fetch();
                }
                if (!$existingProduct && $eanVal) {
                    $existingProduct = $this->query(
                        "SELECT id, stock, COALESCE(track_lots, 0) AS track_lots, purchase_price
                         FROM products WHERE barcode = ? LIMIT 1",
                        [$eanVal]
                    )->fetch();
                }

                if ($existingProduct) {
                    $id = (int)$existingProduct['id'];

                    // Stock/remesa antes del UPDATE de estado (decrease exige producto activo).
                    if ($type === 'product' && $stockSpecified) {
                        $stats = $this->applyCsvStockChange(
                            $id,
                            $stockQty,
                            $stockMode,
                            $cost,
                            $userId,
                            $lotCode,
                            $expiryDate,
                            $hasLotData,
                            'Importación CSV · producto existente'
                        );
                        $movements += $stats['movements'];
                        $remesas += $stats['remesas'];
                    }

                    // Ficha: no tocar stock aquí; el kardex/lotes ya lo sincronizaron.
                    $this->query(
                        "UPDATE products SET name = ?, product_type = ?, category_id = ?,
                                purchase_price = ?, sale_price = ?, wholesale_price = ?, min_stock = ?,
                                unit = ?, status = ?, location = COALESCE(?, location),
                                barcode = COALESCE(?, barcode) WHERE id = ?",
                        [$name, $type, $categoryId, $cost, $price, $wholesale, $minStock, $unit, $status, $locationVal, $eanVal, $id]
                    );

                    $touchedIds[$id] = true;
                    $updated++;
                } else {
                    if ($sku === '') {
                        $sku = 'SKU-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                    }
                    $this->db->beginTransaction();
                    try {
                        // Alta con stock 0; la entrada va por increase (trazabilidad + lote).
                        $this->query(
                            "INSERT INTO products
                                (code, barcode, name, product_type, category_id, purchase_price, sale_price, wholesale_price,
                                 stock, min_stock, unit, status, location, track_lots, expiry_alert_days, created_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 30, ?)",
                            [
                                $sku,
                                $eanVal,
                                $name,
                                $type,
                                $categoryId,
                                $cost,
                                $price,
                                $wholesale,
                                $minStock,
                                $unit,
                                $status,
                                $locationVal,
                                ($type === 'product' && $hasLotData) ? 1 : 0,
                                $userId ?: null,
                            ]
                        );
                        $newId = (int)$this->db->lastInsertId();
                        if ($type === 'product' && $stockQty > 0) {
                            $stats = $this->applyCsvStockChange(
                                $newId,
                                $stockQty,
                                'entrada',
                                $cost,
                                $userId,
                                $lotCode,
                                $expiryDate,
                                $hasLotData,
                                'Alta de producto por importación CSV'
                            );
                            $movements += $stats['movements'];
                            $remesas += $stats['remesas'];
                        }
                        $this->db->commit();
                        $touchedIds[$newId] = true;
                        $created++;
                    } catch (\Throwable $e) {
                        if ($this->db->inTransaction()) {
                            $this->db->rollBack();
                        }
                        throw $e;
                    }
                }
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "Fila {$rowNum}: " . $e->getMessage();
            }
        }
        fclose($handle);

        // Cuadre final: productos con lotes y saldo sin remesa (p.ej. sync absoluto previo).
        foreach (array_keys($touchedIds) as $pid) {
            try {
                $lots = $this->stock->lots();
                if ($lots->tracksLots((int)$pid)) {
                    $seeded = $lots->seedOpeningLot((int)$pid);
                    if ($seeded > 0) {
                        $remesas++;
                    }
                }
            } catch (\Throwable $e) {
                // no abortar import
            }
        }

        return compact('created', 'updated', 'skipped', 'remesas', 'movements', 'errors');
    }

    /**
     * Aplica cambio de stock del CSV con kardex + lotes.
     * - modo entrada/remesa: suma cantidad (como compra)
     * - modo absoluto: lleva el stock al valor indicado
     *
     * @return array{movements:int,remesas:int}
     */
    private function applyCsvStockChange(
        int $productId,
        int $qtyOrTarget,
        string $mode,
        float $unitCost,
        int $userId,
        string $lotCode,
        ?string $expiryDate,
        bool $hasLotData,
        string $detail
    ): array {
        $out = ['movements' => 0, 'remesas' => 0];
        if ($productId <= 0) {
            return $out;
        }

        $lotMeta = null;
        if ($hasLotData || $mode === 'entrada') {
            $lotMeta = [
                'lot_code' => $lotCode,
                'expiry_date' => $expiryDate,
                'alert_days' => 30,
                'notes' => $detail,
            ];
        }

        if ($mode === 'entrada') {
            if ($qtyOrTarget <= 0) {
                return $out;
            }
            $movementId = $this->stock->increase(
                $productId,
                $qtyOrTarget,
                'csv_import',
                null,
                StockService::buildStockMotive('adjustment', $qtyOrTarget, null, null, $detail . ' · remesa'),
                'in',
                $unitCost,
                null,
                'equity',
                false,
                date('Y-m-d'),
                $lotMeta
            );
            if ($movementId > 0) {
                $this->postStockAccounting($movementId);
                $out['movements'] = 1;
                if ($hasLotData || $this->stock->lots()->tracksLots($productId)) {
                    $out['remesas'] = 1;
                }
            }
            return $out;
        }

        // Modo absoluto: sincroniza products.stock y el submayor de lotes.
        $current = (int)$this->query("SELECT stock FROM products WHERE id = ?", [$productId])->fetchColumn();
        $target = max(0, $qtyOrTarget);
        $diff = $target - $current;
        if ($diff === 0) {
            // Misma existencia + lote/vence: al menos activar control y sembrar si falta.
            if ($hasLotData) {
                try {
                    $lots = $this->stock->lots();
                    $lots->configureProduct($productId, true, 30);
                    if ($lots->seedOpeningLot($productId, $unitCost, $expiryDate) > 0) {
                        $out['remesas'] = 1;
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
            return $out;
        }

        if ($diff > 0) {
            $movementId = $this->stock->increase(
                $productId,
                $diff,
                'csv_import',
                null,
                StockService::buildStockMotive('adjustment', $diff, $current, $target, $detail . ' · sync absoluto'),
                'in',
                $unitCost,
                null,
                'equity',
                false,
                date('Y-m-d'),
                $lotMeta
            );
            if ($movementId > 0) {
                $this->postStockAccounting($movementId);
                $out['movements'] = 1;
                if ($hasLotData) {
                    $out['remesas'] = 1;
                }
            }
        } else {
            $movementId = $this->stock->decrease(
                $productId,
                abs($diff),
                'csv_import',
                null,
                StockService::buildStockMotive('adjustment', $diff, $current, $target, $detail . ' · sync absoluto'),
                $unitCost
            );
            if ($movementId > 0) {
                $this->postStockAccounting($movementId);
                $out['movements'] = 1;
                // Tras bajar stock, recortar lotes no vendibles (vencidos/bloqueados) que sobren.
                try {
                    $this->stock->lots()->trimPhysicalToStock($productId, $target, $movementId);
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        return $out;
    }

    /** Parsea cantidades con miles (1.234 / 1,234) sin truncar a 1. */
    private function parseQtyCell(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        // Entero puro
        if (preg_match('/^-?\d+$/', $raw)) {
            return max(0, (int)$raw);
        }
        $n = $this->parseMoneyCell($raw);
        return max(0, (int)round($n));
    }

    /** entrada|absoluto */
    private function resolveStockMode(string $raw, bool $hasLotData): string
    {
        $v = mb_strtolower(trim($raw), 'UTF-8');
        $v = str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $v);
        if (in_array($v, ['entrada', 'remesa', 'add', 'suma', 'in', 'purchase', 'compra'], true)) {
            return 'entrada';
        }
        if (in_array($v, ['absoluto', 'total', 'set', 'sync', 'existencia', 'absolute'], true)) {
            return 'absoluto';
        }
        // Con lote/vence: por defecto es remesa (igual que compra).
        return $hasLotData ? 'entrada' : 'absoluto';
    }

    private function parseMoneyCell(string $raw): float
    {
        if ($raw === '') {
            return 0.0;
        }
        if (function_exists('SoftNova\\Core\\parse_money')) {
            return round(max(0, \SoftNova\Core\parse_money($raw)), 2);
        }
        return round(max(0, (float)str_replace(',', '.', $raw)), 2);
    }

    private function parseExpiryDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // Excel a veces manda serial; ignorar números puros raros.
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', substr($raw, 0, 10));
            return ($dt && $dt->format('Y-m-d') === substr($raw, 0, 10)) ? $dt->format('Y-m-d') : null;
        }
        foreach (['d/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y', 'm/d/Y'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $raw);
            if ($dt instanceof \DateTimeImmutable) {
                $err = \DateTimeImmutable::getLastErrors();
                if (is_array($err) && (($err['warning_count'] ?? 0) > 0 || ($err['error_count'] ?? 0) > 0)) {
                    continue;
                }
                return $dt->format('Y-m-d');
            }
        }
        return null;
    }
}
