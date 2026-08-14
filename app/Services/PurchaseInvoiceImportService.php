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
 * Orígenes soportados: CSV, Excel (xlsx/xls), Word (docx), XML factura electrónica,
 * texto pegado, PDF con capa de texto, fotos/CamScanner y, con IA, escaneos.
 */
class PurchaseInvoiceImportService
{
    private const MAX_UPLOAD_BYTES = 12582912; // 12MB
    private const ALLOWED_EXTENSIONS = [
        'csv', 'txt', 'pdf', 'jpg', 'jpeg', 'png', 'webp', 'xml', 'zip',
        'xlsx', 'xlsm', 'xls', 'ods', 'docx',
    ];
    private const SPREADSHEET_EXTENSIONS = ['csv', 'xlsx', 'xlsm', 'xls', 'ods'];

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
            $source = in_array($stored['extension'], self::SPREADSHEET_EXTENSIONS, true) ? 'csv' : 'text';
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

        if ($stored && in_array($ext, self::SPREADSHEET_EXTENSIONS, true)) {
            $items = $this->parseSpreadsheet($stored['path'], $ext);
            $label = strtoupper($ext === 'csv' ? 'CSV' : 'Excel');
            $notas[] = count($items) . ' línea(s) leídas de ' . $label;
            if ($items === [] && $ext !== 'csv') {
                $notas[] = 'No se detectaron filas de producto en la hoja; se intentará lectura por texto/IA';
                if ($ext === 'xls') {
                    $notas[] = 'Si el Excel es formato antiguo (.xls binario), guárdelo como .xlsx o CSV';
                }
            }
        }

        if ($stored && in_array($ext, ['xml', 'zip'], true) && empty($items)) {
            $ubl = $this->parseUblInvoiceXml($stored['path'], $ext);
            if ($ubl['items'] !== []) {
                $header = $ubl;
                $items = $ubl['items'];
                $source = 'text';
                $notas[] = count($items) . ' línea(s) leídas de factura electrónica DIAN/UBL';
            } elseif ($ext === 'xml' || $ext === 'zip') {
                $notas[] = 'XML/ZIP DIAN sin líneas de producto legibles; se intentará texto/IA';
            }
        }

        $aiService = null;
        $useAi = !empty($meta['use_ai']);

