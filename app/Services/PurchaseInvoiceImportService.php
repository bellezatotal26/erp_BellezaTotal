<?php

namespace SoftNova\Services;

/**
 * Carga de facturas de compra de proveedor.
 *
 * El flujo es en dos pasos deliberadamente: primero se interpreta el documento y
 * se deja en estado `pending` para que el usuario revise el modal de confirmación,
 * y solo al confirmar se afecta el inventario y la contabilidad. Así una lectura
 * equivocada nunca ensucia el kardex.
 *
 * Orígenes soportados: CSV/Excel exportado a CSV, texto pegado, PDF con capa de
 * texto y, si hay clave de IA configurada, extracción asistida por modelo.
 */
class PurchaseInvoiceImportService
{
    private const MAX_UPLOAD_BYTES = 8388608; // 8MB
    private const ALLOWED_EXTENSIONS = ['csv', 'txt', 'pdf', 'jpg', 'jpeg', 'png', 'webp', 'xml'];

    private \PDO $db;
    private ProductSupplierService $links;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        SupplyChainSchema::ensure($db);
        $this->links = new ProductSupplierService($db);
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // ---------------------------------------------------------------- ingesta

    /**
     * Interpreta el documento y guarda el borrador de importación.
     *
     * @param array{supplier_id?:?int,invoice_number?:string,invoice_date?:?string,due_date?:?string,payment_mode?:string,use_ai?:bool} $meta
     * @param array<string,mixed>|null $file Entrada de $_FILES
     * @return array{import_id:int,notes:string}
     */
    public function ingest(array $meta, ?array $file = null, string $pastedText = ''): array
    {
        $stored = null;
        $rawText = trim($pastedText);
        $source = $rawText !== '' ? 'text' : 'manual';
        $notas = [];

        if ($file && ($file['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_NO_FILE) {
            $stored = $this->storeFile($file);
            $source = $stored['extension'] === 'csv' ? 'csv' : 'text';
            $extracted = $this->extractText($stored['path'], $stored['extension']);
            if ($extracted === '' && $rawText === '') {
                $notas[] = 'No se pudo leer texto del archivo';
            }
            if ($extracted !== '') {
                $rawText = $extracted;
            }
        }

        $header = [];
        $items = [];
        $ext = (string)($stored['extension'] ?? '');
        $isImageFile = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
        $pdfLooksScanned = $ext === 'pdf' && ($rawText === '' || !$this->pdfTextLooksUsable($rawText));

        if ($stored && $ext === 'csv') {
            $items = $this->parseCsv($stored['path']);
            $notas[] = count($items) . ' línea(s) leídas del CSV';
        }

        $aiService = null;
        $useAi = !empty($meta['use_ai']);

        // Texto + IA (PDF con capa de texto, TXT pegado, etc.)
        if (empty($items) && $rawText !== '' && $useAi && !$pdfLooksScanned && !$isImageFile) {
            $aiService = new AiService($this->db);
            $ai = $aiService->extractInvoiceData($rawText);
            if ($ai !== null && !empty($ai['items'])) {
                $header = $ai;
                $items = $this->normalizeAiItems($ai['items']);
                $source = 'ai';
                $notas[] = count($items) . ' línea(s) interpretadas por IA (texto)';
            } else {
                $notas[] = 'La IA no devolvió líneas utilizables del texto; se usó el análisis por patrones';
            }
        }

        // PDF solo imagen / foto: visión (requiere IA configurada)
        if (empty($items) && $stored && ($isImageFile || $pdfLooksScanned)) {
            if ($useAi) {
                $aiService = $aiService ?? new AiService($this->db);
                if ($aiService->isConfigured()) {
                    $images = $this->collectInvoiceImages($stored['path'], $ext);
                    if ($images !== []) {
                        $ai = $aiService->extractInvoiceFromImages($images);
                        if ($ai !== null && !empty($ai['items'])) {
                            $header = $ai;
                            $items = $this->normalizeAiItems($ai['items']);
                            $source = 'ai';
                            $notas[] = count($items) . ' línea(s) leídas por IA desde imagen'
                                . ($pdfLooksScanned ? ' (PDF escaneado)' : '');
                        } else {
                            $notas[] = 'La IA con visión no pudo leer productos en la imagen. Pegue el texto o use CSV';
                        }
                    } else {
                        $notas[] = 'PDF/imagen sin contenido visual extraíble. Pruebe exportar a CSV o pegar el texto';
                    }
                } else {
                    $notas[] = 'PDF/imagen requiere IA (OPENROUTER_API_KEY) para leer escaneos';
                }
            } else {
                $notas[] = 'Este archivo parece solo imagen (sin texto). Active «Interpretar con IA» o pegue el texto / use CSV';
            }
        }

        if (empty($items) && $rawText !== '' && !$isImageFile) {
            if ($pdfLooksScanned) {
                // Ya se intentó visión arriba; no forzar patrones sobre basura.
            } else {
                $items = $this->parseText($rawText);
                if ($items) {
                    $notas[] = count($items) . ' línea(s) detectadas por patrones';
                } elseif ($ext === 'pdf') {
                    $notas[] = 'No se detectaron líneas de producto legibles en el PDF; capture las líneas a mano o use CSV';
                }
            }
        }

        $importId = $this->createImport($meta, $header, $source, $stored, $rawText, $notas);
        $this->saveItems($importId, $items, $meta['supplier_id'] ?? null);
        $this->refreshTotals($importId);

        return ['import_id' => $importId, 'notes' => implode('. ', $notas)];
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $header Datos leídos del documento
     * @param array{path:string,mime:string,original:string,extension:string}|null $stored
     * @param list<string> $notas
     */
    private function createImport(
        array $meta,
        array $header,
        string $source,
        ?array $stored,
        string $rawText,
        array $notas
    ): int {
        $invoiceNumber = trim((string)($meta['invoice_number'] ?? '')) ?: trim((string)($header['invoice_number'] ?? ''));
        $invoiceDate = $this->normalizeDate($meta['invoice_date'] ?? null) ?? $this->normalizeDate($header['invoice_date'] ?? null);
        $dueDate = $this->normalizeDate($meta['due_date'] ?? null) ?? $this->normalizeDate($header['due_date'] ?? null);
        $paymentMode = in_array($meta['payment_mode'] ?? '', ['payable', 'cash', 'transfer', 'card'], true)
            ? (string)$meta['payment_mode']
            : 'payable';

        $this->query(
            "INSERT INTO purchase_invoice_imports
                (supplier_id, invoice_number, invoice_date, due_date, source, file_path, file_mime,
                 original_name, raw_text, parse_notes, payment_mode, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                !empty($meta['supplier_id']) ? (int)$meta['supplier_id'] : null,
                $invoiceNumber !== '' ? substr($invoiceNumber, 0, 80) : null,
                $invoiceDate,
                $dueDate,
                $source,
                $stored['path'] ?? null,
                $stored['mime'] ?? null,
                $stored['original'] ?? null,
                $rawText !== '' ? mb_substr($rawText, 0, 60000) : null,
                substr(implode('. ', $notas), 0, 255) ?: null,
                $paymentMode,
                $_SESSION['tenant_user_id'] ?? null,
            ]
        );

        return (int)$this->db->lastInsertId();
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    private function saveItems(int $importId, array $items, ?int $supplierId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO purchase_invoice_import_items
                (import_id, line_no, raw_line, supplier_sku, description, quantity, unit_cost,
                 tax_rate, discount_pct, line_total, matched_product_id, match_type, row_action,
                 lot_code, expiry_date, sale_price, confidence)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $lineNo = 0;
        foreach ($items as $item) {
            $descripcion = trim((string)($item['description'] ?? ''));
            $item = $this->normalizeInvoiceLineAmounts($item);
            $cantidad = round((float)($item['quantity'] ?? 0), 3);
            if ($descripcion === '' && $cantidad <= 0) {
                continue;
            }
            $lineNo++;

            $sku = substr(trim((string)($item['supplier_sku'] ?? '')), 0, 120);
            $match = $this->links->matchInvoiceLine($supplierId, $sku, $descripcion);
            $costo = round((float)($item['unit_cost'] ?? 0), 2);
            $descuento = round(max(0, min(100, (float)($item['discount_pct'] ?? 0))), 2);
            $impuesto = round(max(0, (float)($item['tax_rate'] ?? 0)), 2);
            $totalLinea = isset($item['line_total']) && (float)$item['line_total'] > 0
                ? round((float)$item['line_total'], 2)
                : round($cantidad * $costo * (1 - $descuento / 100) * (1 + $impuesto / 100), 2);

            $stmt->execute([
                $importId,
                $lineNo,
                isset($item['raw_line']) ? substr((string)$item['raw_line'], 0, 500) : null,
                $sku !== '' ? $sku : null,
                substr($descripcion, 0, 255),
                $cantidad,
                $costo,
                $impuesto,
                $descuento,
                $totalLinea,
                $match['product_id'] ?? null,
                $match['match_type'] ?? 'none',
                $match ? 'add_stock' : 'create_product',
                substr(trim((string)($item['lot_code'] ?? '')), 0, 60) ?: null,
                $this->normalizeDate($item['expiry_date'] ?? null),
                isset($item['sale_price']) && (float)$item['sale_price'] > 0 ? round((float)$item['sale_price'], 2) : null,
                (int)($match['confidence'] ?? 0),
            ]);
        }
    }

    // ---------------------------------------------------------------- lectura

    /**
     * @param array<string,mixed> $file
     * @return array{path:string,mime:string,original:string,extension:string}
     */
    private function storeFile(array $file): array
    {
        if (($file['error'] ?? 1) !== \UPLOAD_ERR_OK) {
            throw new \RuntimeException('El archivo no se subió correctamente');
        }
        if ((int)($file['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException('El archivo supera los 8MB permitidos');
        }

        $original = (string)($file['name'] ?? 'factura');
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \RuntimeException('Formato no soportado. Use CSV, PDF, XML, TXT o imagen');
        }

        $dir = dirname(__DIR__, 2) . '/public/uploads/purchase-invoices';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear la carpeta de facturas');
        }

        $nombre = 'fac-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $destino = $dir . '/' . $nombre;
        $movido = is_uploaded_file((string)$file['tmp_name'])
            ? move_uploaded_file((string)$file['tmp_name'], $destino)
            : rename((string)$file['tmp_name'], $destino);
        if (!$movido) {
            throw new \RuntimeException('No se pudo guardar el archivo de la factura');
        }

        return [
            'path' => 'uploads/purchase-invoices/' . $nombre,
            'mime' => (string)($file['type'] ?? 'application/octet-stream'),
            'original' => substr($original, 0, 255),
            'extension' => $extension,
        ];
    }

    /** Texto plano del documento; imágenes / PDF escaneado → vacío (usar visión). */
    private function extractText(string $relativePath, string $extension): string
    {
        $absolute = dirname(__DIR__, 2) . '/public/' . $relativePath;
        if (!is_readable($absolute)) {
            return '';
        }

        if (in_array($extension, ['txt', 'csv', 'xml'], true)) {
            return $this->toUtf8((string)file_get_contents($absolute));
        }
        if ($extension === 'pdf') {
            return $this->extractPdfText($absolute);
        }

        return '';
    }

    /**
     * Imágenes para IA con visión: archivo imagen directo, o JPEG embebidos en PDF escaneado.
     *
     * @return list<array{mime:string,data:string}>
     */
    private function collectInvoiceImages(string $relativePath, string $extension): array
    {
        $absolute = dirname(__DIR__, 2) . '/public/' . $relativePath;
        if (!is_readable($absolute)) {
            return [];
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $data = (string)file_get_contents($absolute);
            if ($data === '') {
                return [];
            }
            $mime = match ($extension) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                default => 'image/jpeg',
            };
            return [['mime' => $mime, 'data' => $data]];
        }

        if ($extension === 'pdf') {
            $fromImagick = $this->pdfPagesToImagesImagick($absolute);
            if ($fromImagick !== []) {
                return $fromImagick;
            }
            return $this->extractEmbeddedJpegFromPdf($absolute);
        }

        return [];
    }

    /** @return list<array{mime:string,data:string}> */
    private function pdfPagesToImagesImagick(string $absolute): array
    {
        if (!class_exists(\Imagick::class)) {
            return [];
        }
        try {
            $im = new \Imagick();
            $im->setResolution(150, 150);
            $im->readImage($absolute . '[0-2]'); // hasta 3 primeras páginas
            $out = [];
            foreach ($im as $page) {
                $page->setImageFormat('jpeg');
                $page->setImageCompressionQuality(85);
                $blob = (string)$page->getImageBlob();
                if ($blob !== '' && strlen($blob) > 2000) {
                    $out[] = ['mime' => 'image/jpeg', 'data' => $blob];
                }
            }
            $im->clear();
            $im->destroy();
            return $out;
        } catch (\Throwable $e) {
            error_log('Imagick PDF factura: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Muchos PDF escaneados embeben JPEG crudos; se extraen por marcadores SOI/EOI.
     *
     * @return list<array{mime:string,data:string}>
     */
    private function extractEmbeddedJpegFromPdf(string $absolute): array
    {
        $bin = (string)file_get_contents($absolute);
        if ($bin === '') {
            return [];
        }
        $images = [];
        $offset = 0;
        $len = strlen($bin);
        while ($offset < $len && count($images) < 3) {
            $start = strpos($bin, "\xFF\xD8\xFF", $offset);
            if ($start === false) {
                break;
            }
            $end = strpos($bin, "\xFF\xD9", $start + 3);
            if ($end === false) {
                break;
            }
            $jpeg = substr($bin, $start, $end - $start + 2);
            $offset = $end + 2;
            // Ignorar iconos / thumbs diminutos
            if (strlen($jpeg) < 8000) {
                continue;
            }
            $images[] = ['mime' => 'image/jpeg', 'data' => $jpeg];
        }
        return $images;
    }

    /**
     * Extrae la capa de texto de un PDF.
     * Prioriza smalot/pdfparser (ToUnicode / encodings reales). Fallback casero
     * solo si el parser no está disponible. PDFs escaneados → vacío.
     */
    private function extractPdfText(string $absolute): string
    {
        $viaParser = $this->extractPdfTextWithParser($absolute);
        if ($this->pdfTextLooksUsable($viaParser)) {
            return $this->normalizePdfExtractedText($viaParser);
        }

        $fallback = $this->extractPdfTextFallback($absolute);
        if ($this->pdfTextLooksUsable($fallback)) {
            return $this->normalizePdfExtractedText($fallback);
        }

        // Si ambos son pobres, preferir el del parser (menos basura tipográfica).
        $best = mb_strlen(trim($viaParser)) >= mb_strlen(trim($fallback)) ? $viaParser : $fallback;
        return $this->normalizePdfExtractedText($best);
    }

    private function extractPdfTextWithParser(string $absolute): string
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            return '';
        }
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($absolute);
            $text = (string)$pdf->getText();
            return trim($text);
        } catch (\Throwable $e) {
            error_log('PDF parser factura compra: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Fallback sin librería: solo streams de contenido con operadores de texto.
     * No interpreta ToUnicode; puede fallar en facturas con fuentes embebidas.
     */
    private function extractPdfTextFallback(string $absolute): string
    {
        $binario = (string)file_get_contents($absolute);
        if ($binario === '') {
            return '';
        }

        $textos = [];
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $binario, $matches)) {
            foreach ($matches[1] as $stream) {
                $plano = @gzuncompress($stream);
                if ($plano === false) {
                    $plano = @gzinflate(substr($stream, 2));
                }
                if ($plano === false) {
                    $plano = $stream;
                }
                if (!is_string($plano) || $plano === '') {
                    continue;
                }
                // Evitar programas de fuente / streams binarios sin operadores de texto.
                if (!preg_match('/\b(BT|ET|Tj|TJ|Td|TD|Tm|\'|")\b/', $plano)) {
                    continue;
                }
                $textos[] = $plano;
            }
        }

        $contenido = implode("\n", $textos);
        if ($contenido === '') {
            return '';
        }

        $salida = [];
        if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)\s*(?:Tj|\'|")/', $contenido, $tj)) {
            foreach ($tj[1] as $fragmento) {
                $decoded = $this->decodePdfLiteralString($fragmento);
                if ($decoded !== '') {
                    $salida[] = $decoded;
                }
            }
        }
        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $contenido, $arrays)) {
            foreach ($arrays[1] as $grupo) {
                $chunk = '';
                if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)|<([0-9A-Fa-f\s]+)>/', $grupo, $partes, PREG_SET_ORDER)) {
                    foreach ($partes as $p) {
                        if (isset($p[1]) && $p[1] !== '') {
                            $chunk .= $this->decodePdfLiteralString($p[1]);
                        } elseif (isset($p[2]) && $p[2] !== '') {
                            $chunk .= $this->decodePdfHexString($p[2]);
                        }
                    }
                }
                if (trim($chunk) !== '') {
                    $salida[] = $chunk;
                }
            }
        }
        if (preg_match_all('/<([0-9A-Fa-f\s]+)>\s*Tj/', $contenido, $hex)) {
            foreach ($hex[1] as $h) {
                $decoded = $this->decodePdfHexString($h);
                if ($decoded !== '') {
                    $salida[] = $decoded;
                }
            }
        }

        return trim(implode("\n", $salida));
    }

    private function decodePdfLiteralString(string $raw): string
    {
        $s = stripcslashes($raw);
        // PDF Unicode strings: BOM UTF-16BE
        if (str_starts_with($s, "\xFE\xFF") || str_starts_with($s, "\xFF\xFE")) {
            $converted = @mb_convert_encoding($s, 'UTF-8', 'UTF-16');
            return is_string($converted) ? $converted : $s;
        }
        return $s;
    }

    private function decodePdfHexString(string $hex): string
    {
        $hex = preg_replace('/\s+/', '', $hex) ?? '';
        if ($hex === '' || (strlen($hex) % 2) === 1) {
            $hex .= '0';
        }
        $bin = @hex2bin($hex);
        if ($bin === false || $bin === '') {
            return '';
        }
        if (str_starts_with($bin, "\xFE\xFF") || str_starts_with($bin, "\xFF\xFE")) {
            $converted = @mb_convert_encoding($bin, 'UTF-8', 'UTF-16');
            return is_string($converted) ? $converted : '';
        }
        return $this->toUtf8($bin);
    }

    private function normalizePdfExtractedText(string $texto): string
    {
        $texto = $this->toUtf8($texto);
        // Dompdf / parsers a veces meten NUL o controles.
        $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $texto) ?? $texto;
        $texto = str_replace("\u{FFFD}", '', $texto);
        $texto = preg_replace("/[ \t]+\n/", "\n", $texto) ?? $texto;
        $texto = preg_replace("/\n{3,}/", "\n\n", $texto) ?? $texto;
        return trim($texto);
    }

    /** Heurística: ¿hay letras reales o solo basura de encoding? */
    private function pdfTextLooksUsable(string $texto): bool
    {
        $texto = trim($texto);
        if ($texto === '' || mb_strlen($texto) < 12) {
            return false;
        }
        if (str_contains($texto, "\u{FFFD}") || str_contains($texto, '�')) {
            // Aún puede servir si hay suficientes letras.
        }
        $letters = preg_match_all('/\p{L}/u', $texto) ?: 0;
        $len = max(1, mb_strlen(preg_replace('/\s+/u', '', $texto) ?? $texto));
        return $letters >= 20 && ($letters / $len) >= 0.35;
    }

    private function isReadableProductDescription(string $desc): bool
    {
        $desc = trim($desc);
        if ($desc === '' || mb_strlen($desc) < 3) {
            return false;
        }
        if (str_contains($desc, "\u{FFFD}") || str_contains($desc, '�')) {
            return false;
        }
        $letters = preg_match_all('/\p{L}/u', $desc) ?: 0;
        if ($letters < 3) {
            return false;
        }
        $len = max(1, mb_strlen(preg_replace('/\s+/u', '', $desc) ?? $desc));
        // Rechaza cosas tipo "$&(," o secuencias sin palabras reales.
        if (($letters / $len) < 0.45) {
            return false;
        }
        return true;
    }

    /**
     * CSV de líneas de factura. Reconoce encabezados en español e inglés
     * y tolera separador coma, punto y coma o tabulación.
     *
     * @return list<array<string,mixed>>
     */
    public function parseCsv(string $relativeOrAbsolutePath): array
    {
        $ruta = is_readable($relativeOrAbsolutePath)
            ? $relativeOrAbsolutePath
            : dirname(__DIR__, 2) . '/public/' . $relativeOrAbsolutePath;
        if (!is_readable($ruta)) {
            return [];
        }

        $raw = (string)file_get_contents($ruta);
        if ($raw === '') {
            return [];
        }
        $raw = $this->normalizeCsvEncoding($raw);

        $primeraLinea = preg_split('/\r\n|\n|\r/', $raw, 2)[0] ?? '';
        $separador = $this->detectCsvSeparator($primeraLinea);

        $handle = fopen('php://memory', 'r+');
        if (!$handle) {
            return [];
        }
        fwrite($handle, $raw);
        rewind($handle);

        $mapa = null;
        $filas = [];
        while (($campos = fgetcsv($handle, 0, $separador)) !== false) {
            if ($campos === [null] || $campos === false) {
                continue;
            }
            $campos = array_map(fn($v) => trim((string)$v), $campos);
            if ($campos !== [] && isset($campos[0])) {
                $campos[0] = preg_replace('/^\xEF\xBB\xBF/', '', $campos[0]) ?? $campos[0];
            }
            if (implode('', $campos) === '') {
                continue;
            }

            if ($mapa === null) {
                $mapped = $this->mapCsvHeader($campos);
                if ($mapped !== []) {
                    $mapa = $mapped;
                    continue;
                }
                if ($this->looksLikeHeaderRow($campos)) {
                    $mapa = $this->defaultPositionalMap(count($campos));
                    continue;
                }
                $mapa = $this->inferCsvMap($campos);
            }

            $fila = $this->rowFromCsvFields($campos, $mapa, $separador);
            $filas[] = $fila;
        }
        fclose($handle);

        // Si casi todas las líneas quedaron en 0, reintentar por heurística de columnas numéricas.
        $filas = $this->recoverZeroAmountRows($filas, $mapa ?? []);

        return $filas;
    }

    private function normalizeCsvEncoding(string $raw): string
    {
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }
        if (strncmp($raw, "\xFF\xFE", 2) === 0 || strncmp($raw, "\xFE\xFF", 2) === 0) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        if (substr_count($raw, "\x00") > max(10, (int)(strlen($raw) * 0.2))) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        return $this->toUtf8($raw);
    }

    private function detectCsvSeparator(string $primera): string
    {
        $tabs = substr_count($primera, "\t");
        $semis = substr_count($primera, ';');
        $comas = substr_count($primera, ',');
        if ($tabs > $semis && $tabs > $comas) {
            return "\t";
        }
        return $semis > $comas ? ';' : ',';
    }

    /**
     * @param list<string> $campos
     * @param array<string,int> $mapa
     * @return array<string,mixed>
     */
    private function rowFromCsvFields(array $campos, array $mapa, string $separador): array
    {
        $fila = [
            'raw_line' => implode($separador, $campos),
            'supplier_sku' => $this->csvValue($campos, $mapa, 'supplier_sku'),
            'description' => $this->csvValue($campos, $mapa, 'description'),
            'quantity' => $this->toNumber($this->csvValue($campos, $mapa, 'quantity')),
            'unit_cost' => $this->toNumber($this->csvValue($campos, $mapa, 'unit_cost')),
            'tax_rate' => $this->toNumber($this->csvValue($campos, $mapa, 'tax_rate')),
            'discount_pct' => $this->toNumber($this->csvValue($campos, $mapa, 'discount_pct')),
            'line_total' => $this->toNumber($this->csvValue($campos, $mapa, 'line_total')),
            'lot_code' => $this->csvValue($campos, $mapa, 'lot_code'),
            'expiry_date' => $this->normalizeDate($this->csvValue($campos, $mapa, 'expiry_date')),
            'sale_price' => $this->toNumber($this->csvValue($campos, $mapa, 'sale_price')),
            '_fields' => $campos,
        ];
        return $this->normalizeInvoiceLineAmounts($fila);
    }

    /**
     * @param list<array<string,mixed>> $filas
     * @param array<string,int> $mapa
     * @return list<array<string,mixed>>
     */
    private function recoverZeroAmountRows(array $filas, array $mapa): array
    {
        if ($filas === []) {
            return $filas;
        }
        $vacias = 0;
        foreach ($filas as $f) {
            if ((float)($f['quantity'] ?? 0) <= 0 && (float)($f['unit_cost'] ?? 0) <= 0 && (float)($f['line_total'] ?? 0) <= 0) {
                $vacias++;
            }
        }
        if ($vacias < max(1, (int)ceil(count($filas) * 0.5))) {
            return array_map(static function (array $f) {
                unset($f['_fields']);
                return $f;
            }, $filas);
        }

        $out = [];
        foreach ($filas as $f) {
            if ((float)($f['quantity'] ?? 0) > 0 || (float)($f['unit_cost'] ?? 0) > 0 || (float)($f['line_total'] ?? 0) > 0) {
                unset($f['_fields']);
                $out[] = $f;
                continue;
            }
            $campos = $f['_fields'] ?? [];
            if (!is_array($campos) || $campos === []) {
                unset($f['_fields']);
                $out[] = $f;
                continue;
            }
            $rescate = $this->inferAmountsFromFields($campos, (string)($f['description'] ?? ''));
            $f['quantity'] = $rescate['quantity'];
            $f['unit_cost'] = $rescate['unit_cost'];
            $f['line_total'] = $rescate['line_total'];
            if ($f['description'] === '' && $rescate['description'] !== '') {
                $f['description'] = $rescate['description'];
            }
            if (($f['supplier_sku'] ?? '') === '' && $rescate['supplier_sku'] !== '') {
                $f['supplier_sku'] = $rescate['supplier_sku'];
            }
            unset($f['_fields']);
            $out[] = $this->normalizeInvoiceLineAmounts($f);
        }
        return $out;
    }

    /**
     * Extrae cantidad/costo/total de celdas cuando el encabezado falló.
     *
     * @param list<string> $campos
     * @return array{quantity:float,unit_cost:float,line_total:float,description:string,supplier_sku:string}
     */
    private function inferAmountsFromFields(array $campos, string $fallbackDesc = ''): array
    {
        $textos = [];
        $numeros = [];
        foreach ($campos as $i => $val) {
            $val = trim((string)$val);
            if ($val === '') {
                continue;
            }
            $num = $this->toNumber($val);
            $tieneLetras = (bool)preg_match('/[A-Za-zÁÉÍÓÚáéíóúñÑ]/u', $val);
            if ($tieneLetras && ($num <= 0 || preg_match('/[A-Za-z]/', preg_replace('/[\d.,\s\$\-]/', '', $val) ?? ''))) {
                $textos[] = $val;
                continue;
            }
            if ($num > 0) {
                $numeros[] = $num;
            }
        }

        $sku = '';
        $desc = $fallbackDesc;
        if ($textos !== []) {
            // Código corto primero, descripción la más larga.
            usort($textos, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            $desc = $desc !== '' ? $desc : $textos[0];
            foreach ($textos as $t) {
                if (preg_match('/^[A-Z0-9][A-Z0-9\-\.\/]{2,20}$/i', $t) && mb_strlen($t) < mb_strlen($desc)) {
                    $sku = $t;
                    break;
                }
            }
        }

        $qty = 0.0;
        $cost = 0.0;
        $total = 0.0;
        if (count($numeros) === 1) {
            $total = $numeros[0];
            $qty = 1.0;
            $cost = $total;
        } elseif (count($numeros) >= 2) {
            // Preferir: cantidad (entero razonable) + costo + opcional total.
            foreach ($numeros as $n) {
                if ($qty <= 0 && $n > 0 && $n <= 100000 && abs($n - round($n)) < 0.001) {
                    $qty = $n;
                    continue;
                }
                if ($cost <= 0) {
                    $cost = $n;
                    continue;
                }
                if ($total <= 0) {
                    $total = $n;
                }
            }
            if ($qty <= 0) {
                $qty = 1.0;
            }
            if ($total <= 0 && $cost > 0) {
                $total = round($qty * $cost, 2);
            }
            if ($cost <= 0 && $total > 0) {
                $cost = round($total / max(1, $qty), 2);
            }
            // Si el "costo" quedó mayor que el "total", intercambiar (orden invertido).
            if ($total > 0 && $cost > $total && $qty > 0) {
                [$cost, $total] = [$total, $cost];
                if ($qty > 1) {
                    $cost = round($total / $qty, 2);
                }
            }
        }

        return [
            'quantity' => $qty,
            'unit_cost' => $cost,
            'line_total' => $total,
            'description' => $desc,
            'supplier_sku' => $sku,
        ];
    }

    /** @return array<string,int> */
    private function mapCsvHeader(array $campos): array
    {
        $alias = [
            'supplier_sku' => [
                'sku', 'codigo', 'código', 'code', 'referencia', 'ref', 'codigo_proveedor',
                'sku_proveedor', 'cod', 'itemcode', 'codigoproducto', 'plu', 'idproducto',
            ],
            'description' => [
                'descripcion', 'descripción', 'description', 'producto', 'nombre', 'detalle',
                'item', 'articulo', 'concepto', 'product', 'name', 'nomproducto', 'desc',
            ],
            'quantity' => [
                'cantidad', 'qty', 'quantity', 'cant', 'unidades', 'und', 'uds', 'ctd',
                'cant.', 'q', 'cantidadpedida', 'cantidadfacturada', 'cnt', 'cantidadund',
            ],
            'unit_cost' => [
                'costo_unitario', 'precio_unitario', 'valor_unitario', 'vr_unitario',
                'valorunitario', 'vrunitario', 'costounitario', 'preciounitario',
                'unit_cost', 'unitprice', 'punit', 'vu', 'vunitario', 'costo', 'precio',
                'price', 'vr',
            ],
            'tax_rate' => ['iva', 'impuesto', 'tax', 'tax_rate', 'porcentaje_iva', 'pctiva', 'porciva', 'tarifaiva'],
            'discount_pct' => ['descuento', 'discount', 'dcto', 'descuento_pct', 'porcdescuento'],
            'line_total' => [
                'total_linea', 'line_total', 'valor_total', 'valortotal', 'importe',
                'subtotal', 'totalitem', 'valortot', 'neto', 'total',
            ],
            'lot_code' => ['lote', 'lot', 'lot_code', 'codigo_lote'],
            'expiry_date' => ['vencimiento', 'vence', 'expiry', 'expiry_date', 'fecha_vencimiento', 'caducidad'],
            'sale_price' => ['precio_venta', 'pvp', 'sale_price', 'precioventa'],
        ];

        // Emparejar por longitud de alias (exacto primero) para que "valortotal"
        // no lo robe el alias corto "valor"/"vr" de unit_cost.
        $candidatos = [];
        foreach ($campos as $indice => $titulo) {
            $normalizado = $this->slug($titulo);
            if ($normalizado === '' || in_array($normalizado, ['ean', 'barcode', 'tipo', 'estado', 'n', 'no', 'num', 'numero'], true)) {
                continue;
            }
            foreach ($alias as $clave => $opciones) {
                foreach ($opciones as $opcion) {
                    $opSlug = $this->slug($opcion);
                    if ($opSlug === '') {
                        continue;
                    }
                    $score = 0;
                    if ($normalizado === $opSlug) {
                        $score = 100 + mb_strlen($opSlug);
                    } elseif (str_starts_with($normalizado, $opSlug) || str_ends_with($normalizado, $opSlug)) {
                        $score = 60 + mb_strlen($opSlug);
                    } elseif (str_contains($normalizado, $opSlug) && mb_strlen($opSlug) >= 4) {
                        $score = 40 + mb_strlen($opSlug);
                    }
                    if ($score <= 0) {
                        continue;
                    }
                    if ($clave === 'unit_cost' && (str_contains($normalizado, 'venta') || str_contains($normalizado, 'pvp'))) {
                        continue;
                    }
                    if ($clave === 'unit_cost' && (str_contains($normalizado, 'total') || str_contains($normalizado, 'importe'))) {
                        continue;
                    }
                    if ($clave === 'line_total' && (str_contains($normalizado, 'unit') || str_contains($normalizado, 'unitario'))) {
                        continue;
                    }
                    if ($clave === 'description' && in_array($normalizado, ['proveedor', 'cliente', 'factura'], true)) {
                        continue;
                    }
                    $candidatos[] = [$score, $clave, $indice, mb_strlen($opSlug)];
                }
            }
        }

        usort($candidatos, static function ($a, $b) {
            return [$b[0], $b[3]] <=> [$a[0], $a[3]];
        });

        $mapa = [];
        $usadas = [];
        foreach ($candidatos as [, $clave, $indice]) {
            if (isset($mapa[$clave]) || isset($usadas[$indice])) {
                continue;
            }
            $mapa[$clave] = $indice;
            $usadas[$indice] = true;
        }

        if (!isset($mapa['description'])) {
            return [];
        }
        if (!isset($mapa['quantity']) && !isset($mapa['unit_cost']) && !isset($mapa['line_total'])) {
            return [];
        }
        return $mapa;
    }

    /**
     * Mapa posicional por defecto o inferido si la primera fila parece datos.
     *
     * @return array<string,int>
     */
    private function inferCsvMap(array $campos): array
    {
        $n = count($campos);
        if ($n >= 2) {
            $desc = null;
            $qty = null;
            $cost = null;
            $total = null;
            $sku = null;
            foreach ($campos as $i => $val) {
                $val = trim((string)$val);
                $num = $this->toNumber($val);
                $tieneLetras = (bool)preg_match('/[A-Za-zÁÉÍÓÚáéíóúñÑ]/u', $val);
                // Códigos alfanuméricos (SKU99) no son cantidad aunque toNumber extraiga dígitos.
                if ($tieneLetras) {
                    if ($sku === null && preg_match('/^[A-Z0-9][A-Z0-9\-\.\/]{2,20}$/i', $val) && mb_strlen($val) <= 24) {
                        $sku = $i;
                    } elseif ($desc === null && mb_strlen($val) >= 2) {
                        $desc = $i;
                    }
                    continue;
                }
                if ($num > 0 && $num <= 100000 && abs($num - round($num)) < 0.001 && $qty === null) {
                    $qty = $i;
                    continue;
                }
                if ($num > 0) {
                    if ($cost === null) {
                        $cost = $i;
                    } elseif ($total === null) {
                        $total = $i;
                    }
                }
            }
            if ($desc !== null && ($qty !== null || $cost !== null || $total !== null)) {
                $mapa = ['description' => $desc];
                if ($sku !== null) {
                    $mapa['supplier_sku'] = $sku;
                }
                if ($qty !== null) {
                    $mapa['quantity'] = $qty;
                }
                if ($cost !== null) {
                    $mapa['unit_cost'] = $cost;
                }
                if ($total !== null) {
                    $mapa['line_total'] = $total;
                }
                return $mapa;
            }
        }

        return $this->defaultPositionalMap($n);
    }

    /** @return array<string,int> */
    private function defaultPositionalMap(int $n): array
    {
        if ($n <= 3) {
            return ['description' => 0, 'quantity' => 1, 'unit_cost' => 2];
        }
        return [
            'supplier_sku' => 0, 'description' => 1, 'quantity' => 2,
            'unit_cost' => 3, 'tax_rate' => 4, 'discount_pct' => 5,
            'lot_code' => 6, 'expiry_date' => 7, 'sale_price' => 8,
        ];
    }

    private function looksLikeHeaderRow(array $campos): bool
    {
        $hits = 0;
        foreach ($campos as $titulo) {
            $normalizado = $this->slug((string)$titulo);
            if ($normalizado === '') {
                continue;
            }
            foreach ([
                'cantidad', 'cant', 'qty', 'quantity', 'descripcion', 'description', 'producto',
                'nombre', 'costo', 'precio', 'valorunitario', 'valortotal', 'importe', 'sku',
                'codigo', 'total', 'iva', 'descuento', 'unidades',
            ] as $token) {
                if ($normalizado === $token || str_starts_with($normalizado, $token) || str_ends_with($normalizado, $token)) {
                    // Evitar que un SKU de producto ("SKU99") cuente como encabezado.
                    if (in_array($token, ['sku', 'codigo', 'total'], true) && preg_match('/\d/', $normalizado)) {
                        continue;
                    }
                    $hits++;
                    break;
                }
            }
        }
        return $hits >= 2;
    }

    /**
     * Completa cantidad/costo cuando el CSV trae solo total o valores incompletos.
     *
     * @param array<string,mixed> $fila
     * @return array<string,mixed>
     */
    private function normalizeInvoiceLineAmounts(array $fila): array
    {
        $qty = (float)($fila['quantity'] ?? 0);
        $cost = (float)($fila['unit_cost'] ?? 0);
        $total = (float)($fila['line_total'] ?? 0);
        $tax = max(0, (float)($fila['tax_rate'] ?? 0));
        $disc = max(0, min(100, (float)($fila['discount_pct'] ?? 0)));

        if ($qty <= 0 && $total > 0 && $cost > 0) {
            $netFactor = (1 - $disc / 100) * (1 + $tax / 100);
            if ($netFactor > 0) {
                $qty = round($total / ($cost * $netFactor), 3);
            }
        }
        if ($qty <= 0 && $total > 0 && $cost <= 0) {
            $qty = 1.0;
            $netFactor = (1 - $disc / 100) * (1 + $tax / 100);
            $cost = $netFactor > 0 ? round($total / $netFactor, 2) : $total;
        }
        if ($cost <= 0 && $qty > 0 && $total > 0) {
            $netFactor = (1 - $disc / 100) * (1 + $tax / 100);
            $cost = $netFactor > 0 ? round($total / ($qty * $netFactor), 2) : round($total / $qty, 2);
        }
        if ($total <= 0 && $qty > 0 && $cost > 0) {
            $total = round($qty * $cost * (1 - $disc / 100) * (1 + $tax / 100), 2);
        }

        $fila['quantity'] = $qty;
        $fila['unit_cost'] = $cost;
        $fila['line_total'] = $total;
        return $fila;
    }

    /**
     * Detecta líneas de factura en texto libre.
     * Busca el patrón habitual: [código] descripción cantidad costo [total].
     *
     * @return list<array<string,mixed>>
     */
    public function parseText(string $texto): array
    {
        $filas = [];
        foreach (preg_split('/\r\n|\r|\n/', $texto) ?: [] as $linea) {
            $linea = trim(preg_replace('/\s{2,}/', ' ', $linea) ?? '');
            if ($linea === '' || mb_strlen($linea) < 8) {
                continue;
            }
            if (preg_match('/^(subtotal|total|iva|impuesto|descuento|neto|factura|nit|cliente|proveedor|fecha)\b/i', $linea)) {
                continue;
            }

            // Últimos números de la línea: cantidad, costo unitario y total.
            if (!preg_match_all('/-?\d[\d.,]*/', $linea, $numeros)) {
                continue;
            }
            $valores = array_map([$this, 'toNumber'], $numeros[0]);
            if (count($valores) < 2) {
                continue;
            }

            $posicionPrimerNumero = mb_strpos($linea, $numeros[0][0]);
            $descripcion = trim(mb_substr($linea, 0, $posicionPrimerNumero === false ? null : $posicionPrimerNumero));
            $sku = '';
            if (preg_match('/^([A-Z0-9][A-Z0-9\-\.\/]{2,})\s+(.+)$/u', $descripcion, $partes)) {
                $sku = $partes[1];
                $descripcion = trim($partes[2]);
            }
            if (!$this->isReadableProductDescription($descripcion)) {
                continue;
            }

            // La cantidad es el primer número; el costo, el siguiente mayor a cero.
            $cantidad = $valores[0] > 0 ? $valores[0] : 1.0;
            $costo = 0.0;
            for ($i = 1; $i < count($valores); $i++) {
                if ($valores[$i] > 0) {
                    $costo = $valores[$i];
                    break;
                }
            }
            if ($costo <= 0) {
                continue;
            }

            $filas[] = [
                'raw_line' => $linea,
                'supplier_sku' => $sku,
                'description' => $descripcion,
                'quantity' => $cantidad,
                'unit_cost' => $costo,
                'tax_rate' => 0.0,
                'discount_pct' => 0.0,
                'line_total' => end($valores) ?: 0.0,
                'lot_code' => '',
                'expiry_date' => $this->findExpiryInLine($linea),
                'sale_price' => 0.0,
            ];
        }

        return $filas;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function normalizeAiItems(array $items): array
    {
        $salida = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $salida[] = [
                'raw_line' => null,
                'supplier_sku' => (string)($item['supplier_sku'] ?? ''),
                'description' => (string)($item['description'] ?? ''),
                'quantity' => $this->toNumber((string)($item['quantity'] ?? '0')),
                'unit_cost' => $this->toNumber((string)($item['unit_cost'] ?? '0')),
                'tax_rate' => $this->toNumber((string)($item['tax_rate'] ?? '0')),
                'discount_pct' => $this->toNumber((string)($item['discount_pct'] ?? '0')),
                'line_total' => $this->toNumber((string)($item['line_total'] ?? '0')),
                'lot_code' => (string)($item['lot_code'] ?? ''),
                'expiry_date' => $this->normalizeDate((string)($item['expiry_date'] ?? '')),
                'sale_price' => 0.0,
            ];
        }

        return $salida;
    }

    // ------------------------------------------------------------- consulta

    /**
     * @return array{import:array<string,mixed>,items:list<array<string,mixed>>}|null
     */
    public function get(int $importId): ?array
    {
        $import = $this->query(
            "SELECT i.*, s.name supplier_name, u.name created_by_name
             FROM purchase_invoice_imports i
             LEFT JOIN suppliers s ON s.id = i.supplier_id
             LEFT JOIN users u ON u.id = i.created_by
             WHERE i.id = ?",
            [$importId]
        )->fetch();

        if (!$import) {
            return null;
        }

        $items = $this->query(
            "SELECT it.*, p.name matched_product_name, p.code matched_product_code,
                    p.stock matched_stock, p.sale_price matched_sale_price, p.track_lots matched_track_lots
             FROM purchase_invoice_import_items it
             LEFT JOIN products p ON p.id = it.matched_product_id
             WHERE it.import_id = ?
             ORDER BY it.line_no ASC, it.id ASC",
            [$importId]
        )->fetchAll();

        return ['import' => $import, 'items' => $items];
    }

    /** @return list<array<string,mixed>> */
    public function pendingImports(int $limit = 50): array
    {
        return $this->query(
            "SELECT i.*, s.name supplier_name,
                    (SELECT COUNT(*) FROM purchase_invoice_import_items it WHERE it.import_id = i.id) items_count
             FROM purchase_invoice_imports i
             LEFT JOIN suppliers s ON s.id = i.supplier_id
             WHERE i.status = 'pending'
             ORDER BY i.id DESC
             LIMIT " . max(1, min(200, $limit))
        )->fetchAll();
    }

    // ------------------------------------------------------------ edición

    /**
     * Guarda las correcciones hechas en el modal de confirmación.
     *
     * @param array<int,array<string,mixed>> $rows Indexado por id de línea
     */
    public function updateItems(int $importId, array $rows): void
    {
        $stmt = $this->db->prepare(
            "UPDATE purchase_invoice_import_items SET
                description = ?, quantity = ?, unit_cost = ?, tax_rate = ?, discount_pct = ?,
                line_total = ?, matched_product_id = ?, row_action = ?, lot_code = ?,
                expiry_date = ?, sale_price = ?, supplier_sku = ?
             WHERE id = ? AND import_id = ?"
        );

        foreach ($rows as $itemId => $row) {
            $itemId = (int)$itemId;
            if ($itemId <= 0 || !is_array($row)) {
                continue;
            }
            $row = $this->normalizeInvoiceLineAmounts([
                'quantity' => $this->toNumber((string)($row['quantity'] ?? '0')),
                'unit_cost' => $this->toNumber((string)($row['unit_cost'] ?? '0')),
                'tax_rate' => $this->toNumber((string)($row['tax_rate'] ?? '0')),
                'discount_pct' => $this->toNumber((string)($row['discount_pct'] ?? '0')),
                'line_total' => $this->toNumber((string)($row['line_total'] ?? '0')),
                'description' => $row['description'] ?? '',
            ] + $row);
            $cantidad = round(max(0, (float)($row['quantity'] ?? 0)), 3);
            $costo = round(max(0, (float)($row['unit_cost'] ?? 0)), 2);
            $impuesto = round(max(0, min(100, (float)($row['tax_rate'] ?? 0))), 2);
            $descuento = round(max(0, min(100, (float)($row['discount_pct'] ?? 0))), 2);
            $productoId = !empty($row['matched_product_id']) ? (int)$row['matched_product_id'] : null;
            $accion = in_array($row['row_action'] ?? '', ['add_stock', 'create_product', 'skip'], true)
                ? (string)$row['row_action']
                : 'add_stock';
            if ($accion === 'add_stock' && $productoId === null) {
                $accion = 'create_product';
            }

            $stmt->execute([
                substr(trim((string)($row['description'] ?? '')), 0, 255),
                $cantidad,
                $costo,
                $impuesto,
                $descuento,
                round($cantidad * $costo * (1 - $descuento / 100) * (1 + $impuesto / 100), 2),
                $productoId,
                $accion,
                substr(trim((string)($row['lot_code'] ?? '')), 0, 60) ?: null,
                $this->normalizeDate($row['expiry_date'] ?? null),
                isset($row['sale_price']) && (float)$row['sale_price'] > 0 ? round((float)$row['sale_price'], 2) : null,
                substr(trim((string)($row['supplier_sku'] ?? '')), 0, 120) ?: null,
                $itemId,
                $importId,
            ]);
        }

        $this->refreshTotals($importId);
    }

    /** Actualiza la cabecera con lo corregido en el modal. */
    public function updateHeader(int $importId, array $meta): void
    {
        $this->query(
            "UPDATE purchase_invoice_imports SET
                supplier_id = COALESCE(?, supplier_id),
                invoice_number = COALESCE(NULLIF(?, ''), invoice_number),
                invoice_date = COALESCE(?, invoice_date),
                due_date = COALESCE(?, due_date),
                payment_mode = ?
             WHERE id = ? AND status = 'pending'",
            [
                !empty($meta['supplier_id']) ? (int)$meta['supplier_id'] : null,
                substr(trim((string)($meta['invoice_number'] ?? '')), 0, 80),
                $this->normalizeDate($meta['invoice_date'] ?? null),
                $this->normalizeDate($meta['due_date'] ?? null),
                in_array($meta['payment_mode'] ?? '', ['payable', 'cash', 'transfer', 'card'], true)
                    ? (string)$meta['payment_mode']
                    : 'payable',
                $importId,
            ]
        );
    }

    public function discard(int $importId, string $reason = ''): void
    {
        $this->query(
            "UPDATE purchase_invoice_imports
             SET status = 'discarded',
                 parse_notes = COALESCE(NULLIF(?, ''), parse_notes)
             WHERE id = ? AND status = 'pending'",
            [substr($reason, 0, 255), $importId]
        );
    }

    // ------------------------------------------------------------ confirmación

    /**
     * Convierte la importación en una orden de compra recibida:
     * crea los productos nuevos, ingresa el stock con su lote y contabiliza.
     *
     * @return array{order_id:int,created_products:int,received_items:int,skipped:int}
     */
    public function confirm(int $importId, ?string $warehouseDate = null): array
    {
        $data = $this->get($importId);
        if (!$data) {
            throw new \RuntimeException('Importación no encontrada');
        }
        if ($data['import']['status'] !== 'pending') {
            throw new \RuntimeException('Esta factura ya fue procesada');
        }

        // Claim atómico: evita doble clic / reintento que duplica stock.
        $claimedOk = false;
        try {
            $claimed = $this->query(
                "UPDATE purchase_invoice_imports
                 SET status = 'processing', updated_at = NOW()
                 WHERE id = ? AND status = 'pending'",
                [$importId]
            );
            $claimedOk = $claimed->rowCount() >= 1;
        } catch (\Throwable $e) {
            // ENUM sin 'processing' en installs viejos
            $claimedOk = false;
        }
        if (!$claimedOk) {
            $again = (string)$this->query(
                "SELECT status FROM purchase_invoice_imports WHERE id = ? LIMIT 1",
                [$importId]
            )->fetchColumn();
            if (in_array($again, ['confirmed', 'discarded', 'processing'], true)) {
                throw new \RuntimeException('Esta factura ya fue procesada');
            }
            $hasOrder = $this->query(
                "SELECT purchase_order_id FROM purchase_invoice_imports WHERE id = ? LIMIT 1",
                [$importId]
            )->fetchColumn();
            if (!empty($hasOrder)) {
                throw new \RuntimeException('Esta factura ya fue procesada');
            }
        }

        try {
            return $this->confirmLocked($importId, $warehouseDate);
        } catch (\Throwable $e) {
            $this->releaseImportClaim($importId);
            throw $e;
        }
    }

    /**
     * Cuerpo de confirmación tras haber reclamado el import (status processing o pending legacy).
     *
     * @return array{order_id:int,created_products:int,received_items:int,skipped:int,cash_note?:string}
     */
    private function confirmLocked(int $importId, ?string $warehouseDate = null): array
    {
        $data = $this->get($importId);
        if (!$data) {
            throw new \RuntimeException('Importación no encontrada');
        }
        $import = $data['import'];
        $supplierId = !empty($import['supplier_id']) ? (int)$import['supplier_id'] : null;
        if ($supplierId === null) {
            throw new \RuntimeException('Seleccione el proveedor antes de confirmar');
        }

        return $this->confirmBuildOrder($importId, $data, $import, $supplierId, $warehouseDate);
    }

    private function releaseImportClaim(int $importId): void
    {
        try {
            $this->query(
                "UPDATE purchase_invoice_imports SET status = 'pending'
                 WHERE id = ? AND status = 'processing' AND (purchase_order_id IS NULL OR purchase_order_id = 0)",
                [$importId]
            );
        } catch (\Throwable $e) {
            // silencioso
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $import
     * @return array{order_id:int,created_products:int,received_items:int,skipped:int,cash_note?:string}
     */
    private function confirmBuildOrder(
        int $importId,
        array $data,
        array $import,
        int $supplierId,
        ?string $warehouseDate
    ): array
    {
        $creados = 0;
        $omitidos = 0;
        $sinCantidad = 0;
        $lineas = [];

        foreach ($data['items'] as $item) {
            if (($item['row_action'] ?? '') === 'skip') {
                $omitidos++;
                continue;
            }
            $item = $this->normalizeInvoiceLineAmounts([
                'quantity' => $this->toNumber((string)($item['quantity'] ?? '0')),
                'unit_cost' => $this->toNumber((string)($item['unit_cost'] ?? '0')),
                'tax_rate' => $this->toNumber((string)($item['tax_rate'] ?? '0')),
                'discount_pct' => $this->toNumber((string)($item['discount_pct'] ?? '0')),
                'line_total' => $this->toNumber((string)($item['line_total'] ?? '0')),
            ] + $item);

            // Inventario trabaja en unidades enteras; redondea y no pierde fracciones > 0.
            $cantidadFloat = (float)$item['quantity'];
            $cantidad = (int)max(0, (int)round($cantidadFloat));
            if ($cantidad <= 0 && $cantidadFloat > 0) {
                $cantidad = 1;
            }
            if ($cantidad <= 0) {
                $sinCantidad++;
                $omitidos++;
                continue;
            }

            $productoId = !empty($item['matched_product_id']) ? (int)$item['matched_product_id'] : null;
            if ($productoId === null) {
                $productoId = $this->createProductFromLine($item);
                $creados++;
            }

            // El descuento de línea se traslada al costo: la OC no maneja descuento por ítem.
            $descuento = (float)($item['discount_pct'] ?? 0);
            $costo = round((float)$item['unit_cost'] * (1 - $descuento / 100), 2);
            if ($costo <= 0 && (float)($item['line_total'] ?? 0) > 0) {
                $costo = round((float)$item['line_total'] / $cantidad, 2);
            }
            if ($costo < 0) {
                $costo = 0.0;
            }

            $lineas[] = [
                'product_id' => $productoId,
                'product_name' => (string)$item['description'],
                'quantity' => $cantidad,
                'unit_cost' => $costo,
                'tax_rate' => (float)($item['tax_rate'] ?? 0),
                'lot_code' => (string)($item['lot_code'] ?? ''),
                'expiry_date' => $item['expiry_date'] ?? null,
            ];

            $this->links->link($productoId, $supplierId, [
                'supplier_sku' => (string)($item['supplier_sku'] ?? ''),
                'supplier_product_name' => (string)$item['description'],
                'last_cost' => $costo,
                'last_purchase_date' => (string)($import['invoice_date'] ?? date('Y-m-d')),
            ]);

            if (!empty($item['sale_price']) && (float)$item['sale_price'] > 0) {
                $this->query(
                    "UPDATE products SET sale_price = ? WHERE id = ?",
                    [round((float)$item['sale_price'], 2), $productoId]
                );
            }
        }

        if (empty($lineas)) {
            $detalle = $sinCantidad > 0
                ? " {$sinCantidad} línea(s) con cantidad 0 o sin costo/total legible."
                : '';
            throw new \RuntimeException(
                'No hay líneas válidas para ingresar al inventario.'
                . $detalle
                . ' En Revisar, complete Cant. y Costo u. (o Total) en cada línea antes de confirmar.'
            );
        }

        $purchasing = new PurchasingService($this->db);
        $fechaFactura = (string)($import['invoice_date'] ?? date('Y-m-d'));
        $fechaBodega = $this->normalizeDate($warehouseDate) ?? $fechaFactura;

        $orderId = $purchasing->createOrder(
            $supplierId,
            $fechaFactura,
            $fechaBodega,
            null,
            'Factura importada #' . $importId,
            $lineas,
            'ordered',
            0.0,
            $import['due_date'] ?? null,
            $fechaFactura,
            $import['invoice_number'] ?? null,
            false // no forzar descuento aliado sobre el total de factura
        );

        $this->query(
            "UPDATE purchase_orders SET origin = 'invoice_import', invoice_import_id = ? WHERE id = ?",
            [$importId, $orderId]
        );

        $purchasing->receiveOrder(
            $orderId,
            $fechaBodega,
            (string)($import['invoice_number'] ?? ''),
            (string)($import['payment_mode'] ?? 'payable'),
            'Ingreso por factura importada'
        );

        try {
            (new AccountingService($this->db))->postPurchaseOrder($orderId);
        } catch (\Throwable $e) {
            error_log('Contabilidad OC ' . $orderId . ' desde factura ' . $importId . ': ' . $e->getMessage());
        }

        $cashNote = '';
        try {
            $cashNote = $purchasing->syncCashForReceivedOrder($orderId);
        } catch (\Throwable $e) {
            error_log('Caja OC ' . $orderId . ' desde factura ' . $importId . ': ' . $e->getMessage());
        }

        $this->query(
            "UPDATE purchase_invoice_imports
             SET status = 'confirmed', purchase_order_id = ?, confirmed_at = NOW()
             WHERE id = ?",
            [$orderId, $importId]
        );

        return [
            'order_id' => $orderId,
            'created_products' => $creados,
            'received_items' => count($lineas),
            'skipped' => $omitidos,
            'cash_note' => $cashNote,
        ];
    }

    /**
     * Crea el producto de una línea sin coincidencia. El precio de venta se
     * estima con el margen configurado para que quede vendible de inmediato.
     */
    private function createProductFromLine(array $item): int
    {
        $nombre = trim((string)$item['description']);
        if ($nombre === '') {
            throw new \RuntimeException('Una línea sin descripción no puede crear producto');
        }

        $codigo = trim((string)($item['supplier_sku'] ?? ''));
        if ($codigo === '' || $this->codeExists($codigo)) {
            $codigo = 'SKU-' . strtoupper(bin2hex(random_bytes(3)));
        }

        $costo = round((float)$item['unit_cost'], 2);
        $precioVenta = !empty($item['sale_price']) && (float)$item['sale_price'] > 0
            ? round((float)$item['sale_price'], 2)
            : round($costo * (1 + $this->defaultMarginPct() / 100), 2);

        $this->query(
            "INSERT INTO products
                (code, name, product_type, purchase_price, sale_price, stock, min_stock, unit,
                 track_lots, expiry_alert_days, created_by, source_channel)
             VALUES (?, ?, 'product', ?, ?, 0, ?, 'UNIDAD', ?, 30, ?, 'manual')",
            [
                substr($codigo, 0, 255),
                substr($nombre, 0, 255),
                $costo,
                $precioVenta,
                5,
                !empty($item['expiry_date']) ? 1 : 0,
                $_SESSION['tenant_user_id'] ?? null,
            ]
        );

        return (int)$this->db->lastInsertId();
    }

    private function codeExists(string $code): bool
    {
        return (bool)$this->query("SELECT 1 FROM products WHERE code = ? LIMIT 1", [$code])->fetchColumn();
    }

    private function defaultMarginPct(): float
    {
        try {
            $valor = $this->query(
                "SELECT setting_value FROM settings WHERE setting_key = 'default_margin_pct' LIMIT 1"
            )->fetchColumn();
            $margen = (float)$valor;
            return $margen > 0 ? min(500, $margen) : 30.0;
        } catch (\Throwable $e) {
            return 30.0;
        }
    }

    private function refreshTotals(int $importId): void
    {
        $this->query(
            "UPDATE purchase_invoice_imports i
             SET i.subtotal = COALESCE((
                    SELECT SUM(ROUND(it.quantity * it.unit_cost * (1 - it.discount_pct / 100), 2))
                    FROM purchase_invoice_import_items it
                    WHERE it.import_id = i.id AND it.row_action <> 'skip'
                 ), 0),
                 i.tax_total = COALESCE((
                    SELECT SUM(ROUND(it.quantity * it.unit_cost * (1 - it.discount_pct / 100) * (it.tax_rate / 100), 2))
                    FROM purchase_invoice_import_items it
                    WHERE it.import_id = i.id AND it.row_action <> 'skip'
                 ), 0),
                 i.total = COALESCE((
                    SELECT SUM(it.line_total)
                    FROM purchase_invoice_import_items it
                    WHERE it.import_id = i.id AND it.row_action <> 'skip'
                 ), 0)
             WHERE i.id = ?",
            [$importId]
        );
    }

    // ------------------------------------------------------------- utilidades

    private function findExpiryInLine(string $linea): ?string
    {
        // dd/mm/yyyy, dd-mm-yyyy y yyyy-mm-dd son los formatos habituales.
        if (preg_match('/(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/', $linea, $m)) {
            return $this->buildDate((int)$m[1], (int)$m[2], (int)$m[3]);
        }
        if (preg_match('/(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})/', $linea, $m)) {
            return $this->buildDate((int)$m[3], (int)$m[2], (int)$m[1]);
        }
        return null;
    }

    private function buildDate(int $y, int $m, int $d): ?string
    {
        if ($y < 2000 || $y > 2100 || $m < 1 || $m > 12 || $d < 1 || $d > 31) {
            return null;
        }
        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }

    private function csvValue(array $campos, array $mapa, string $clave): string
    {
        if (!isset($mapa[$clave])) {
            return '';
        }
        return (string)($campos[$mapa[$clave]] ?? '');
    }

    /**
     * Convierte números en formato local o inglés a float.
     * "1.234,50" y "1,234.50" deben dar el mismo resultado.
     */
    private function toNumber(string $valor): float
    {
        $valor = trim(html_entity_decode($valor, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Quitar moneda y basura, conservar dígitos/separadores/signo.
        $valor = preg_replace('/[^\d.,\-]/', '', $valor) ?? '';
        $valor = trim($valor);
        if ($valor === '' || $valor === '-' || $valor === '.' || $valor === ',') {
            return 0.0;
        }

        $ultimaComa = strrpos($valor, ',');
        $ultimoPunto = strrpos($valor, '.');

        if ($ultimaComa !== false && $ultimoPunto !== false) {
            // El separador decimal es el que aparece más a la derecha.
            if ($ultimaComa > $ultimoPunto) {
                $valor = str_replace('.', '', $valor);
                $valor = str_replace(',', '.', $valor);
            } else {
                $valor = str_replace(',', '', $valor);
            }
        } elseif ($ultimaComa !== false) {
            $decimales = strlen($valor) - $ultimaComa - 1;
            // "1,5" / "1,50" decimal; "1,234" miles (3 dígitos) salvo que sea único grupo corto.
            $valor = ($decimales <= 2)
                ? str_replace(',', '.', $valor)
                : str_replace(',', '', $valor);
        } elseif ($ultimoPunto !== false) {
            $decimales = strlen($valor) - $ultimoPunto - 1;
            $puntos = substr_count($valor, '.');
            // "1.500" / "12.500.000" formato local miles; "1.5" / "1.50" decimal.
            if ($puntos > 1 || ($decimales === 3 && strlen($valor) > 4)) {
                $valor = str_replace('.', '', $valor);
            }
        }

        return round((float)$valor, 4);
    }

    private function slug(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));
        $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
        return preg_replace('/[^a-z0-9]/', '', $texto) ?? '';
    }

    private function toUtf8(string $texto): string
    {
        if ($texto === '') {
            return '';
        }
        if (str_starts_with($texto, "\xEF\xBB\xBF")) {
            $texto = substr($texto, 3);
        }
        if (mb_check_encoding($texto, 'UTF-8')) {
            return $texto;
        }
        foreach (['Windows-1252', 'ISO-8859-1', 'UTF-16LE', 'UTF-16BE'] as $from) {
            $converted = @mb_convert_encoding($texto, 'UTF-8', $from);
            if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }
        return mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
    }

    private function normalizeDate(?string $date): ?string
    {
        $date = trim((string)$date);
        if ($date === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'm/d/Y'] as $formato) {
            $dt = \DateTimeImmutable::createFromFormat($formato, $date);
            if ($dt && $dt->format($formato) === $date) {
                return $dt->format('Y-m-d');
            }
        }
        return null;
    }
}