        // Órdenes/facturas con "ID: … / EAN: …" (ej. Recamier): parseo estructurado antes que IA genérica.
        if (empty($items) && $rawText !== '' && !$isImageFile && !$pdfLooksScanned
            && preg_match('/\bEAN\s*:/i', $rawText)) {
            $items = $this->parseIdEanInvoiceText($rawText);
            if ($items !== []) {
                $source = 'text';
                $notas[] = count($items) . ' línea(s) con ID/EAN detectados';
            }
        }

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
                if (preg_match('/\bEAN\s*:/i', $rawText)) {
                    $items = $this->parseIdEanInvoiceText($rawText);
                }
                if ($items === []) {
                    $items = $this->parseText($rawText);
                }
                if ($items) {
                    $withEan = count(array_filter($items, static fn($i) => trim((string)($i['barcode'] ?? '')) !== ''));
                    $notas[] = count($items) . ' línea(s) detectadas por patrones'
                        . ($withEan > 0 ? " ({$withEan} con EAN)" : '');
                } elseif ($ext === 'pdf') {
                    $notas[] = 'No se detectaron líneas de producto legibles en el PDF; active IA o use CSV/XML DIAN';
                }
            }
        }

        // Enriquecer ID/EAN si quedaron dentro de la descripción.
        $items = array_map(fn(array $it) => $this->enrichLineIds($it), $items);

        if ($items === [] && !$stored && $rawText === '') {
            $notas[] = 'Borrador manual sin archivo: agregue productos en la revisión (buscar / escanear)';
            $source = 'manual';
        } elseif ($items === [] && empty($notas)) {
            $notas[] = 'Sin líneas detectadas: puede agregar productos manualmente en la revisión';
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
        $earlyPct = isset($meta['early_payment_discount_pct']) && $meta['early_payment_discount_pct'] !== ''
            ? max(0, min(100, round((float)$meta['early_payment_discount_pct'], 2)))
            : 0.0;

        $this->query(
            "INSERT INTO purchase_invoice_imports
                (supplier_id, invoice_number, invoice_date, due_date, source, file_path, file_mime,
                 original_name, raw_text, parse_notes, payment_mode, early_payment_discount_pct, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
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
                $earlyPct,
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
                (import_id, line_no, raw_line, supplier_sku, barcode, description, quantity, unit_cost,
                 tax_rate, discount_pct, line_total, matched_product_id, match_type, row_action,
                 lot_code, expiry_date, sale_price, confidence)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
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
            $barcode = $this->normalizeBarcodeValue((string)($item['barcode'] ?? $item['ean'] ?? ''));
            // Si el "SKU" parece EAN y no hay barcode, usarlo como EAN.
            if ($barcode === '' && $this->looksLikeBarcode($sku)) {
                $barcode = $this->normalizeBarcodeValue($sku);
            }
            $match = $this->links->matchInvoiceLine($supplierId, $sku, $descripcion, $barcode);
            // Auto-relacionar solo con identificadores fuertes (EAN / SKU / código / nombre exacto).
            $autoMatch = $match && (int)($match['confidence'] ?? 0) >= 80;
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
                $barcode !== '' ? $barcode : null,
                substr($descripcion, 0, 255),
                $cantidad,
                $costo,
                $impuesto,
                $descuento,
                $totalLinea,
                $autoMatch ? ($match['product_id'] ?? null) : null,
                $autoMatch ? ($match['match_type'] ?? 'none') : 'none',
                $autoMatch ? 'add_stock' : 'create_product',
                substr(trim((string)($item['lot_code'] ?? '')), 0, 60) ?: null,
                $this->normalizeDate($item['expiry_date'] ?? null),
                isset($item['sale_price']) && (float)$item['sale_price'] > 0 ? round((float)$item['sale_price'], 2) : null,
                $autoMatch ? (int)($match['confidence'] ?? 0) : 0,
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
            throw new \RuntimeException('El archivo supera los 12MB permitidos');
        }

        $original = (string)($file['name'] ?? 'factura');
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \RuntimeException('Formato no soportado. Use CSV, Excel, Word, PDF, XML, TXT o imagen');
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
        if ($extension === 'docx') {
            return $this->extractDocxText($absolute);
        }
        if (in_array($extension, ['xlsx', 'xlsm', 'xls', 'ods'], true)) {
            // Texto tabular auxiliar para IA si el parseo por filas falla.
            return $this->spreadsheetToPlainText($absolute, $extension);
        }
        if ($extension === 'zip') {
            return $this->extractZipTextPreview($absolute);
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
        // Separar bloques típicos de órdenes (Recamier y similares).
        $texto = preg_replace('/\s*(ID\s*:\s*)/iu', "\n$1", $texto) ?? $texto;
        $texto = preg_replace('/\s*(\/\s*EAN\s*:)/iu', ' $1', $texto) ?? $texto;
        // EAN-13 pegado a la cantidad: ...84210.0 → ...8421 0.0 (solo 13 dígitos; no partir EAN válidos)
        $texto = preg_replace('/(EAN\s*:\s*)(\d{13})(\d)/iu', '$1$2 $3', $texto) ?? $texto;
        // Cant/bonif pegados: 0.03.0 → 0.0 3.0
        $texto = preg_replace('/(\d+\.0)(\d+\.0)/u', '$1 $2', $texto) ?? $texto;
        // Recuperar espacios perdidos en PDF: letra↔dígito y camelCase.
        $texto = preg_replace('/([a-zà-ÿ])([A-ZÁ-Ý])/u', '$1 $2', $texto) ?? $texto;
        $texto = preg_replace('/([A-Za-zÁ-ý])(\d)/u', '$1 $2', $texto) ?? $texto;
        $texto = preg_replace('/(\d)([A-Za-zÁ-ý])/u', '$1 $2', $texto) ?? $texto;
        $texto = preg_replace('/([\$])(\d)/u', '$1 $2', $texto) ?? $texto;
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
     * Lee CSV o Excel (xlsx/xls/ods) como filas de factura.
     *
     * @return list<array<string,mixed>>
     */
    public function parseSpreadsheet(string $relativeOrAbsolutePath, string $extension): array
    {
        $extension = strtolower($extension);
        if ($extension === 'csv') {
            return $this->parseCsv($relativeOrAbsolutePath);
        }

        $ruta = is_readable($relativeOrAbsolutePath)
            ? $relativeOrAbsolutePath
            : dirname(__DIR__, 2) . '/public/' . $relativeOrAbsolutePath;
        if (!is_readable($ruta)) {
            return [];
        }

        $matrix = match ($extension) {
            'xlsx', 'xlsm' => $this->readXlsxMatrix($ruta),
            'ods' => $this->readOdsMatrix($ruta),
            'xls' => $this->readXlsMatrix($ruta),
            default => [],
        };
        if ($matrix === []) {
            return [];
        }

        return $this->matrixToInvoiceItems($matrix);
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
        $sku = $this->csvValue($campos, $mapa, 'supplier_sku');
        $barcode = $this->normalizeBarcodeValue($this->csvValue($campos, $mapa, 'barcode'));
        if ($barcode === '' && $this->looksLikeBarcode($sku)) {
            $barcode = $this->normalizeBarcodeValue($sku);
        }
        $fila = [
            'raw_line' => implode($separador, $campos),
            'supplier_sku' => $sku,
            'barcode' => $barcode,
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
                'id', 'idd', 'item_id', 'product_id', 'id_producto', 'codigointerno',
                'codinterno', 'sku_interno', 'parte', 'partnumber', 'pn',
            ],
            'barcode' => [
                'ean', 'barcode', 'codigo_barras', 'codigobarras', 'barras', 'upc', 'gtin',
                'ean13', 'ean8', 'codigo_ean', 'codigodebarras', 'bar_code', 'cbarras',
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
            if ($normalizado === '' || in_array($normalizado, ['tipo', 'estado', 'n', 'no', 'num', 'numero'], true)) {
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
     * Órdenes/facturas con bloque "ID: 048391 / EAN: 7702113048391"
     * (Recamier y formatos similares). Evita tomar 5.0 / 6.35 del nombre como cantidad.
     *
     * @return list<array<string,mixed>>
     */
    public function parseIdEanInvoiceText(string $texto): array
    {
        $texto = $this->normalizePdfExtractedText($texto);
        if (!preg_match('/\bEAN\s*:/i', $texto)) {
            return [];
        }

        // Unificar saltos; mantener ID/EAN reconocibles aunque el PDF pegue todo.
        $texto = preg_replace('/\s+/u', ' ', $texto) ?? $texto;
        $filas = [];

        if (!preg_match_all(
            '/ID\s*:\s*([A-Z0-9][A-Z0-9\.\-]{1,40})\s*\/\s*EAN\s*:\s*(\d{13}|\d{8,14})(?!\d)/iu',
            $texto,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            return [];
        }

        $count = count($matches[0]);
        for ($i = 0; $i < $count; $i++) {
            $sku = trim((string)$matches[1][$i][0]);
            $ean = $this->normalizeBarcodeValue((string)$matches[2][$i][0]);
            $matchStart = (int)$matches[0][$i][1];
            $matchLen = strlen((string)$matches[0][$i][0]);
            $matchEnd = $matchStart + $matchLen;

            $prevEnd = $i === 0 ? 0 : ((int)$matches[0][$i - 1][1] + strlen((string)$matches[0][$i - 1][0]));
            // Nombre: texto entre el bloque ID/EAN anterior y este (sin los montos del ítem previo).
            $before = trim(substr($texto, $prevEnd, max(0, $matchStart - $prevEnd)));
            // Montos: hasta 100 caracteres después del EAN (o hasta el siguiente ID).
            $nextStart = $i + 1 < $count ? (int)$matches[0][$i + 1][1] : strlen($texto);
            $after = trim(substr($texto, $matchEnd, min(120, max(0, $nextStart - $matchEnd))));

            // Quitar del "before" los montos del producto anterior (quedan delante del nombre).
            $name = $before;
            $name = preg_replace('/^(?:[\$]?\d[\d\.,]*\s*)+/u', '', $name) ?? $name;
            $name = preg_replace('/.*?\b(?:PRODUCTO|DETALLE|DESCRIPCION|DESCRIPCIÓN)\b/iu', '', $name) ?? $name;
            $name = preg_replace('/\b(?:IMAGEN|CANT\.?|BONIF\.?|VLR\.?\s*UNIT\.?|VLR\.?\s*TOTAL|ORDEN DE PEDIDO)\b/iu', ' ', $name) ?? $name;
            $name = preg_replace('/\bRECAMIER\b.*?\bS\.?A\.?\b/iu', ' ', $name) ?? $name;
            $name = preg_replace('/^(?:S\.?A\.?|SAS|LTDA)\s+/iu', '', trim($name)) ?? $name;
            if (preg_match('/([A-Za-zÁ-ý][\p{L}\d\s\.\,\-\/%xX]{6,140})$/u', $name, $nm)) {
                $name = trim($nm[1]);
            }
            $name = $this->softenJammedWords(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
            if (!$this->isReadableProductDescription($name)) {
                $name = 'Producto ' . ($sku !== '' ? $sku : $ean);
            }

            $amounts = $this->extractQtyCostFromTail($after);
            // Bonificados: CANT 0 + BONIF > 0 → usar bonificación como cantidad de ingreso.
            if ($amounts['quantity'] <= 0 && $amounts['bonus'] > 0) {
                $amounts['quantity'] = $amounts['bonus'];
            }
            if ($amounts['quantity'] <= 0 && $amounts['unit_cost'] <= 0 && $amounts['line_total'] <= 0) {
                $amounts['quantity'] = 1;
            }

            $filas[] = [
                'raw_line' => 'ID: ' . $sku . ' / EAN: ' . $ean . ' ' . $name,
                'supplier_sku' => $sku,
                'barcode' => $ean,
                'description' => $name,
                'quantity' => $amounts['quantity'],
                'unit_cost' => $amounts['unit_cost'],
                'tax_rate' => 0.0,
                'discount_pct' => 0.0,
                'line_total' => $amounts['line_total'],
                'lot_code' => '',
                'expiry_date' => null,
                'sale_price' => 0.0,
            ];
        }

        return $filas;
    }

    /**
     * @return array{quantity:float,bonus:float,unit_cost:float,line_total:float}
     */
    private function extractQtyCostFromTail(string $tail): array
    {
        $out = ['quantity' => 0.0, 'bonus' => 0.0, 'unit_cost' => 0.0, 'line_total' => 0.0];
        $tail = trim($tail);
        if ($tail === '') {
            return $out;
        }

        // Preferir montos con $ (costo / total).
        $money = [];
        if (preg_match_all('/\$\s*([\d.,]+)/u', $tail, $mm)) {
            foreach ($mm[1] as $raw) {
                $n = $this->toNumber($raw);
                if ($n > 0) {
                    $money[] = $n;
                }
            }
        }
        if (count($money) >= 2) {
            $out['unit_cost'] = $money[count($money) - 2];
            $out['line_total'] = $money[count($money) - 1];
        } elseif (count($money) === 1) {
            $out['line_total'] = $money[0];
            $out['unit_cost'] = $money[0];
        }

        // Cantidades tipo 2.0 0.0 (cant / bonif) antes de los $
        $beforeMoney = preg_split('/\$/u', $tail, 2)[0] ?? $tail;
        // Preferir el patrón Recamier: N.N N.N justo al inicio del tramo.
        if (preg_match('/^\s*(\d+[.,]\d+)\s+(\d+[.,]\d+)/u', $beforeMoney, $pair)) {
            $out['quantity'] = $this->toNumber($pair[1]);
            $out['bonus'] = $this->toNumber($pair[2]);
        } elseif (preg_match_all('/\b(\d+[.,]\d+)\b/u', $beforeMoney, $qm)) {
            $nums = array_map([$this, 'toNumber'], $qm[1]);
            if (isset($nums[0])) {
                $out['quantity'] = $nums[0];
            }
            if (isset($nums[1])) {
                $out['bonus'] = $nums[1];
            }
        }

        if ($out['unit_cost'] <= 0 && $out['line_total'] > 0 && $out['quantity'] > 0) {
            $out['unit_cost'] = round($out['line_total'] / $out['quantity'], 2);
        }
        if ($out['line_total'] <= 0 && $out['unit_cost'] > 0 && $out['quantity'] > 0) {
            $out['line_total'] = round($out['unit_cost'] * $out['quantity'], 2);
        }

        return $out;
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function enrichLineIds(array $item): array
    {
        $blob = trim(
            (string)($item['description'] ?? '') . ' ' .
            (string)($item['raw_line'] ?? '') . ' ' .
            (string)($item['supplier_sku'] ?? '') . ' ' .
            (string)($item['barcode'] ?? '')
        );

        if (preg_match('/ID\s*:\s*([A-Z0-9][A-Z0-9\.\-]{1,40})\s*\/\s*EAN\s*:\s*(\d{8,14})/iu', $blob, $m)) {
            if (trim((string)($item['supplier_sku'] ?? '')) === '') {
                $item['supplier_sku'] = trim($m[1]);
            }
            if (trim((string)($item['barcode'] ?? '')) === '') {
                $item['barcode'] = $this->normalizeBarcodeValue($m[2]);
            }
            $item['description'] = trim(preg_replace(
                '/ID\s*:\s*[A-Z0-9\.\-]+\s*\/\s*EAN\s*:\s*\d{8,14}/iu',
                '',
                (string)($item['description'] ?? '')
            ) ?? '');
        } elseif (preg_match('/EAN\s*:\s*(\d{8,14})/iu', $blob, $m)) {
            if (trim((string)($item['barcode'] ?? '')) === '') {
                $item['barcode'] = $this->normalizeBarcodeValue($m[1]);
            }
        } elseif (preg_match('/\b(77\d{11})\b/', $blob, $m)) {
            // EAN-13 Colombia (prefijo 770–773) suelto en el texto
            if (trim((string)($item['barcode'] ?? '')) === '') {
                $item['barcode'] = $this->normalizeBarcodeValue($m[1]);
            }
        }

        $item['description'] = $this->softenJammedWords(trim((string)($item['description'] ?? '')));
        return $item;
    }

    private function softenJammedWords(string $text): string
    {
        if ($text === '' || str_contains($text, ' ')) {
            // Aun con espacios, separar camel residual.
            $text = preg_replace('/([a-zà-ÿ])([A-ZÁ-Ý])/u', '$1 $2', $text) ?? $text;
            return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        }
        $text = preg_replace('/([a-zà-ÿ])([A-ZÁ-Ý])/u', '$1 $2', $text) ?? $text;
        $text = preg_replace('/([A-Za-zÁ-ý])(\d)/u', '$1 $2', $text) ?? $text;
        $text = preg_replace('/(\d)([A-Za-zÁ-ý])/u', '$1 $2', $text) ?? $text;
        // Tokens conocidos en belleza/cosmético
        $text = preg_replace('/\bSALON\s*IN\b/iu', 'SALON IN', $text) ?? $text;
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Detecta líneas de factura en texto libre.
     * Busca el patrón habitual: [código] descripción cantidad costo [total].
     *
     * @return list<array<string,mixed>>
     */
    public function parseText(string $texto): array
    {
        $idEan = $this->parseIdEanInvoiceText($texto);
        if ($idEan !== []) {
            return $idEan;
        }

        $filas = [];
        foreach (preg_split('/\r\n|\r|\n/', $texto) ?: [] as $linea) {
            $linea = trim(preg_replace('/\s{2,}/', ' ', $linea) ?? '');
            if ($linea === '' || mb_strlen($linea) < 8) {
                continue;
            }
            if (preg_match('/^(subtotal|total|iva|impuesto|descuento|neto|factura|nit|cliente|proveedor|fecha|pedido|vendedor)\b/i', $linea)) {
                continue;
            }

            $sku = '';
            $barcode = '';
            $work = $linea;
            if (preg_match('/ID\s*:\s*([A-Z0-9\.\-]+)\s*\/\s*EAN\s*:\s*(\d{8,14})/iu', $work, $im)) {
                $sku = trim($im[1]);
                $barcode = $this->normalizeBarcodeValue($im[2]);
                $work = trim(str_replace($im[0], ' ', $work));
            } elseif (preg_match('/EAN\s*:\s*(\d{8,14})/iu', $work, $im)) {
                $barcode = $this->normalizeBarcodeValue($im[1]);
                $work = trim(str_replace($im[0], ' ', $work));
            }

            // Quitar EAN-13 del pool de números para no usarlos como cantidad/costo.
            $workForNums = preg_replace('/\b\d{8,14}\b/', ' ', $work) ?? $work;
            $workForNums = preg_replace('/\$\s*/', '$', $workForNums) ?? $workForNums;

            if (!preg_match_all('/\$?\s*-?\d[\d.,]*/', $workForNums, $numeros)) {
                continue;
            }
            $tokens = $numeros[0];
            $valores = array_map([$this, 'toNumber'], $tokens);
            if (count($valores) < 2) {
                continue;
            }

            $firstTok = ltrim($tokens[0], '$ ');
            $posicionPrimerNumero = mb_strpos($work, $firstTok);
            $descripcion = trim(mb_substr($work, 0, $posicionPrimerNumero === false ? null : $posicionPrimerNumero));
            $descripcion = $this->softenJammedWords($descripcion);

            if ($barcode === '' && preg_match('/\b(77\d{11})\b/', $linea, $eanMatch)) {
                $barcode = $this->normalizeBarcodeValue($eanMatch[1]);
            }
            if ($sku === '' && preg_match('/^([A-Z0-9][A-Z0-9\-\.\/]{2,})\s+(.+)$/u', $descripcion, $partes)) {
                $sku = $partes[1];
                $descripcion = trim($partes[2]);
                if ($barcode === '' && $this->looksLikeBarcode($sku)) {
                    $barcode = $this->normalizeBarcodeValue($sku);
                }
            }
            if (!$this->isReadableProductDescription($descripcion)) {
                continue;
            }

            // Preferir montos con $ como costo/total; cantidades = números sin $.
            $moneyIdx = [];
            foreach ($tokens as $i => $tok) {
                if (str_contains($tok, '$')) {
                    $moneyIdx[] = $i;
                }
            }
            $cantidad = 0.0;
            $costo = 0.0;
            $total = 0.0;
            if (count($moneyIdx) >= 2) {
                $costo = $valores[$moneyIdx[count($moneyIdx) - 2]];
                $total = $valores[$moneyIdx[count($moneyIdx) - 1]];
                foreach ($valores as $i => $v) {
                    if (!in_array($i, $moneyIdx, true) && $v > 0 && $v < 10000) {
                        $cantidad = $v;
                        break;
                    }
                }
            } else {
                $cantidad = $valores[0] > 0 ? $valores[0] : 1.0;
                for ($i = 1; $i < count($valores); $i++) {
                    if ($valores[$i] > 0) {
                        $costo = $valores[$i];
                        break;
                    }
                }
                $total = end($valores) ?: 0.0;
            }
            if ($cantidad <= 0) {
                $cantidad = 1.0;
            }
            if ($costo <= 0 && $total <= 0) {
                continue;
            }

            $filas[] = [
                'raw_line' => $linea,
                'supplier_sku' => $sku,
                'barcode' => $barcode,
                'description' => $descripcion,
                'quantity' => $cantidad,
                'unit_cost' => $costo,
                'tax_rate' => 0.0,
                'discount_pct' => 0.0,
                'line_total' => $total,
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
            $sku = trim((string)($item['supplier_sku'] ?? $item['sku'] ?? $item['id'] ?? $item['codigo'] ?? ''));
            $barcode = $this->normalizeBarcodeValue((string)($item['barcode'] ?? $item['ean'] ?? $item['gtin'] ?? $item['upc'] ?? ''));
            if ($barcode === '' && $this->looksLikeBarcode($sku)) {
                $barcode = $this->normalizeBarcodeValue($sku);
            }
            $salida[] = [
                'raw_line' => null,
                'supplier_sku' => $sku,
                'barcode' => $barcode,
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
                expiry_date = ?, sale_price = ?, supplier_sku = ?, barcode = ?
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
                isset($row['sale_price']) && $this->toNumber((string)($row['sale_price'] ?? '')) > 0
                    ? round($this->toNumber((string)$row['sale_price']), 2)
                    : null,
                substr(trim((string)($row['supplier_sku'] ?? '')), 0, 120) ?: null,
                $this->normalizeBarcodeValue((string)($row['barcode'] ?? '')) ?: null,
                $itemId,
                $importId,
            ]);
        }

        $this->refreshTotals($importId);
    }

    /**
     * Agrega una línea manual (producto del catálogo) al borrador de factura.
     * Permite escanear o buscar por nombre además de lo leído del archivo.
     *
     * @return array<string,mixed> Línea creada
     */
    public function addManualItem(
        int $importId,
        int $productId,
        float $quantity = 1.0,
        ?float $unitCost = null,
        ?float $taxRate = null,
        ?float $salePrice = null
    ): array {
        $import = $this->query(
            "SELECT id, status, supplier_id FROM purchase_invoice_imports WHERE id = ? LIMIT 1",
            [$importId]
        )->fetch();
        if (!$import || ($import['status'] ?? '') !== 'pending') {
            throw new \RuntimeException('Solo se pueden agregar líneas a facturas pendientes de confirmar');
        }

        $product = $this->query(
            "SELECT id, code, barcode, name, purchase_price, sale_price
             FROM products
             WHERE id = ? AND status = 'active' AND COALESCE(product_type, 'product') = 'product'
             LIMIT 1",
            [$productId]
        )->fetch();
        if (!$product) {
            throw new \RuntimeException('Producto no encontrado o inactivo');
        }

        $qty = round(max(0.001, $quantity), 3);
        $cost = $unitCost !== null && $unitCost >= 0
            ? round($unitCost, 2)
            : round((float)$product['purchase_price'], 2);
        $tax = $taxRate !== null ? round(max(0, min(100, $taxRate)), 2) : 0.0;

        // Si el mismo producto ya está en el borrador, suma cantidad (evita filas duplicadas).
        $existing = $this->query(
            "SELECT id, quantity, unit_cost, tax_rate, discount_pct
             FROM purchase_invoice_import_items
             WHERE import_id = ? AND matched_product_id = ? AND row_action = 'add_stock'
             ORDER BY id DESC LIMIT 1",
            [$importId, $productId]
        )->fetch();
        if ($existing) {
            $newQty = round((float)$existing['quantity'] + $qty, 3);
            $useCost = $cost > 0 ? $cost : (float)$existing['unit_cost'];
            $useTax = (float)$existing['tax_rate'];
            $dst = (float)$existing['discount_pct'];
            $lineTotal = round($newQty * $useCost * (1 - $dst / 100) * (1 + $useTax / 100), 2);
            $this->query(
                "UPDATE purchase_invoice_import_items
                 SET quantity = ?, unit_cost = ?, line_total = ?
                 WHERE id = ? AND import_id = ?",
                [$newQty, $useCost, $lineTotal, (int)$existing['id'], $importId]
            );
            $this->refreshTotals($importId);
            $row = $this->query(
                "SELECT * FROM purchase_invoice_import_items WHERE id = ? LIMIT 1",
                [(int)$existing['id']]
            )->fetch();
            return is_array($row) ? $row : ['id' => (int)$existing['id']];
        }

        $lineTotal = round($qty * $cost * (1 + $tax / 100), 2);
        $sale = $salePrice !== null && $salePrice > 0
            ? round($salePrice, 2)
            : (isset($product['sale_price']) && (float)$product['sale_price'] > 0
                ? round((float)$product['sale_price'], 2)
                : null);

        $lineNo = (int)$this->query(
            "SELECT COALESCE(MAX(line_no), 0) + 1 FROM purchase_invoice_import_items WHERE import_id = ?",
            [$importId]
        )->fetchColumn();

        $this->query(
            "INSERT INTO purchase_invoice_import_items
                (import_id, line_no, raw_line, supplier_sku, barcode, description, quantity, unit_cost,
                 tax_rate, discount_pct, line_total, matched_product_id, match_type, row_action,
                 lot_code, expiry_date, sale_price, confidence)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 'manual', 'add_stock', NULL, NULL, ?, 100)",
            [
                $importId,
                $lineNo,
                'Manual / escáner',
                substr(trim((string)($product['code'] ?? '')), 0, 120) ?: null,
                $this->normalizeBarcodeValue((string)($product['barcode'] ?? '')) ?: null,
                substr((string)$product['name'], 0, 255),
                $qty,
                $cost,
                $tax,
                $lineTotal,
                $productId,
                $sale,
            ]
        );

        $itemId = (int)$this->db->lastInsertId();
        $this->refreshTotals($importId);

        $row = $this->query(
            "SELECT * FROM purchase_invoice_import_items WHERE id = ? LIMIT 1",
            [$itemId]
        )->fetch();

        return is_array($row) ? $row : ['id' => $itemId];
    }

    /**
     * Casa el borrador de factura con una OC abierta y carga/mezcla sus productos.
     * No pisa cantidades ni costos que ya traiga la factura (solo rellena ceros y
     * agrega ítems de la OC que falten).
     *
     * @return array{import:array,items:array,added:int,updated:int,message:string}
     */
    public function linkOrderAndLoadItems(int $importId, int $orderId): array
    {
        $data = $this->get($importId);
        if (!$data || ($data['import']['status'] ?? '') !== 'pending') {
            throw new \RuntimeException('Solo se puede casar una OC en facturas pendientes de confirmar');
        }

        if ($orderId <= 0) {
            $this->updateHeader($importId, ['linked_order_id' => null]);
            $fresh = $this->get($importId);
            return [
                'import' => $fresh['import'] ?? $data['import'],
                'items' => $fresh['items'] ?? $data['items'],
                'added' => 0,
                'updated' => 0,
                'message' => 'OC desvinculada (los productos del borrador se conservan)',
            ];
        }

        $purchasing = new PurchasingService($this->db);
        $order = $purchasing->getOrder($orderId);
        if (!$order || !in_array((string)($order['status'] ?? ''), ['draft', 'ordered'], true)) {
            throw new \RuntimeException('La OC seleccionada no está disponible para casar');
        }

        $importSupplier = !empty($data['import']['supplier_id']) ? (int)$data['import']['supplier_id'] : 0;
        $orderSupplier = !empty($order['supplier_id']) ? (int)$order['supplier_id'] : 0;
        if ($importSupplier > 0 && $orderSupplier > 0 && $importSupplier !== $orderSupplier) {
            throw new \RuntimeException('La OC casada pertenece a otro proveedor');
        }

        $headerMeta = ['linked_order_id' => $orderId];
        if ($importSupplier <= 0 && $orderSupplier > 0) {
            $headerMeta['supplier_id'] = $orderSupplier;
        }
        // Hereda nº/fecha de factura de la OC si el borrador aún no los tiene.
        if (empty($data['import']['invoice_number']) && !empty($order['invoice_number'])) {
            $headerMeta['invoice_number'] = (string)$order['invoice_number'];
        }
        if (empty($data['import']['invoice_date']) && !empty($order['invoice_date'])) {
            $headerMeta['invoice_date'] = (string)$order['invoice_date'];
        }
        if (empty($data['import']['due_date']) && !empty($order['due_date'])) {
            $headerMeta['due_date'] = (string)$order['due_date'];
        }
        $this->updateHeader($importId, $headerMeta);

        $byProduct = [];
        foreach ($data['items'] as $it) {
            $pid = (int)($it['matched_product_id'] ?? 0);
            if ($pid > 0) {
                $byProduct[$pid] = $it;
            }
        }

        $added = 0;
        $updated = 0;
        $orderLabel = (string)($order['order_number'] ?? ('#' . $orderId));

        foreach ($order['items'] as $oi) {
            $pid = (int)($oi['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $qty = round(max(0, (float)($oi['quantity'] ?? 0)), 3);
            if ($qty <= 0) {
                continue;
            }
            $cost = round(max(0, (float)($oi['unit_cost'] ?? 0)), 2);
            $tax = round(max(0, (float)($oi['tax_rate'] ?? 0)), 2);

            if (isset($byProduct[$pid])) {
                $ex = $byProduct[$pid];
                if (($ex['row_action'] ?? '') === 'skip') {
                    continue;
                }
                $needQty = !((float)($ex['quantity'] ?? 0) > 0);
                $needCost = !((float)($ex['unit_cost'] ?? 0) > 0)
                    && !((float)($ex['line_total'] ?? 0) > 0);
                if (!$needQty && !$needCost) {
                    continue;
                }
                $newQty = $needQty ? $qty : (float)$ex['quantity'];
                $newCost = $needCost ? $cost : (float)$ex['unit_cost'];
                $useTax = (float)($ex['tax_rate'] ?? 0) > 0 ? (float)$ex['tax_rate'] : $tax;
                $dst = (float)($ex['discount_pct'] ?? 0);
                $lineTotal = round($newQty * $newCost * (1 - $dst / 100) * (1 + $useTax / 100), 2);
                $this->query(
                    "UPDATE purchase_invoice_import_items
                     SET quantity = ?, unit_cost = ?, tax_rate = ?, line_total = ?
                     WHERE id = ? AND import_id = ?",
                    [$newQty, $newCost, $useTax, $lineTotal, (int)$ex['id'], $importId]
                );
                $updated++;
                continue;
            }

            $product = $this->query(
                "SELECT id, code, barcode, name, sale_price
                 FROM products
                 WHERE id = ? AND status = 'active' AND COALESCE(product_type, 'product') = 'product'
                 LIMIT 1",
                [$pid]
            )->fetch();
            if (!$product) {
                continue;
            }

            $lineTotal = round($qty * $cost * (1 + $tax / 100), 2);
            $sale = isset($product['sale_price']) && (float)$product['sale_price'] > 0
                ? round((float)$product['sale_price'], 2)
                : null;
            $lineNo = (int)$this->query(
                "SELECT COALESCE(MAX(line_no), 0) + 1 FROM purchase_invoice_import_items WHERE import_id = ?",
                [$importId]
            )->fetchColumn();

            $this->query(
                "INSERT INTO purchase_invoice_import_items
                    (import_id, line_no, raw_line, supplier_sku, barcode, description, quantity, unit_cost,
                     tax_rate, discount_pct, line_total, matched_product_id, match_type, row_action,
                     lot_code, expiry_date, sale_price, confidence)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 'purchase_order', 'add_stock', ?, ?, ?, 100)",
                [
                    $importId,
                    $lineNo,
                    'Desde OC ' . $orderLabel,
                    substr(trim((string)($oi['product_code'] ?? $product['code'] ?? '')), 0, 120) ?: null,
                    $this->normalizeBarcodeValue((string)($oi['barcode'] ?? $product['barcode'] ?? '')) ?: null,
                    substr((string)($oi['product_name'] ?? $product['name']), 0, 255),
                    $qty,
                    $cost,
                    $tax,
                    $lineTotal,
                    $pid,
                    !empty($oi['lot_code']) ? substr((string)$oi['lot_code'], 0, 60) : null,
                    $this->normalizeDate($oi['expiry_date'] ?? null),
                    $sale,
                ]
            );
            $added++;
            $byProduct[$pid] = ['id' => (int)$this->db->lastInsertId()];
        }

        $this->refreshTotals($importId);
        $fresh = $this->get($importId);
        $parts = [];
        if ($added > 0) {
            $parts[] = $added . ' producto(s) cargados desde la OC';
        }
        if ($updated > 0) {
            $parts[] = $updated . ' línea(s) completadas con datos de la OC';
        }
        if ($parts === []) {
            $parts[] = 'OC casada (los productos ya estaban en el borrador)';
        }

        return [
            'import' => $fresh['import'] ?? $data['import'],
            'items' => $fresh['items'] ?? [],
            'added' => $added,
            'updated' => $updated,
            'message' => implode('. ', $parts),
        ];
    }

    /** Actualiza la cabecera con lo corregido en el modal. */
    public function updateHeader(int $importId, array $meta): void
    {
        $linkedOrderId = array_key_exists('linked_order_id', $meta)
            ? ((int)($meta['linked_order_id'] ?? 0) ?: null)
            : null;

        // Si llega linked_order_id en el meta, persistirlo (incluye limpiar con null vía clave presente).
        if (array_key_exists('linked_order_id', $meta)) {
            try {
                $this->query(
                    "UPDATE purchase_invoice_imports SET linked_order_id = ? WHERE id = ? AND status = 'pending'",
                    [$linkedOrderId, $importId]
                );
            } catch (\Throwable $e) {
                // Columna aún no migrada en installs viejos
            }
        }

        $this->query(
            "UPDATE purchase_invoice_imports SET
                supplier_id = COALESCE(?, supplier_id),
                invoice_number = COALESCE(NULLIF(?, ''), invoice_number),
                invoice_date = COALESCE(?, invoice_date),
                due_date = COALESCE(?, due_date),
                payment_mode = ?,
                early_payment_discount_pct = ?
             WHERE id = ? AND status = 'pending'",
            [
                !empty($meta['supplier_id']) ? (int)$meta['supplier_id'] : null,
                substr(trim((string)($meta['invoice_number'] ?? '')), 0, 80),
                $this->normalizeDate($meta['invoice_date'] ?? null),
                $this->normalizeDate($meta['due_date'] ?? null),
                in_array($meta['payment_mode'] ?? '', ['payable', 'cash', 'transfer', 'card'], true)
                    ? (string)$meta['payment_mode']
                    : 'payable',
                isset($meta['early_payment_discount_pct']) && $meta['early_payment_discount_pct'] !== ''
                    ? max(0, min(100, round((float)$meta['early_payment_discount_pct'], 2)))
                    : 0.0,
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
                'barcode' => $this->normalizeBarcodeValue((string)($item['barcode'] ?? '')) ?: null,
                'product_code' => substr(trim((string)($item['supplier_sku'] ?? '')), 0, 255) ?: null,
            ];

            $this->links->link($productoId, $supplierId, [
                'supplier_sku' => (string)($item['supplier_sku'] ?? ''),
                'supplier_product_name' => (string)$item['description'],
                'last_cost' => $costo,
                'last_purchase_date' => (string)($import['invoice_date'] ?? date('Y-m-d')),
            ]);

            $barcodeLine = $this->normalizeBarcodeValue((string)($item['barcode'] ?? ''));
            if ($barcodeLine !== '' && !$this->barcodeExists($barcodeLine)) {
                $this->query(
                    "UPDATE products SET barcode = COALESCE(NULLIF(barcode, ''), ?) WHERE id = ?",
                    [$barcodeLine, $productoId]
                );
            }

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

        $linkedOrderId = !empty($import['linked_order_id']) ? (int)$import['linked_order_id'] : 0;
        $orderId = 0;

        if ($linkedOrderId > 0) {
            $linked = $purchasing->getOrder($linkedOrderId);
            if (!$linked || !in_array($linked['status'], ['draft', 'ordered'], true)) {
                throw new \RuntimeException('La OC seleccionada no está disponible para casar con esta factura');
            }
            // Si la OC no tenía proveedor, hereda el de la factura.
            if (empty($linked['supplier_id']) && $supplierId > 0) {
                $this->query(
                    "UPDATE purchase_orders SET supplier_id = ? WHERE id = ? AND status IN ('draft','ordered')",
                    [$supplierId, $linkedOrderId]
                );
            } elseif (!empty($linked['supplier_id']) && (int)$linked['supplier_id'] !== $supplierId) {
                throw new \RuntimeException('La OC casada pertenece a otro proveedor');
            }

            $purchasing->replaceOrderItems($linkedOrderId, $lineas);
            $purchasing->applyInvoiceMeta($linkedOrderId, [
                'due_date' => $import['due_date'] ?? null,
                'invoice_date' => $fechaFactura,
                'invoice_number' => $import['invoice_number'] ?? null,
                'early_payment_discount_pct' => $import['early_payment_discount_pct'] ?? 0,
            ]);
            $this->query(
                "UPDATE purchase_orders SET origin = COALESCE(NULLIF(origin,''), 'invoice_import'),
                    invoice_import_id = ?, notes = CONCAT(COALESCE(notes,''),
                    CASE WHEN COALESCE(notes,'') = '' THEN '' ELSE ' | ' END,
                    'Factura importada #', ?)
                 WHERE id = ?",
                [$importId, (string)$importId, $linkedOrderId]
            );
            $orderId = $linkedOrderId;
        } else {
            $orderId = $purchasing->createOrder(
                $supplierId,
                $fechaFactura,
                $fechaBodega,
                null,
                'Factura importada #' . $importId,
                $lineas,
                'ordered',
                (float)($import['early_payment_discount_pct'] ?? 0),
                $import['due_date'] ?? null,
                $fechaFactura,
                $import['invoice_number'] ?? null,
                false // no forzar descuento aliado sobre el total de factura
            );

            $this->query(
                "UPDATE purchase_orders SET origin = 'invoice_import', invoice_import_id = ? WHERE id = ?",
                [$importId, $orderId]
            );
        }

        $purchasing->receiveOrder(
            $orderId,
            $fechaBodega,
            (string)($import['invoice_number'] ?? ''),
            (string)($import['payment_mode'] ?? 'payable'),
            'Ingreso por factura importada'
        );

        // El ingreso usa WAC; si el precio de la factura cambió respecto al catálogo,
        // el costo de compra del inventario debe quedar en el de la factura confirmada.
        $this->applyInvoiceCostsToCatalog($lineas);

        $accountingOk = true;
        try {
            (new AccountingService($this->db))->postPurchaseOrder($orderId);
        } catch (\Throwable $e) {
            $accountingOk = false;
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
            'accounting_ok' => $accountingOk,
            'accounting_note' => $accountingOk
                ? ''
                : 'Asiento contable pendiente: use «Sincronizar» en Contabilidad.',
        ];
    }

    /**
     * Deja el precio de compra del catálogo en el costo unitario de la factura.
     * Así, si el proveedor cambió el precio, inventario refleja el costo real recibido.
     *
     * @param array<int,array<string,mixed>> $lineas
     */
    private function applyInvoiceCostsToCatalog(array $lineas): void
    {
        foreach ($lineas as $ln) {
            $pid = (int)($ln['product_id'] ?? 0);
            $cost = round((float)($ln['unit_cost'] ?? 0), 2);
            if ($pid <= 0 || $cost <= 0) {
                continue;
            }
            $this->query(
                "UPDATE products
                 SET purchase_price = ?
                 WHERE id = ? AND COALESCE(product_type, 'product') = 'product'",
                [$cost, $pid]
            );
        }
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
        $barcode = $this->normalizeBarcodeValue((string)($item['barcode'] ?? ''));
        if ($codigo === '' || $this->codeExists($codigo)) {
            $codigo = $barcode !== '' && !$this->codeExists($barcode)
                ? $barcode
                : ('SKU-' . strtoupper(bin2hex(random_bytes(3))));
        }
        if ($barcode !== '' && $this->barcodeExists($barcode)) {
            $barcode = '';
        }

        $costo = round((float)$item['unit_cost'], 2);
        $precioVenta = !empty($item['sale_price']) && (float)$item['sale_price'] > 0
            ? round((float)$item['sale_price'], 2)
            : round($costo * (1 + $this->defaultMarginPct() / 100), 2);

        $this->query(
            "INSERT INTO products
                (code, barcode, name, product_type, purchase_price, sale_price, stock, min_stock, unit,
                 track_lots, expiry_alert_days, created_by, source_channel)
             VALUES (?, ?, ?, 'product', ?, ?, 0, ?, 'UND', ?, 30, ?, 'manual')",
            [
                substr($codigo, 0, 255),
                $barcode !== '' ? $barcode : null,
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

    private function barcodeExists(string $barcode): bool
    {
        $barcode = $this->normalizeBarcodeValue($barcode);
        if ($barcode === '') {
            return false;
        }
        return (bool)$this->query(
            "SELECT 1 FROM products WHERE barcode = ? LIMIT 1",
            [$barcode]
        )->fetchColumn();
    }

    private function normalizeBarcodeValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $compact = preg_replace('/[\s\-]/', '', $value) ?? $value;
        return substr($compact, 0, 64);
    }

    private function looksLikeBarcode(string $value): bool
    {
        $v = $this->normalizeBarcodeValue($value);
        return $v !== '' && preg_match('/^\d{8,14}$/', $v) === 1;
    }

    /**
     * @param list<list<string>> $matrix
     * @return list<array<string,mixed>>
     */
    private function matrixToInvoiceItems(array $matrix): array
    {
        if ($matrix === []) {
            return [];
        }
        $mapa = null;
        $filas = [];
        foreach ($matrix as $campos) {
            $campos = array_map(static fn($v) => trim((string)$v), $campos);
            while ($campos !== [] && end($campos) === '') {
                array_pop($campos);
            }
            if ($campos === [] || implode('', $campos) === '') {
                continue;
            }
            if ($mapa === null) {
                $mapped = $this->mapCsvHeader($campos);
                if ($mapped !== []) {
                    $mapa = $mapped;
                    continue;
                }
                $mapa = $this->inferCsvMap($campos);
                if ($mapa === []) {
                    continue;
                }
            }
            $fila = $this->rowFromCsvFields($campos, $mapa, "\t");
            if (trim((string)($fila['description'] ?? '')) === ''
                && (float)($fila['quantity'] ?? 0) <= 0
                && (float)($fila['unit_cost'] ?? 0) <= 0) {
                continue;
            }
            $filas[] = $fila;
        }
        return $this->recoverZeroAmountRows($filas, $mapa ?? []);
    }

    /** @return list<list<string>> */
    private function readXlsxMatrix(string $absolute): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return [];
        }
        $zip = new \ZipArchive();
        if ($zip->open($absolute) !== true) {
            return [];
        }

        $shared = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($ssXml) && $ssXml !== '') {
            $ss = @simplexml_load_string($ssXml);
            if ($ss !== false) {
                foreach ($ss->si as $si) {
                    if (isset($si->t)) {
                        $shared[] = (string)$si->t;
                    } else {
                        $parts = [];
                        foreach ($si->r as $r) {
                            $parts[] = (string)$r->t;
                        }
                        $shared[] = implode('', $parts);
                    }
                }
            }
        }

        $sheetPath = 'xl/worksheets/sheet1.xml';
        $wb = $zip->getFromName('xl/workbook.xml');
        if (is_string($wb) && $wb !== '') {
            // Preferir la primera hoja del workbook.
            if (preg_match('/sheet[^>]*r:id="(rId\d+)"/i', $wb, $m)) {
                $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
                if (is_string($rels) && preg_match(
                    '/Id="' . preg_quote($m[1], '/') . '"[^>]*Target="([^"]+)"/i',
                    $rels,
                    $tm
                )) {
                    $target = ltrim(str_replace('\\', '/', $tm[1]), '/');
                    if (!str_starts_with($target, 'xl/')) {
                        $target = 'xl/' . $target;
                    }
                    $sheetPath = $target;
                }
            }
        }

        $sheetXml = $zip->getFromName($sheetPath);
        $zip->close();
        if (!is_string($sheetXml) || $sheetXml === '') {
            return [];
        }

        $sheet = @simplexml_load_string($sheetXml);
        if ($sheet === false) {
            return [];
        }
        $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $matrix = [];
        foreach ($sheet->sheetData->row as $row) {
            $cells = [];
            $maxCol = -1;
            foreach ($row->c as $c) {
                $ref = (string)($c['r'] ?? '');
                $colIdx = $this->xlsxColIndex($ref);
                if ($colIdx < 0) {
                    $colIdx = $maxCol + 1;
                }
                $maxCol = max($maxCol, $colIdx);
                $type = (string)($c['t'] ?? '');
                $value = '';
                if ($type === 's') {
                    $idx = (int)($c->v ?? -1);
                    $value = $shared[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string)($c->is->t ?? '');
                } else {
                    $value = (string)($c->v ?? '');
                }
                $cells[$colIdx] = $this->toUtf8($value);
            }
            if ($maxCol < 0) {
                continue;
            }
            $line = array_fill(0, $maxCol + 1, '');
            foreach ($cells as $i => $v) {
                $line[$i] = $v;
            }
            $matrix[] = $line;
        }

        return $matrix;
    }

    private function xlsxColIndex(string $cellRef): int
    {
        if (!preg_match('/^([A-Z]+)/i', $cellRef, $m)) {
            return -1;
        }
        $letters = strtoupper($m[1]);
        $n = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n - 1;
    }

    /** @return list<list<string>> */
    private function readOdsMatrix(string $absolute): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return [];
        }
        $zip = new \ZipArchive();
        if ($zip->open($absolute) !== true) {
            return [];
        }
        $xml = $zip->getFromName('content.xml');
        $zip->close();
        if (!is_string($xml) || $xml === '') {
            return [];
        }
        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            return [];
        }
        $doc->registerXPathNamespace('table', 'urn:oasis:names:tc:opendocument:xmlns:table:1.0');
        $doc->registerXPathNamespace('text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0');
        $rows = $doc->xpath('//table:table-row') ?: [];
        $matrix = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($row->children('urn:oasis:names:tc:opendocument:xmlns:table:1.0') as $cell) {
                if ($cell->getName() !== 'table-cell') {
                    continue;
                }
                $repeat = (int)($cell->attributes('urn:oasis:names:tc:opendocument:xmlns:table:1.0')['number-columns-repeated'] ?? 1);
                $repeat = max(1, min(50, $repeat));
                $text = trim(html_entity_decode(strip_tags($cell->asXML() ?: ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                for ($i = 0; $i < $repeat; $i++) {
                    $line[] = $this->toUtf8($text);
                }
            }
            if (implode('', $line) !== '') {
                $matrix[] = $line;
            }
        }
        return $matrix;
    }

    /**
     * XLS antiguo: a veces es HTML/CSV disfrazado; BIFF binario no se parsea aquí.
     *
     * @return list<list<string>>
     */
    private function readXlsMatrix(string $absolute): array
    {
        $raw = (string)@file_get_contents($absolute);
        if ($raw === '') {
            return [];
        }
        // HTML exportado desde Excel
        if (stripos($raw, '<table') !== false) {
            return $this->htmlTableToMatrix($raw);
        }
        // CSV/TSV mal nombrado como .xls
        if (preg_match('/[;\t,].*[;\t,]/', substr($raw, 0, 2000))) {
            return $this->csvRawToMatrix($this->toUtf8($raw));
        }
        return [];
    }

    /** @return list<list<string>> */
    private function csvRawToMatrix(string $raw): array
    {
        $raw = $this->normalizeCsvEncoding($raw);
        $primera = preg_split('/\r\n|\n|\r/', $raw, 2)[0] ?? '';
        $sep = $this->detectCsvSeparator($primera);
        $handle = fopen('php://memory', 'r+');
        if (!$handle) {
            return [];
        }
        fwrite($handle, $raw);
        rewind($handle);
        $matrix = [];
        while (($campos = fgetcsv($handle, 0, $sep)) !== false) {
            if ($campos === [null]) {
                continue;
            }
            $matrix[] = array_map(static fn($v) => trim((string)$v), $campos);
        }
        fclose($handle);
        return $matrix;
    }

    /** @return list<list<string>> */
    private function htmlTableToMatrix(string $html): array
    {
        $matrix = [];
        if (!preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $html, $rows)) {
            return [];
        }
        foreach ($rows[1] as $rowHtml) {
            if (!preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $rowHtml, $cells)) {
                continue;
            }
            $line = [];
            foreach ($cells[1] as $cell) {
                $line[] = trim(html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            if (implode('', $line) !== '') {
                $matrix[] = $line;
            }
        }
        return $matrix;
    }

    private function spreadsheetToPlainText(string $absolute, string $extension): string
    {
        $matrix = match (strtolower($extension)) {
            'xlsx', 'xlsm' => $this->readXlsxMatrix($absolute),
            'ods' => $this->readOdsMatrix($absolute),
            'xls' => $this->readXlsMatrix($absolute),
            default => [],
        };
        if ($matrix === []) {
            return '';
        }
        $lines = [];
        foreach (array_slice($matrix, 0, 400) as $row) {
            $lines[] = implode("\t", $row);
        }
        return implode("\n", $lines);
    }

    private function extractDocxText(string $absolute): string
    {
        if (!class_exists(\ZipArchive::class)) {
            return '';
        }
        $zip = new \ZipArchive();
        if ($zip->open($absolute) !== true) {
            return '';
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (!is_string($xml) || $xml === '') {
            return '';
        }
        $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<w:tab[^>]*\/>/', "\t", $xml) ?? $xml;
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return $this->toUtf8(trim($text));
    }

    /**
     * Factura electrónica UBL / DIAN (Invoice, CreditNote, AttachedDocument, ZIP).
     *
     * @return array{invoice_number?:string,invoice_date?:?string,items:list<array<string,mixed>>}
     */
    private function parseUblInvoiceXml(string $relativeOrAbsolutePath, string $extension = 'xml'): array
    {
        $ruta = is_readable($relativeOrAbsolutePath)
            ? $relativeOrAbsolutePath
            : dirname(__DIR__, 2) . '/public/' . $relativeOrAbsolutePath;
        if (!is_readable($ruta)) {
            return ['items' => []];
        }

        $raw = '';
        if (strtolower($extension) === 'zip') {
            $raw = $this->extractXmlFromZip($ruta);
        } else {
            $raw = (string)file_get_contents($ruta);
        }
        if ($raw === '') {
            return ['items' => []];
        }
        $raw = $this->toUtf8($raw);
        $ubl = $this->extractEmbeddedUblXml($raw);
        if ($ubl === '') {
            return ['items' => []];
        }

        return $this->parseUblDom($ubl);
    }

    private function extractZipTextPreview(string $absolute): string
    {
        $xml = $this->extractXmlFromZip($absolute);
        if ($xml === '') {
            return '';
        }
        $parsed = $this->parseUblDom($xml);
        if ($parsed['items'] === []) {
            return mb_substr(strip_tags($xml), 0, 12000);
        }
        $lines = [];
        foreach ($parsed['items'] as $it) {
            $lines[] = trim(
                ($it['description'] ?? '') . ' ID: ' . ($it['supplier_sku'] ?? '') .
                ' / EAN: ' . ($it['barcode'] ?? '') . ' ' .
                ($it['quantity'] ?? '') . ' ' . ($it['unit_cost'] ?? '')
            );
        }
        return implode("\n", $lines);
    }

    private function extractXmlFromZip(string $absolute): string
    {
        if (!class_exists(\ZipArchive::class)) {
            return '';
        }
        $zip = new \ZipArchive();
        if ($zip->open($absolute) !== true) {
            return '';
        }
        $best = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (!preg_match('/\.xml$/i', $name)) {
                continue;
            }
            $content = $zip->getFromIndex($i);
            if (!is_string($content) || $content === '') {
                continue;
            }
            if (stripos($content, 'Invoice') !== false || stripos($content, 'CreditNote') !== false
                || stripos($content, 'AttachedDocument') !== false) {
                $best = $content;
                break;
            }
            if ($best === '') {
                $best = $content;
            }
        }
        $zip->close();
        return $best;
    }

    /** Extrae el XML Invoice/CreditNote desde AttachedDocument, CDATA, base64 o entidades. */
    private function extractEmbeddedUblXml(string $raw): string
    {
        $candidates = [$raw];
        $decodedEntities = html_entity_decode($raw, ENT_QUOTES | ENT_XML1, 'UTF-8');
        if ($decodedEntities !== $raw) {
            $candidates[] = $decodedEntities;
        }

        foreach ($candidates as $blob) {
            if (preg_match('/<(?:\w+:)?Invoice\b[\s\S]*?<\/(?:\w+:)?Invoice>/i', $blob, $m)) {
                return $m[0];
            }
            if (preg_match('/<(?:\w+:)?CreditNote\b[\s\S]*?<\/(?:\w+:)?CreditNote>/i', $blob, $m)) {
                return $m[0];
            }
        }

        // Description / CDATA con XML escapado
        if (preg_match_all('/<(?:\w+:)?Description[^>]*>([\s\S]*?)<\/(?:\w+:)?Description>/i', $raw, $descs)) {
            foreach ($descs[1] as $desc) {
                $inner = html_entity_decode(trim($desc), ENT_QUOTES | ENT_XML1, 'UTF-8');
                $inner = preg_replace('/^<!\[CDATA\[|\]\]>$/s', '', $inner) ?? $inner;
                $found = $this->extractEmbeddedUblXml($inner);
                if ($found !== '' && stripos($found, 'InvoiceLine') !== false) {
                    return $found;
                }
                if ($found !== '') {
                    return $found;
                }
            }
        }

        // EmbeddedDocumentBinaryObject base64
        if (preg_match_all(
            '/<(?:\w+:)?EmbeddedDocumentBinaryObject[^>]*>([\s\S]*?)<\/(?:\w+:)?EmbeddedDocumentBinaryObject>/i',
            $raw,
            $bins
        )) {
            foreach ($bins[1] as $bin) {
                $b64 = preg_replace('/\s+/', '', trim($bin)) ?? '';
                $decoded = base64_decode($b64, true);
                if (!is_string($decoded) || $decoded === '') {
                    continue;
                }
                $found = $this->extractEmbeddedUblXml($this->toUtf8($decoded));
                if ($found !== '') {
                    return $found;
                }
            }
        }

        // AttachedDocument raíz sin InvoiceLine propia: devolver vacío (ya se intentó embeber).
        if (stripos($raw, 'AttachedDocument') !== false) {
            return '';
        }

        return (stripos($raw, 'Invoice') !== false || stripos($raw, 'CreditNote') !== false) ? $raw : '';
    }

    /**
     * @return array{invoice_number?:string,invoice_date?:?string,items:list<array<string,mixed>>}
     */
    private function parseUblDom(string $ublXml): array
    {
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($ublXml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            // Reintento sin declaración XML duplicada / BOM
            $clean = preg_replace('/^\xEF\xBB\xBF/', '', $ublXml) ?? $ublXml;
            $ok = @$dom->loadXML($clean, LIBXML_NONET | LIBXML_COMPACT);
            if (!$ok) {
                return ['items' => []];
            }
        }

        $xp = new \DOMXPath($dom);
        $invoiceNumber = trim((string)$xp->evaluate('string(//*[local-name()="ID"][1])'));
        // Preferir ID de cabecera Invoice/CreditNote
        $idNode = $xp->query('/*[local-name()="Invoice" or local-name()="CreditNote"]/*[local-name()="ID"]');
        if ($idNode && $idNode->length > 0) {
            $invoiceNumber = trim($idNode->item(0)?->textContent ?? $invoiceNumber);
        }
        $invoiceDate = $this->normalizeDate(trim((string)$xp->evaluate(
            'string(/*[local-name()="Invoice" or local-name()="CreditNote"]/*[local-name()="IssueDate"])'
        )));

        $lineNodes = $xp->query('//*[local-name()="InvoiceLine"]|//*[local-name()="CreditNoteLine"]');
        $items = [];
        if ($lineNodes) {
            foreach ($lineNodes as $line) {
                if (!$line instanceof \DOMElement) {
                    continue;
                }
                $qty = $this->toNumber($this->domFirstText($xp, $line, './/*[local-name()="InvoicedQuantity"]|.//*[local-name()="CreditedQuantity"]'));
                $lineTotal = $this->toNumber($this->domFirstText($xp, $line, './/*[local-name()="LineExtensionAmount"]'));
                $desc = trim($this->domFirstText($xp, $line, './/*[local-name()="Item"]/*[local-name()="Description"]|.//*[local-name()="Item"]/*[local-name()="Name"]'));
                $sku = trim($this->domFirstText($xp, $line, './/*[local-name()="SellersItemIdentification"]/*[local-name()="ID"]'));
                if ($sku === '') {
                    $sku = trim($this->domFirstText($xp, $line, './/*[local-name()="BuyersItemIdentification"]/*[local-name()="ID"]'));
                }
                if ($sku === '') {
                    $sku = trim($this->domFirstText($xp, $line, './/*[local-name()="AdditionalItemIdentification"]/*[local-name()="ID"]'));
                }
                $barcode = trim($this->domFirstText($xp, $line, './/*[local-name()="StandardItemIdentification"]/*[local-name()="ID"]'));
                $barcode = $this->normalizeBarcodeValue($barcode);
                if ($barcode === '' && preg_match('/\b(77\d{11}|\d{8,14})\b/', $desc . ' ' . $sku, $bm)) {
                    $barcode = $this->normalizeBarcodeValue($bm[1]);
                }
                $priceAmount = $this->toNumber($this->domFirstText($xp, $line, './/*[local-name()="Price"]/*[local-name()="PriceAmount"]'));
                $taxRate = $this->toNumber($this->domFirstText($xp, $line, './/*[local-name()="TaxCategory"]/*[local-name()="Percent"]'));
                if ($desc === '' && $qty <= 0 && $lineTotal <= 0) {
                    continue;
                }
                $unitCost = $priceAmount > 0 ? $priceAmount : ($qty > 0 ? round($lineTotal / $qty, 2) : 0.0);
                $items[] = $this->enrichLineIds([
                    'supplier_sku' => $sku,
                    'barcode' => $barcode,
                    'description' => $desc !== '' ? $desc : ('Ítem ' . (count($items) + 1)),
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'tax_rate' => $taxRate,
                    'discount_pct' => 0.0,
                    'line_total' => $lineTotal,
                    'lot_code' => '',
                    'expiry_date' => null,
                    'sale_price' => 0.0,
                ]);
            }
        }

        return [
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $invoiceDate,
            'items' => $items,
        ];
    }

    private function domFirstText(\DOMXPath $xp, \DOMNode $ctx, string $query): string
    {
        $nodes = $xp->query($query, $ctx);
        if (!$nodes || $nodes->length === 0) {
            return '';
        }
        return trim((string)($nodes->item(0)?->textContent ?? ''));
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
