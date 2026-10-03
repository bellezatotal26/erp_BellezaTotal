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

    /** Factura electrónica DIAN / con IVA de inventario. */
    public const DOC_ELECTRONIC = 'electronic';
    /** Factura normal: el usuario decide si los productos llevan IVA. */
    public const DOC_STANDARD = 'standard';
    /** Remisión: productos exentos (sin IVA). */
    public const DOC_REMISSION = 'remission';

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

    /**
     * Registra un hito interno del borrador/factura (trazabilidad Timeline).
     *
     * @param array<string,mixed> $meta
     */
    public function logLifecycleEvent(
        ?int $importId,
        ?int $purchaseOrderId,
        string $eventType,
        string $label,
        string $detail = '',
        array $meta = []
    ): void {
        $eventType = substr(trim($eventType), 0, 40);
        $label = substr(trim($label), 0, 160);
        if ($eventType === '' || $label === '') {
            return;
        }
        $actor = isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : null;
        if ($actor !== null && $actor <= 0) {
            $actor = null;
        }
        $metaJson = $meta !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
        try {
            $this->query(
                "INSERT INTO purchase_invoice_lifecycle_events
                    (import_id, purchase_order_id, event_type, label, detail, actor_user_id, meta_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $importId && $importId > 0 ? $importId : null,
                    $purchaseOrderId && $purchaseOrderId > 0 ? $purchaseOrderId : null,
                    $eventType,
                    $label,
                    $detail !== '' ? substr($detail, 0, 500) : null,
                    $actor,
                    $metaJson,
                ]
            );
        } catch (\Throwable $e) {
            // Tabla aún no migrada: ensureSchema la crea en el siguiente request.
        }
    }

    /** Etiqueta legible del origen de carga. */
    public static function sourceLabel(string $source): string
    {
        return match (strtolower(trim($source))) {
            'csv' => 'CSV / Excel',
            'ai' => 'IA (texto)',
            'ai_vision' => 'IA (visión / foto)',
            'text' => 'Texto pegado',
            'manual' => 'Carga manual',
            default => $source !== '' ? $source : 'Desconocido',
        };
    }

    // ---------------------------------------------------------------- ingesta

    /**
     * Interpreta el documento y guarda el borrador de importación.
     *
     * @param array{
     *   supplier_id?:?int,
     *   invoice_number?:string,
     *   invoice_date?:?string,
     *   due_date?:?string,
     *   warehouse_date?:?string,
     *   payment_mode?:string,
     *   use_ai?:bool,
     *   document_kind?:string,
     *   apply_product_vat?:bool|int|null,
     *   linked_order_id?:?int,
     *   early_payment_discount_pct?:float|int|string
     * } $meta
     * @param array<string,mixed>|null $file Entrada de $_FILES
     * @return array{import_id:int,notes:string}
     */
    public function ingest(array $meta, ?array $file = null, string $pastedText = ''): array
    {
        $documentKind = self::normalizeDocumentKind((string)($meta['document_kind'] ?? ''));
        if ($documentKind === '') {
            throw new \InvalidArgumentException(
                'Seleccione el tipo de documento: F. Electrónica, F. Normal o Remisión'
            );
        }
        $meta['document_kind'] = $documentKind;
        if ($documentKind === self::DOC_ELECTRONIC) {
            $meta['apply_product_vat'] = 1;
            $meta['tax_override_amount'] = null;
        } elseif ($documentKind === self::DOC_REMISSION) {
            $meta['apply_product_vat'] = 0;
            $meta['tax_override_amount'] = null;
        } else {
            // F. Normal: 1 = %, 0 = exento, 2 = IVA monto personalizado (factura real).
            if (!array_key_exists('apply_product_vat', $meta) || $meta['apply_product_vat'] === null || $meta['apply_product_vat'] === '') {
                throw new \InvalidArgumentException(
                    'En Factura Normal indique si los productos llevan IVA, están exentos o IVA personalizado'
                );
            }
            $meta['apply_product_vat'] = self::parseApplyProductVat($meta['apply_product_vat']);
            if ((int)$meta['apply_product_vat'] === 2) {
                $override = isset($meta['tax_override_amount']) && $meta['tax_override_amount'] !== '' && $meta['tax_override_amount'] !== null
                    ? round(max(0, (float)$meta['tax_override_amount']), 2)
                    : 0.0;
                if ($override < 0.01) {
                    throw new \InvalidArgumentException(
                        'Con IVA personalizado indique el valor exacto del IVA de la factura (mayor a 0)'
                    );
                }
                $meta['tax_override_amount'] = $override;
            } else {
                $meta['tax_override_amount'] = null;
            }
        }

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
        // Texto de cabecera/dirección usable pero sin señales de productos → tratar como híbrido.
        $pdfWeakProducts = $ext === 'pdf'
            && !$pdfLooksScanned
            && $rawText !== ''
            && !$this->textHasProductLineSignals($rawText);

        if ($stored && in_array($ext, self::SPREADSHEET_EXTENSIONS, true)) {
            try {
                $items = $this->parseSpreadsheet($stored['path'], $ext);
                $label = strtoupper($ext === 'csv' ? 'CSV' : 'Excel');
                $notas[] = count($items) . ' línea(s) leídas de ' . $label;
                if ($items === [] && $ext !== 'csv') {
                    $notas[] = 'No se detectaron filas de producto en la hoja; se intentará lectura por texto/IA';
                }
            } catch (\Throwable $e) {
                $notas[] = 'Excel/CSV: ' . $e->getMessage();
                // No silenciar: el usuario debe saber que el archivo no se leyó.
                if ($items === []) {
                    // deja items vacío para fallback texto/IA
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

        // PDF solo imagen / foto / híbrido (dirección OK, tabla ilegible): visión
        if (empty($items) && $stored && ($isImageFile || $pdfLooksScanned || $pdfWeakProducts)) {
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
                            $motivo = $pdfLooksScanned
                                ? ' (PDF escaneado)'
                                : ($pdfWeakProducts ? ' (PDF con poco texto de productos)' : '');
                            $notas[] = count($items) . ' línea(s) leídas por IA desde imagen' . $motivo;
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
                $notas[] = $pdfWeakProducts
                    ? 'El PDF tiene texto de cabecera pero no productos legibles. Active «Interpretar con IA» o use CSV/Excel.'
                    : 'Este archivo parece solo imagen (sin texto). Active «Interpretar con IA» o pegue el texto / use CSV';
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

        // PDF con texto "usable" que falló en patrones/IA texto: último intento con visión.
        if (empty($items) && $ext === 'pdf' && $stored && !$pdfLooksScanned && !$pdfWeakProducts && $useAi) {
            $aiService = $aiService ?? new AiService($this->db);
            if ($aiService->isConfigured()) {
                $images = $this->collectInvoiceImages($stored['path'], $ext);
                if ($images !== []) {
                    $ai = $aiService->extractInvoiceFromImages($images);
                    if ($ai !== null && !empty($ai['items'])) {
                        $header = $ai;
                        $items = $this->normalizeAiItems($ai['items']);
                        $source = 'ai';
                        $notas[] = count($items) . ' línea(s) leídas por IA (visión tras fallar texto)';
                    }
                }
            }
        }

        // Enriquecer ID/EAN si quedaron dentro de la descripción.
        $items = array_map(fn(array $it) => $this->enrichLineIds($it), $items);

        // Aplicar política de IVA según tipo de documento (antes de persistir).
        // apply_product_vat=2 (personalizado): líneas sin %; el monto va en tax_override_amount.
        $vatAlreadyIncluded = self::toBoolFlag($meta['vat_already_included'] ?? 0);
        $applyMode = (int)($meta['apply_product_vat'] ?? 0);
        $items = $this->applyDocumentTaxPolicy(
            $items,
            $documentKind,
            $applyMode === 1,
            $vatAlreadyIncluded
        );
        $notas[] = self::documentKindTaxNote(
            $documentKind,
            $applyMode === 1,
            TaxService::defaultRate($this->db),
            $vatAlreadyIncluded,
            $applyMode,
            isset($meta['tax_override_amount']) ? (float)$meta['tax_override_amount'] : null
        );

        if ($items === [] && !$stored && $rawText === '') {
            $notas[] = 'Borrador manual sin archivo: agregue productos en la revisión (buscar / escanear)';
            $source = 'manual';
        } elseif ($items === [] && empty($notas)) {
            $notas[] = 'Sin líneas detectadas: puede agregar productos manualmente en la revisión';
        }

        // Señal explícita para la UI: el archivo no produjo líneas.
        $spreadsheetFailed = $stored
            && in_array($ext, self::SPREADSHEET_EXTENSIONS, true)
            && $items === [];

        $importId = $this->createImport($meta, $header, $source, $stored, $rawText, $notas);
        $this->saveItems($importId, $items, $meta['supplier_id'] ?? null, $documentKind);
        $this->refreshTotals($importId);

        $fileLabel = trim((string)($stored['original'] ?? ''));
        $uploadDetail = trim(
            self::sourceLabel($source)
            . ($fileLabel !== '' ? ' · ' . $fileLabel : '')
            . (count($items) > 0 ? (' · ' . count($items) . ' línea(s)') : ' · sin líneas detectadas')
            . (!empty($notas) ? (' · ' . implode('. ', array_slice($notas, 0, 2))) : '')
        );
        $this->logLifecycleEvent(
            $importId,
            null,
            'invoice_uploaded',
            'Factura cargada al sistema',
            $uploadDetail,
            ['source' => $source, 'items_count' => count($items)]
        );
        $this->logLifecycleEvent(
            $importId,
            null,
            'invoice_pending',
            'Pendiente de revisión',
            'El borrador espera confirmación antes de afectar inventario y contabilidad'
        );

        $linkedOrderId = (int)($meta['linked_order_id'] ?? 0);
        if ($linkedOrderId > 0) {
            try {
                $link = $this->linkOrderAndLoadItems($importId, $linkedOrderId);
                if (!empty($link['message'])) {
                    $notas[] = (string)$link['message'];
                }
                // Reaplicar IVA del tipo de documento tras mezclar líneas de OC.
                $this->reapplyDocumentTaxToImport($importId);
            } catch (\Throwable $e) {
                $notas[] = 'No se pudo cruzar con la OC: ' . $e->getMessage();
            }
        }

        return [
            'import_id' => $importId,
            'notes' => implode('. ', $notas),
            'items_count' => count($items),
            'spreadsheet_failed' => $spreadsheetFailed,
        ];
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
        // Recepción a bodega: lo que el usuario indicó al cargar; si no, hoy (no la fecha de factura).
        $warehouseDate = $this->normalizeDate($meta['warehouse_date'] ?? null) ?? date('Y-m-d');
        $paymentMode = in_array($meta['payment_mode'] ?? '', ['payable', 'cash', 'transfer', 'card'], true)
            ? (string)$meta['payment_mode']
            : 'payable';
        $earlyPct = isset($meta['early_payment_discount_pct']) && $meta['early_payment_discount_pct'] !== ''
            ? max(0, min(100, round((float)$meta['early_payment_discount_pct'], 2)))
            : 0.0;
        $commercialPct = isset($meta['commercial_discount_pct']) && $meta['commercial_discount_pct'] !== ''
            ? max(0, min(100, round((float)$meta['commercial_discount_pct'], 2)))
            : 0.0;
        $commercialAmt = isset($meta['commercial_discount_amount']) && $meta['commercial_discount_amount'] !== ''
            ? max(0, round((float)$meta['commercial_discount_amount'], 2))
            : 0.0;
        if ($commercialAmt > 0.009) {
            $commercialPct = 0.0; // $ manda; evita recalcular por % al confirmar
        }
        $documentKind = self::normalizeDocumentKind((string)($meta['document_kind'] ?? ''));
        $applyVat = array_key_exists('apply_product_vat', $meta) && $meta['apply_product_vat'] !== null && $meta['apply_product_vat'] !== ''
            ? self::parseApplyProductVat($meta['apply_product_vat'])
            : null;
        $vatAlreadyIncluded = self::toBoolFlag($meta['vat_already_included'] ?? 0) ? 1 : 0;
        $taxOverride = null;
        if ($applyVat === 2 && isset($meta['tax_override_amount']) && $meta['tax_override_amount'] !== '' && $meta['tax_override_amount'] !== null) {
            $taxOverride = round(max(0, (float)$meta['tax_override_amount']), 2);
        }

        $paramsBase = [
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
            $documentKind !== '' ? $documentKind : null,
            $applyVat,
            $vatAlreadyIncluded,
            $earlyPct,
            $commercialPct,
            $commercialAmt,
            $_SESSION['tenant_user_id'] ?? null,
        ];

        try {
            $this->query(
                "INSERT INTO purchase_invoice_imports
                    (supplier_id, invoice_number, invoice_date, due_date, warehouse_date, source, file_path, file_mime,
                     original_name, raw_text, parse_notes, payment_mode, document_kind, apply_product_vat,
                     vat_already_included,
                     early_payment_discount_pct, commercial_discount_pct, commercial_discount_amount, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $paramsBase[0], $paramsBase[1], $paramsBase[2], $paramsBase[3], $warehouseDate,
                    $paramsBase[4], $paramsBase[5], $paramsBase[6], $paramsBase[7], $paramsBase[8],
                    $paramsBase[9], $paramsBase[10], $paramsBase[11], $paramsBase[12], $paramsBase[13],
                    $paramsBase[14], $paramsBase[15], $paramsBase[16], $paramsBase[17],
                ]
            );
        } catch (\Throwable $e) {
            try {
                $this->query(
                    "INSERT INTO purchase_invoice_imports
                        (supplier_id, invoice_number, invoice_date, due_date, warehouse_date, source, file_path, file_mime,
                         original_name, raw_text, parse_notes, payment_mode, document_kind, apply_product_vat,
                         early_payment_discount_pct, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $paramsBase[0], $paramsBase[1], $paramsBase[2], $paramsBase[3], $warehouseDate,
                        $paramsBase[4], $paramsBase[5], $paramsBase[6], $paramsBase[7], $paramsBase[8],
                        $paramsBase[9], $paramsBase[10], $paramsBase[11], $paramsBase[12], $paramsBase[14],
                        $paramsBase[17],
                    ]
                );
            } catch (\Throwable $e2) {
                // Installs viejos sin columna warehouse_date / comerciales.
                $this->query(
                    "INSERT INTO purchase_invoice_imports
                        (supplier_id, invoice_number, invoice_date, due_date, source, file_path, file_mime,
                         original_name, raw_text, parse_notes, payment_mode, document_kind, apply_product_vat,
                         early_payment_discount_pct, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $paramsBase[0], $paramsBase[1], $paramsBase[2], $paramsBase[3],
                        $paramsBase[4], $paramsBase[5], $paramsBase[6], $paramsBase[7], $paramsBase[8],
                        $paramsBase[9], $paramsBase[10], $paramsBase[11], $paramsBase[12], $paramsBase[14],
                        $paramsBase[17],
                    ]
                );
            }
        }

        $importId = (int)$this->db->lastInsertId();
        if ($applyVat === 2 && $taxOverride !== null) {
            try {
                $this->query(
                    "UPDATE purchase_invoice_imports SET tax_override_amount = ? WHERE id = ?",
                    [$taxOverride, $importId]
                );
            } catch (\Throwable $e) {
                // Columna aún no migrada: ensureSchema la crea en el siguiente request.
            }
        }

        return $importId;
    }

    public static function normalizeDocumentKind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        return in_array($kind, [self::DOC_ELECTRONIC, self::DOC_STANDARD, self::DOC_REMISSION], true)
            ? $kind
            : '';
    }

    public static function documentKindLabel(string $kind): string
    {
        return match (self::normalizeDocumentKind($kind)) {
            self::DOC_ELECTRONIC => 'F. Electrónica',
            self::DOC_STANDARD => 'F. Normal',
            self::DOC_REMISSION => 'Remisión',
            default => 'Documento',
        };
    }

    private static function toBoolFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value === 1;
        }
        $raw = strtolower(trim((string)$value));
        return in_array($raw, ['1', 'true', 'yes', 'on', 'si', 'sí'], true);
    }

    /** F. Normal: 0 = exento, 1 = IVA %, 2 = IVA monto personalizado. */
    public static function parseApplyProductVat(mixed $value): int
    {
        if (is_int($value) || is_float($value)) {
            $n = (int)$value;
            if ($n === 2) {
                return 2;
            }
            return $n === 1 ? 1 : 0;
        }
        $raw = strtolower(trim((string)$value));
        if (in_array($raw, ['2', 'custom', 'personalizado', 'override'], true)) {
            return 2;
        }
        return self::toBoolFlag($value) ? 1 : 0;
    }

    /**
     * Tasa IVA de línea según tipo de documento (misma base que inventario / TaxService).
     *
     * @param bool $zeroMeansMissing true = 0 se trata como “sin tasa” (OCR) y se usa default en F. Normal+IVA.
     *                                false = 0 es exento explícito (producto de catálogo).
     */
    public function resolveLineTaxRateForDocument(
        string $kind,
        bool $applyProductVat,
        float $currentRate = 0.0,
        bool $zeroMeansMissing = true
    ): float {
        $kind = self::normalizeDocumentKind($kind);
        if ($kind === self::DOC_REMISSION) {
            return 0.0;
        }
        if ($kind === self::DOC_ELECTRONIC) {
            return TaxService::defaultRate($this->db);
        }
        // F. Normal
        if (!$applyProductVat) {
            return 0.0;
        }
        $current = TaxService::normalizeRate($currentRate);
        if ($zeroMeansMissing && $current <= 0.009) {
            return TaxService::defaultRate($this->db);
        }
        return $current;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    /** F. Electrónica: el IVA se incorpora al costo unitario (precio de compra total). */
    public static function documentEmbedsVatInCost(string $kind): bool
    {
        return self::normalizeDocumentKind($kind) === self::DOC_ELECTRONIC;
    }

    public function applyDocumentTaxPolicy(
        array $items,
        string $kind,
        bool $applyProductVat,
        bool $vatAlreadyIncluded = false
    ): array {
        $kind = self::normalizeDocumentKind($kind);
        $embedVat = self::documentEmbedsVatInCost($kind);
        $out = [];
        foreach ($items as $item) {
            $current = (float)($item['tax_rate'] ?? 0);
            $tax = $this->resolveLineTaxRateForDocument($kind, $applyProductVat, $current);
            $qty = round((float)($item['quantity'] ?? 0), 3);
            $cost = round((float)($item['unit_cost'] ?? 0), 2);
            $disc = round(max(0, min(100, (float)($item['discount_pct'] ?? 0))));

            // F. Electrónica: sumar IVA al costo salvo que el Excel ya lo traiga incluido.
            if ($embedVat && $tax > 0.009 && $cost > 0 && !$vatAlreadyIncluded) {
                $cost = round($cost * (1 + $tax / 100), 2);
            }
            $item['unit_cost'] = $cost;
            $item['tax_rate'] = $tax;
            if ($qty > 0 && $cost >= 0) {
                $item['line_total'] = $embedVat
                    ? round($qty * $cost * (1 - $disc / 100), 2)
                    : round($qty * $cost * (1 - $disc / 100) * (1 + $tax / 100), 2);
            }
            $out[] = $item;
        }
        return $out;
    }

    public static function documentKindTaxNote(
        string $kind,
        bool $applyProductVat,
        float $defaultRate,
        bool $vatAlreadyIncluded = false,
        int $applyMode = 0,
        ?float $taxOverrideAmount = null
    ): string {
        $kind = self::normalizeDocumentKind($kind);
        if ($kind === self::DOC_ELECTRONIC) {
            $pct = rtrim(rtrim(number_format($defaultRate, 2, '.', ''), '0'), '.');
            if ($vatAlreadyIncluded) {
                return 'IVA: F. Electrónica → precios del archivo ya incluyen el '
                    . $pct . '% (no se vuelve a sumar al costo)';
            }
            return 'IVA: F. Electrónica → ' . $pct . '% incluido en el costo/precio de compra de cada producto';
        }
        if ($kind === self::DOC_REMISSION) {
            return 'IVA: Remisión → productos exentos (0%)';
        }
        if ($applyMode === 2) {
            $amt = $taxOverrideAmount !== null ? number_format(max(0, $taxOverrideAmount), 2, '.', ',') : '0.00';
            return 'IVA: F. Normal → IVA personalizado $' . $amt
                . ' (se prorratea a líneas al confirmar; asiento 240802 = ese monto)';
        }
        if ($applyProductVat) {
            return 'IVA: F. Normal → productos con IVA (revise % por línea; default '
                . rtrim(rtrim(number_format($defaultRate, 2, '.', ''), '0'), '.') . '%)';
        }
        return 'IVA: F. Normal → productos sin IVA (exentos); puede activar % por línea en revisión';
    }

    /** Reaplica la política de IVA del borrador a todas sus líneas (p.ej. tras cruzar OC). */
    public function reapplyDocumentTaxToImport(int $importId): void
    {
        $imp = $this->query(
            "SELECT document_kind, apply_product_vat, vat_already_included, tax_override_amount
             FROM purchase_invoice_imports WHERE id = ? LIMIT 1",
            [$importId]
        )->fetch();
        if (!$imp) {
            // Fallback instalaciones sin columna nueva.
            try {
                $imp = $this->query(
                    "SELECT document_kind, apply_product_vat FROM purchase_invoice_imports WHERE id = ? LIMIT 1",
                    [$importId]
                )->fetch();
            } catch (\Throwable $e) {
                return;
            }
        }
        if (!$imp) {
            return;
        }
        $kind = self::normalizeDocumentKind((string)($imp['document_kind'] ?? ''));
        if ($kind === '') {
            return;
        }
        $applyMode = self::parseApplyProductVat($imp['apply_product_vat'] ?? 0);
        $applyVat = $applyMode === 1;
        $isCustomVat = $applyMode === 2;
        $vatAlreadyIncluded = (int)($imp['vat_already_included'] ?? 0) === 1;
        if ($kind === self::DOC_STANDARD && $imp['apply_product_vat'] === null) {
            return;
        }

        $rows = $this->query(
            "SELECT id, quantity, unit_cost, discount_pct, tax_rate
             FROM purchase_invoice_import_items WHERE import_id = ?",
            [$importId]
        )->fetchAll();

        $embedVat = self::documentEmbedsVatInCost($kind);
        foreach ($rows as $row) {
            $cur = (float)($row['tax_rate'] ?? 0);
            if ($kind === self::DOC_ELECTRONIC) {
                $newTax = TaxService::defaultRate($this->db);
            } elseif ($kind === self::DOC_REMISSION || ($kind === self::DOC_STANDARD && (!$applyVat || $isCustomVat))) {
                $newTax = 0.0;
            } else {
                // F. Normal + IVA: no pisar tasas ya editadas; solo completar ceros (OCR/OC).
                $newTax = $cur > 0.009
                    ? TaxService::normalizeRate($cur)
                    : TaxService::defaultRate($this->db);
            }
            $qty = (float)$row['quantity'];
            $cost = (float)$row['unit_cost'];
            $dst = (float)$row['discount_pct'];
            if ($embedVat && $newTax > 0.009 && $cost > 0 && !$vatAlreadyIncluded) {
                // Evitar doble IVA: solo incorporar si la línea aún no tenía la tasa electrónica.
                if ($cur < 0.009) {
                    $cost = round($cost * (1 + $newTax / 100), 2);
                } elseif (abs($cur - $newTax) > 0.009) {
                    $net = round($cost / (1 + $cur / 100), 2);
                    $cost = round($net * (1 + $newTax / 100), 2);
                }
                $lineTotal = round($qty * $cost * (1 - $dst / 100), 2);
                $this->query(
                    "UPDATE purchase_invoice_import_items
                     SET unit_cost = ?, tax_rate = ?, line_total = ?
                     WHERE id = ? AND import_id = ?",
                    [$cost, $newTax, $lineTotal, (int)$row['id'], $importId]
                );
            } else {
                if ($embedVat) {
                    $lineTotal = round($qty * $cost * (1 - $dst / 100), 2);
                } else {
                    $lineTotal = round($qty * $cost * (1 - $dst / 100) * (1 + $newTax / 100), 2);
                }
                $this->query(
                    "UPDATE purchase_invoice_import_items SET tax_rate = ?, line_total = ? WHERE id = ? AND import_id = ?",
                    [$newTax, $lineTotal, (int)$row['id'], $importId]
                );
            }
        }
        $this->refreshTotals($importId);
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    private function saveItems(int $importId, array $items, ?int $supplierId, string $documentKind = ''): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO purchase_invoice_import_items
                (import_id, line_no, raw_line, supplier_sku, barcode, description, quantity, unit_cost,
                 tax_rate, discount_pct, line_total, matched_product_id, match_type, row_action,
                 lot_code, expiry_date, sale_price, confidence)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $vatInCost = self::documentEmbedsVatInCost($documentKind);
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
            $descuento = round(max(0, min(100, (float)($item['discount_pct'] ?? 0))));
            $impuesto = round(max(0, (float)($item['tax_rate'] ?? 0)), 2);
            // F. Electrónica: costo ya trae IVA; no volver a multiplicar.
            $totalLinea = $vatInCost
                ? round($cantidad * $costo * (1 - $descuento / 100), 2)
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

    /**
     * Señales de que el texto trae líneas de producto (no solo dirección/cabecera).
     * PDFs híbridos a menudo tienen dirección legible pero tabla ilegible.
     */
    private function textHasProductLineSignals(string $texto): bool
    {
        $texto = trim($texto);
        if ($texto === '' || mb_strlen($texto) < 40) {
            return false;
        }
        $hits = 0;
        if (preg_match('/\bEAN\b|\bUPC\b|\bGTIN\b/i', $texto)) {
            $hits += 2;
        }
        if (preg_match('/\b(cant(?:idad)?|qty|quantity|und|unid)\b/i', $texto)) {
            $hits++;
        }
        if (preg_match('/\b(descripci[oó]n|producto|item|artículo|articulo)\b/i', $texto)) {
            $hits++;
        }
        if (preg_match('/\b(precio|unit(?:ario)?|valor\s*unit|unit\s*price|p\.?\s*unit)\b/i', $texto)) {
            $hits++;
        }
        // Varios EAN numéricos de 8–14 dígitos
        if (preg_match_all('/(?<!\d)(\d{8,14})(?!\d)/', $texto, $m) && count($m[1]) >= 2) {
            $hits += 2;
        }
        // Patrón típico: cantidad + precio en la misma zona
        if (preg_match('/\d+[.,]?\d*\s+(?:\d+[.,]\d{2}|\d{1,3}(?:[.,]\d{3})*[.,]\d{2})/', $texto)) {
            $hits++;
        }
        return $hits >= 2;
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
            throw new \RuntimeException('No se pudo abrir el archivo Excel/CSV subido');
        }

        if (in_array($extension, ['xlsx', 'xlsm', 'ods'], true) && !class_exists(\ZipArchive::class)) {
            throw new \RuntimeException(
                'El servidor no tiene la extensión PHP Zip (ext-zip) necesaria para leer Excel (.xlsx/.ods). '
                . 'Pida activarla al hosting o exporte la factura a CSV e inténtelo de nuevo.'
            );
        }

        if ($extension === 'xls') {
            $matrix = $this->readXlsMatrix($ruta);
            if ($matrix === []) {
                throw new \RuntimeException(
                    'El formato .xls antiguo (binario) no es compatible. '
                    . 'Abra el archivo en Excel/LibreOffice y guárdelo como .xlsx o CSV.'
                );
            }
        } else {
            $matrix = match ($extension) {
                'xlsx', 'xlsm' => $this->readXlsxMatrix($ruta),
                'ods' => $this->readOdsMatrix($ruta),
                default => [],
            };
            if ($matrix === []) {
                throw new \RuntimeException(
                    'No se pudo leer la hoja del Excel. Verifique que no esté dañado '
                    . 'o guárdelo de nuevo como .xlsx / CSV.'
                );
            }
        }

        return $this->matrixToInvoiceItems($matrix);
    }

    /**
     * CSV de líneas de factura. Reconoce encabezados en español e inglés
     * y tolera separador coma, punto y coma o tabulación.
     * Soporta facturas con metadatos arriba (Vendor/Date/Invoice) y tabla de
     * productos más abajo (ej. export L'Oréal: #, Ref., EAN, Description…).
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

        $separador = $this->detectCsvSeparatorFromRaw($raw);

        $handle = fopen('php://memory', 'r+');
        if (!$handle) {
            return [];
        }
        fwrite($handle, $raw);
        rewind($handle);

        $matrix = [];
        while (($campos = fgetcsv($handle, 0, $separador)) !== false) {
            if ($campos === [null] || $campos === false) {
                continue;
            }
            $campos = array_map(fn($v) => trim((string)$v), $campos);
            if ($campos !== [] && isset($campos[0])) {
                $campos[0] = preg_replace('/^\xEF\xBB\xBF/', '', $campos[0]) ?? $campos[0];
            }
            while ($campos !== [] && end($campos) === '') {
                array_pop($campos);
            }
            if ($campos === [] || implode('', $campos) === '') {
                continue;
            }
            $matrix[] = $campos;
        }
        fclose($handle);

        // Si casi todas las filas quedaron en 1 columna, reintentar con el otro separador típico.
        if ($matrix !== [] && $this->csvMatrixLooksCollapsed($matrix)) {
            $alt = $separador === ';' ? ',' : ($separador === ',' ? ';' : ',');
            $retry = $this->parseCsvWithSeparator($raw, $alt);
            if (count($retry) > count($matrix) || !$this->csvMatrixLooksCollapsed($retry)) {
                $matrix = $retry;
                $separador = $alt;
            }
        }

        return $this->matrixToInvoiceItems($matrix, $separador);
    }

    /** @param list<list<string>> $matrix */
    private function csvMatrixLooksCollapsed(array $matrix): bool
    {
        $n = min(20, count($matrix));
        if ($n === 0) {
            return true;
        }
        $single = 0;
        for ($i = 0; $i < $n; $i++) {
            if (count($matrix[$i]) <= 1) {
                $single++;
            }
        }
        return $single >= (int)ceil($n * 0.7);
    }

    /** @return list<list<string>> */
    private function parseCsvWithSeparator(string $raw, string $separador): array
    {
        $handle = fopen('php://memory', 'r+');
        if (!$handle) {
            return [];
        }
        fwrite($handle, $raw);
        rewind($handle);
        $matrix = [];
        while (($campos = fgetcsv($handle, 0, $separador)) !== false) {
            if ($campos === [null] || $campos === false) {
                continue;
            }
            $campos = array_map(fn($v) => trim((string)$v), $campos);
            while ($campos !== [] && end($campos) === '') {
                array_pop($campos);
            }
            if ($campos === [] || implode('', $campos) === '') {
                continue;
            }
            $matrix[] = $campos;
        }
        fclose($handle);
        return $matrix;
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

    /** Elige ; , o tab según las primeras filas (no solo la línea 1 de metadatos). */
    private function detectCsvSeparatorFromRaw(string $raw): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
        $candidates = ["\t", ';', ','];
        $best = ',';
        $bestScore = -1;
        foreach ($candidates as $sep) {
            $counts = [];
            $n = 0;
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line === '') {
                    continue;
                }
                $counts[] = substr_count($line, $sep);
                if (++$n >= 20) {
                    break;
                }
            }
            if ($counts === []) {
                continue;
            }
            sort($counts);
            $median = $counts[(int)floor((count($counts) - 1) / 2)];
            if ($median < 1) {
                continue;
            }
            $freq = array_count_values($counts);
            $score = ($median * 10) + (max($freq) * 5);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $sep;
            }
        }
        if ($bestScore < 0) {
            $primera = trim((string)($lines[0] ?? ''));
            return $this->detectCsvSeparator($primera);
        }
        return $best;
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
        $unitCostRaw = $this->csvValue($campos, $mapa, 'unit_cost');
        $unitCost = $this->toNumber($unitCostRaw);
        // Nunca tomar un EAN/código de barras como precio.
        if ($unitCost > 0 && ($this->looksLikeBarcode($unitCostRaw) || ($barcode !== '' && abs($unitCost - (float)$barcode) < 0.01))) {
            $unitCost = 0.0;
        }
        if ($unitCost >= 1.0e11) {
            $unitCost = 0.0;
        }

        $taxRate = $this->toNumber($this->csvValue($campos, $mapa, 'tax_rate'));
        // Si la columna "IVA" trae monto (ej. 2850) y no porcentaje, ignorarla.
        if ($taxRate > 100) {
            $taxRate = 0.0;
        }

        $fila = [
            'raw_line' => implode($separador, $campos),
            'supplier_sku' => $sku,
            'barcode' => $barcode,
            'description' => $this->csvValue($campos, $mapa, 'description'),
            'quantity' => $this->toQuantity($this->csvValue($campos, $mapa, 'quantity')),
            'bonus_qty' => $this->toQuantity($this->csvValue($campos, $mapa, 'bonus_qty')),
            'unit_cost' => $unitCost,
            'tax_rate' => $taxRate,
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

        $out = [];
        foreach ($filas as $f) {
            $hasAmt = (float)($f['quantity'] ?? 0) > 0
                || (float)($f['unit_cost'] ?? 0) > 0
                || (float)($f['line_total'] ?? 0) > 0;
            if (!$hasAmt) {
                $campos = $f['_fields'] ?? [];
                if (is_array($campos) && $campos !== []) {
                    $rescate = $this->inferAmountsFromFields($campos, (string)($f['description'] ?? ''));
                    $f['quantity'] = $rescate['quantity'];
                    $f['unit_cost'] = $rescate['unit_cost'];
                    $f['line_total'] = $rescate['line_total'];
                    if (($f['description'] ?? '') === '' && $rescate['description'] !== '') {
                        $f['description'] = $rescate['description'];
                    }
                    if (($f['supplier_sku'] ?? '') === '' && $rescate['supplier_sku'] !== '') {
                        $f['supplier_sku'] = $rescate['supplier_sku'];
                    }
                    $f = $this->normalizeInvoiceLineAmounts($f);
                }
            }
            unset($f['_fields']);
            $out[] = $f;
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
                'sku', 'codigo', 'código', 'code', 'referencia', 'ref', 'ref.', 'codigo_proveedor',
                'sku_proveedor', 'cod', 'itemcode', 'codigoproducto', 'plu', 'idproducto',
                'id', 'idd', 'item_id', 'product_id', 'id_producto', 'codigointerno',
                'codinterno', 'sku_interno', 'parte', 'partnumber', 'pn', 'supplierref',
                'codigoref', 'itemref',
            ],
            'barcode' => [
                'ean', 'barcode', 'codigo_barras', 'codigobarras', 'barras', 'upc', 'gtin',
                'ean13', 'ean8', 'codigo_ean', 'codigodebarras', 'bar_code', 'cbarras',
                'codebar', 'codigobarra',
            ],
            'description' => [
                'descripcion', 'descripción', 'description', 'producto', 'nombre', 'detalle',
                'item', 'articulo', 'concepto', 'product', 'name', 'nomproducto', 'desc',
                'productname', 'itemdescription',
            ],
            'quantity' => [
                'cantidad', 'qty', 'quantity', 'cant', 'unidades', 'und', 'uds', 'ctd',
                'cant.', 'q', 'cantidadpedida', 'cantidadfacturada', 'cnt', 'cantidadund',
                'qtyordered', 'shippedqty',
            ],
            'bonus_qty' => [
                'bonif', 'bonificacion', 'bonificación', 'bonus', 'regalo', 'obsequio',
                'cantbonif', 'qtybonus', 'freeqty', 'bonificados',
            ],
            'unit_cost' => [
                'costo_unitario', 'precio_unitario', 'valor_unitario', 'vr_unitario',
                'valorunitario', 'vrunitario', 'costounitario', 'preciounitario',
                'unit_cost', 'unitprice', 'unit_price', 'punit', 'vu', 'vunitario', 'costo',
                'precio', 'price', 'pricenet', 'netprice', 'unitcost',
            ],
            'tax_rate' => [
                'iva', 'impuesto', 'tax', 'tax_rate', 'porcentaje_iva', 'pctiva', 'porciva',
                'tarifaiva', 'taxpercent', 'taxpct', 'iva%', 'tax%',
            ],
            'discount_pct' => ['descuento', 'discount', 'dcto', 'descuento_pct', 'porcdescuento'],
            'line_total' => [
                'total_linea', 'line_total', 'valor_total', 'valortotal', 'importe',
                'subtotal', 'totalitem', 'valortot', 'neto', 'total', 'linetotal',
                'extendedprice', 'amount',
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
            if ($normalizado === '' || in_array($normalizado, ['tipo', 'estado', 'n', 'no', 'num', 'numero', 'taxval', 'taxvalue', 'valoriva', 'ivavalor'], true)) {
                continue;
            }
            // "Tax Val." / valor del impuesto: no es precio unitario ni total de línea.
            if (str_contains($normalizado, 'taxval') || str_contains($normalizado, 'valorimpuesto')
                || $normalizado === 'taxval' || str_ends_with($normalizado, 'taxval')) {
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
                    if ($clave === 'description' && in_array($normalizado, ['proveedor', 'cliente', 'factura', 'vendor'], true)) {
                        continue;
                    }
                    // Preferir "price" exacto sobre "precio" genérico confuso.
                    if ($clave === 'unit_cost' && $normalizado === 'price') {
                        $score += 15;
                    }
                    if ($clave === 'barcode' && $normalizado === 'ean') {
                        $score += 20;
                    }
                    if ($clave === 'supplier_sku' && ($normalizado === 'ref' || $normalizado === 'referencia')) {
                        $score += 15;
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
            // Permitir EAN/SKU + cantidad + costo/total sin columna descripción.
            if (!isset($mapa['barcode']) && !isset($mapa['supplier_sku'])) {
                return [];
            }
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
        // Formato típico export proveedor: #, Ref, EAN, Description, Qty, Price, Tax%, TaxVal, Total
        if ($n >= 9) {
            return [
                'supplier_sku' => 1,
                'barcode' => 2,
                'description' => 3,
                'quantity' => 4,
                'unit_cost' => 5,
                'tax_rate' => 6,
                'line_total' => $n - 1,
            ];
        }
        if ($n >= 7) {
            return [
                'supplier_sku' => 0,
                'barcode' => 1,
                'description' => 2,
                'quantity' => 3,
                'unit_cost' => 4,
                'tax_rate' => 5,
                'line_total' => $n - 1,
            ];
        }
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
                'nombre', 'costo', 'precio', 'price', 'valorunitario', 'valortotal', 'importe', 'sku',
                'codigo', 'total', 'iva', 'descuento', 'unidades', 'ean', 'barcode', 'ref', 'referencia',
            ] as $token) {
                if ($normalizado === $token || str_starts_with($normalizado, $token) || str_ends_with($normalizado, $token)) {
                    // Evitar que un SKU de producto ("SKU99") cuente como encabezado.
                    if (in_array($token, ['sku', 'codigo', 'total', 'ref'], true) && preg_match('/\d/', $normalizado)) {
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

        // Si el "costo" es un EAN numérico, invalidar.
        if ($cost > 0 && $this->looksLikeBarcode((string)(int)round($cost)) && $cost > 1.0e9) {
            $cost = 0.0;
        }

        // Si total ≈ qty * cost (sin IVA), no reaplicar IVA encima.
        $netSimple = ($qty > 0 && $cost > 0) ? round($qty * $cost * (1 - $disc / 100), 2) : 0.0;
        $totalsMatchNet = $total > 0 && $netSimple > 0 && abs($total - $netSimple) <= max(1.0, $netSimple * 0.02);

        if ($qty <= 0 && $total > 0 && $cost > 0) {
            $netFactor = (1 - $disc / 100) * ($totalsMatchNet ? 1.0 : (1 + $tax / 100));
            if ($netFactor > 0) {
                $qty = round($total / ($cost * $netFactor), 3);
            }
        }
        if ($qty <= 0 && $total > 0 && $cost <= 0) {
            $qty = 1.0;
            $netFactor = (1 - $disc / 100) * ($totalsMatchNet || $tax <= 0 ? 1.0 : (1 + $tax / 100));
            $cost = $netFactor > 0 ? round($total / $netFactor, 2) : $total;
        }
        if ($cost <= 0 && $qty > 0 && $total > 0) {
            $netFactor = (1 - $disc / 100) * ($totalsMatchNet || $tax <= 0 ? 1.0 : (1 + $tax / 100));
            $cost = $netFactor > 0 ? round($total / ($qty * $netFactor), 2) : round($total / $qty, 2);
        }
        if ($total <= 0 && $qty > 0 && $cost > 0) {
            // Muchos CSV traen Price y Total sin IVA; no forzar +19% si el total no venía.
            $total = round($qty * $cost * (1 - $disc / 100), 2);
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
            $paidQty = (float)$amounts['quantity'];
            $bonusQty = (float)$amounts['bonus'];
            // Solo bonificación: CANT 0 + BONIF > 0.
            if ($paidQty <= 0 && $bonusQty > 0) {
                $paidQty = 0.0;
                $amounts['quantity'] = $bonusQty;
                $amounts['unit_cost'] = 0.0;
                $amounts['line_total'] = 0.0;
                $bonusQty = 0.0; // ya va en quantity
            }
            if ($amounts['quantity'] <= 0 && $amounts['unit_cost'] <= 0 && $amounts['line_total'] <= 0 && $bonusQty <= 0) {
                $amounts['quantity'] = 1;
            }

            if ((float)$amounts['quantity'] > 0) {
                $isBonusOnly = $amounts['unit_cost'] <= 0 && $amounts['line_total'] <= 0
                    && $paidQty <= 0 && (float)$amounts['quantity'] > 0;
                $filas[] = [
                    'raw_line' => 'ID: ' . $sku . ' / EAN: ' . $ean . ' ' . $name
                        . ($isBonusOnly ? ' [Bonificación]' : ''),
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
            // CANT + BONIF: línea paga + línea gratis del mismo producto (trazabilidad).
            if ($bonusQty > 0 && $paidQty > 0) {
                $filas[] = [
                    'raw_line' => 'ID: ' . $sku . ' / EAN: ' . $ean . ' ' . $name . ' [Bonificación]',
                    'supplier_sku' => $sku,
                    'barcode' => $ean,
                    'description' => $name,
                    'quantity' => $bonusQty,
                    'unit_cost' => 0.0,
                    'tax_rate' => 0.0,
                    'discount_pct' => 0.0,
                    'line_total' => 0.0,
                    'lot_code' => '',
                    'expiry_date' => null,
                    'sale_price' => 0.0,
                ];
            }
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
            $out['quantity'] = $this->toQuantity($pair[1]);
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
                $candSku = trim($partes[1]);
                $candDesc = trim($partes[2]);
                // Evita tratar la primera palabra del nombre (ej. "AMPOLLAS VA") como SKU.
                if ($this->isReadableProductDescription($candDesc)) {
                    $sku = $candSku;
                    $descripcion = $candDesc;
                    if ($barcode === '' && $this->looksLikeBarcode($sku)) {
                        $barcode = $this->normalizeBarcodeValue($sku);
                    }
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
                'quantity' => $this->toQuantity((string)($item['quantity'] ?? '0')),
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
        $rows = $this->query(
            "SELECT i.*, s.name supplier_name,
                    (SELECT COUNT(*) FROM purchase_invoice_import_items it WHERE it.import_id = i.id) items_count
             FROM purchase_invoice_imports i
             LEFT JOIN suppliers s ON s.id = i.supplier_id
             WHERE i.status = 'pending'
             ORDER BY i.id DESC
             LIMIT " . max(1, min(200, $limit))
        )->fetchAll();

        // Total de listado = neto a pagar (bruto − desc. comercial − pronto pago), no el bruto.
        foreach ($rows as &$row) {
            $disc = PurchasingService::resolvePurchaseDiscounts(
                (float)($row['subtotal'] ?? 0),
                (float)($row['tax_total'] ?? 0),
                (float)($row['early_payment_discount_pct'] ?? 0),
                (float)($row['commercial_discount_pct'] ?? 0),
                (float)($row['commercial_discount_amount'] ?? 0)
            );
            $row['total'] = $disc['total'];
            $row['gross_total'] = $disc['gross'];
        }
        unset($row);

        return $rows;
    }

    // ------------------------------------------------------------ edición

    /**
     * Guarda las correcciones hechas en el modal de confirmación.
     *
     * @param array<int,array<string,mixed>> $rows Indexado por id de línea
     */
    public function updateItems(int $importId, array $rows): void
    {
        $imp = $this->query(
            "SELECT document_kind FROM purchase_invoice_imports WHERE id = ? LIMIT 1",
            [$importId]
        )->fetch();
        $vatInCost = self::documentEmbedsVatInCost((string)($imp['document_kind'] ?? ''));

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
                'quantity' => $this->toQuantity((string)($row['quantity'] ?? '0')),
                'unit_cost' => $this->toNumber((string)($row['unit_cost'] ?? '0')),
                'tax_rate' => $this->toNumber((string)($row['tax_rate'] ?? '0')),
                'discount_pct' => $this->toNumber((string)($row['discount_pct'] ?? '0')),
                'line_total' => $this->toNumber((string)($row['line_total'] ?? '0')),
                'description' => $row['description'] ?? '',
            ] + $row);
            $cantidad = round(max(0, (float)($row['quantity'] ?? 0)), 3);
            $costo = round(max(0, (float)($row['unit_cost'] ?? 0)), 2);
            $impuesto = round(max(0, min(100, (float)($row['tax_rate'] ?? 0))), 2);
            $descuento = round(max(0, min(100, (float)($row['discount_pct'] ?? 0))));
            $productoId = !empty($row['matched_product_id']) ? (int)$row['matched_product_id'] : null;
            $accion = in_array($row['row_action'] ?? '', ['add_stock', 'create_product', 'skip'], true)
                ? (string)$row['row_action']
                : 'add_stock';
            if ($accion === 'add_stock' && $productoId === null) {
                $accion = 'create_product';
            }

            $lineTotal = $vatInCost
                ? round($cantidad * $costo * (1 - $descuento / 100), 2)
                : round($cantidad * $costo * (1 - $descuento / 100) * (1 + $impuesto / 100), 2);

            $stmt->execute([
                substr(trim((string)($row['description'] ?? '')), 0, 255),
                $cantidad,
                $costo,
                $impuesto,
                $descuento,
                $lineTotal,
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
     * Mismo SKU puede coexistir en filas separadas cuando una es bonificación ($0)
     * y otra es pagada, o si se fuerza fila aparte (forceSeparate).
     *
     * @return array<string,mixed> Línea creada o actualizada
     */
    public function addManualItem(
        int $importId,
        int $productId,
        float $quantity = 1.0,
        ?float $unitCost = null,
        ?float $taxRate = null,
        ?float $salePrice = null,
        bool $asBonus = false,
        bool $forceSeparate = false
    ): array {
        $import = $this->query(
            "SELECT id, status, supplier_id FROM purchase_invoice_imports WHERE id = ? LIMIT 1",
            [$importId]
        )->fetch();
        if (!$import || ($import['status'] ?? '') !== 'pending') {
            throw new \RuntimeException('Solo se pueden agregar líneas a facturas pendientes de confirmar');
        }

        $product = $this->query(
            "SELECT id, code, barcode, name, purchase_price, sale_price, COALESCE(tax_rate, 0) AS tax_rate
             FROM products
             WHERE id = ? AND status = 'active' AND COALESCE(product_type, 'product') = 'product'
             LIMIT 1",
            [$productId]
        )->fetch();
        if (!$product) {
            throw new \RuntimeException('Producto no encontrado o inactivo');
        }

        $qty = round(max(0.001, $quantity), 3);
        if ($asBonus) {
            $cost = 0.0;
            $unitCost = 0.0;
            $forceSeparate = true;
        } else {
            $cost = $unitCost !== null && $unitCost >= 0
                ? round($unitCost, 2)
                : round((float)$product['purchase_price'], 2);
        }
        $newIsBonus = $cost <= 0.009;

        $impMeta = $this->query(
            "SELECT document_kind, apply_product_vat FROM purchase_invoice_imports WHERE id = ? LIMIT 1",
            [$importId]
        )->fetch();
        $docKind = self::normalizeDocumentKind((string)($impMeta['document_kind'] ?? ''));
        $applyVat = (int)($impMeta['apply_product_vat'] ?? 0) === 1;
        if ($taxRate !== null) {
            $tax = round(max(0, min(100, $taxRate)), 2);
            if ($docKind === self::DOC_REMISSION) {
                $tax = 0.0;
            } elseif ($docKind === self::DOC_ELECTRONIC) {
                $tax = TaxService::defaultRate($this->db);
            }
        } elseif ($docKind !== '') {
            $tax = $this->resolveLineTaxRateForDocument(
                $docKind,
                $applyVat,
                (float)($product['tax_rate'] ?? 0),
                false // 0 del catálogo = exento, no “falta tasa”
            );
        } else {
            $tax = 0.0;
        }

        $vatInCost = self::documentEmbedsVatInCost($docKind);
        // Costo manual explícito en F. Electrónica: se asume neto del proveedor y se le suma IVA.
        // Si viene del catálogo (purchase_price), ya es valor de compra total.
        if ($vatInCost && $tax > 0.009 && $unitCost !== null && $unitCost >= 0 && $cost > 0) {
            $cost = round($cost * (1 + $tax / 100), 2);
        }

        // Solo fusiona si ya hay una fila del mismo producto con la misma naturaleza
        // (pagada vs bonificación). Normal + bonif. del mismo SKU = filas distintas.
        if (!$forceSeparate) {
            $existingRows = $this->query(
                "SELECT id, quantity, unit_cost, tax_rate, discount_pct, line_total
                 FROM purchase_invoice_import_items
                 WHERE import_id = ? AND matched_product_id = ? AND row_action = 'add_stock'
                 ORDER BY id DESC",
                [$importId, $productId]
            )->fetchAll() ?: [];
            $existing = null;
            foreach ($existingRows as $rowCand) {
                $exCost = (float)($rowCand['unit_cost'] ?? 0);
                $exTotal = (float)($rowCand['line_total'] ?? 0);
                $exQty = (float)($rowCand['quantity'] ?? 0);
                $exIsBonus = $exQty > 0 && $exCost <= 0.009 && $exTotal <= 0.009;
                if ($exIsBonus === $newIsBonus) {
                    $existing = $rowCand;
                    break;
                }
            }
            if ($existing) {
                $newQty = round((float)$existing['quantity'] + $qty, 3);
                $useCost = $newIsBonus
                    ? 0.0
                    : ($cost > 0 ? $cost : (float)$existing['unit_cost']);
                $useTax = (float)$existing['tax_rate'];
                $dst = (float)$existing['discount_pct'];
                $lineTotal = $vatInCost
                    ? round($newQty * $useCost * (1 - $dst / 100), 2)
                    : round($newQty * $useCost * (1 - $dst / 100) * (1 + $useTax / 100), 2);
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
        }

        $lineTotal = $vatInCost
            ? round($qty * $cost, 2)
            : round($qty * $cost * (1 + $tax / 100), 2);
        $sale = $salePrice !== null && $salePrice > 0
            ? round($salePrice, 2)
            : (isset($product['sale_price']) && (float)$product['sale_price'] > 0
                ? round((float)$product['sale_price'], 2)
                : null);

        $lineNo = (int)$this->query(
            "SELECT COALESCE(MAX(line_no), 0) + 1 FROM purchase_invoice_import_items WHERE import_id = ?",
            [$importId]
        )->fetchColumn();

        $rawLine = $asBonus || $newIsBonus
            ? 'Manual / bonificación'
            : 'Manual / escáner';
        $desc = substr((string)$product['name'], 0, 255);
        if (($asBonus || $newIsBonus) && stripos($desc, 'bonific') === false) {
            $desc = substr($desc . ' (bonificación)', 0, 255);
        }

        $this->query(
            "INSERT INTO purchase_invoice_import_items
                (import_id, line_no, raw_line, supplier_sku, barcode, description, quantity, unit_cost,
                 tax_rate, discount_pct, line_total, matched_product_id, match_type, row_action,
                 lot_code, expiry_date, sale_price, confidence)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 'manual', 'add_stock', NULL, NULL, ?, 100)",
            [
                $importId,
                $lineNo,
                $rawLine,
                substr(trim((string)($product['code'] ?? '')), 0, 120) ?: null,
                $this->normalizeBarcodeValue((string)($product['barcode'] ?? '')) ?: null,
                $desc,
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

        $this->logLifecycleEvent(
            $importId,
            null,
            'invoice_edited',
            ($asBonus || $newIsBonus) ? 'Bonificación agregada en revisión' : 'Línea agregada en revisión',
            trim((string)($product['name'] ?? '') . ' · cant. ' . $qty . (($asBonus || $newIsBonus) ? ' · $0' : '')),
            ['product_id' => $productId, 'item_id' => $itemId, 'as_bonus' => ($asBonus || $newIsBonus)]
        );

        $row = $this->query(
            "SELECT * FROM purchase_invoice_import_items WHERE id = ? LIMIT 1",
            [$itemId]
        )->fetch();

        return is_array($row) ? $row : ['id' => $itemId];
    }

    /**
     * Elimina una línea del borrador de factura (revisión pendiente).
     *
     * @return array{import:array,items:array}
     */
    public function removeItem(int $importId, int $itemId): array
    {
        $import = $this->query(
            "SELECT id, status FROM purchase_invoice_imports WHERE id = ? LIMIT 1",
            [$importId]
        )->fetch();
        if (!$import || ($import['status'] ?? '') !== 'pending') {
            throw new \RuntimeException('Solo se pueden eliminar líneas de facturas pendientes de confirmar');
        }
        if ($itemId <= 0) {
            throw new \RuntimeException('Línea no válida');
        }

        $row = $this->query(
            "SELECT id, description, quantity, matched_product_id
             FROM purchase_invoice_import_items
             WHERE id = ? AND import_id = ?
             LIMIT 1",
            [$itemId, $importId]
        )->fetch();
        if (!$row) {
            throw new \RuntimeException('La línea ya no existe en este borrador');
        }

        $this->query(
            "DELETE FROM purchase_invoice_import_items WHERE id = ? AND import_id = ?",
            [$itemId, $importId]
        );
        $this->refreshTotals($importId);

        $this->logLifecycleEvent(
            $importId,
            null,
            'invoice_edited',
            'Línea eliminada en revisión',
            trim((string)($row['description'] ?? '') . ' · cant. ' . (string)($row['quantity'] ?? '')),
            ['item_id' => $itemId, 'product_id' => (int)($row['matched_product_id'] ?? 0)]
        );

        $fresh = $this->get($importId);
        return [
            'import' => $fresh['import'] ?? [],
            'items' => $fresh['items'] ?? [],
        ];
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
            try {
                $this->query(
                    "DELETE FROM purchase_invoice_import_items
                     WHERE import_id = ? AND match_type = 'purchase_order'",
                    [$importId]
                );
            } catch (\Throwable $e) {
                // ignore
            }
            $this->refreshTotals($importId);
            $fresh = $this->get($importId);
            $this->logLifecycleEvent(
                $importId,
                null,
                'invoice_oc_unlinked',
                'OC desvinculada del borrador',
                'Se quitaron las filas asociadas a la orden de compra'
            );
            return [
                'import' => $fresh['import'] ?? $data['import'],
                'items' => $fresh['items'] ?? $data['items'],
                'added' => 0,
                'updated' => 0,
                'message' => 'OC desvinculada (se quitaron las filas amarillas de la OC)',
                'order_match' => null,
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
        $existingOcByProduct = [];
        foreach ($data['items'] as $it) {
            $pid = (int)($it['matched_product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            if (($it['match_type'] ?? '') === 'purchase_order') {
                $existingOcByProduct[$pid] = $it;
                continue;
            }
            $byProduct[$pid] = $it;
        }

        // Quitar filas OC de productos que ya no están en la orden casada.
        $orderProductIds = [];
        foreach ($order['items'] as $oi) {
            $opid = (int)($oi['product_id'] ?? 0);
            if ($opid > 0) {
                $orderProductIds[$opid] = true;
            }
        }
        foreach ($existingOcByProduct as $pid => $ocRow) {
            if (!isset($orderProductIds[$pid])) {
                $this->query(
                    "DELETE FROM purchase_invoice_import_items WHERE id = ? AND import_id = ?",
                    [(int)$ocRow['id'], $importId]
                );
                unset($existingOcByProduct[$pid]);
            }
        }
        // Recargar ítems tras limpieza
        $data = $this->get($importId) ?: $data;
        $byProduct = [];
        $existingOcByProduct = [];
        foreach ($data['items'] as $it) {
            $pid = (int)($it['matched_product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            if (($it['match_type'] ?? '') === 'purchase_order') {
                $existingOcByProduct[$pid] = $it;
                continue;
            }
            $byProduct[$pid] = $it;
        }

        $added = 0;
        $updated = 0;
        $orderLabel = (string)($order['order_number'] ?? ('#' . $orderId));
        // Si el borrador ya trae líneas de factura, no se agregan los ítems solo-OC
        // (la factura puede ser parcial). Solo se completan qty/costo de coincidencias.
        $invoiceHasLines = false;
        foreach ($data['items'] as $it) {
            if (($it['row_action'] ?? '') !== 'skip') {
                $invoiceHasLines = true;
                break;
            }
        }

        $impMeta = $this->query(
            "SELECT document_kind, apply_product_vat FROM purchase_invoice_imports WHERE id = ? LIMIT 1",
            [$importId]
        )->fetch() ?: [];
        $docKind = self::normalizeDocumentKind((string)($impMeta['document_kind'] ?? ''));
        $applyVat = (int)($impMeta['apply_product_vat'] ?? 0) === 1;
        $defaultTax = TaxService::defaultRate($this->db);
        $vatInCost = self::documentEmbedsVatInCost($docKind);

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
                // Respetar tasa del import (0 válido en remisión/exento); solo completar si falta.
                $exTax = (float)($ex['tax_rate'] ?? 0);
                if ($docKind === self::DOC_REMISSION || ($docKind === self::DOC_STANDARD && !$applyVat)) {
                    $useTax = 0.0;
                } elseif ($docKind === self::DOC_ELECTRONIC) {
                    $useTax = $defaultTax;
                } elseif ($exTax > 0.009) {
                    $useTax = $exTax;
                } else {
                    $useTax = $tax > 0.009 ? $tax : ($applyVat ? $defaultTax : 0.0);
                }
                // OC suele traer costo neto: en F. Electrónica incorporar IVA al completar costo.
                if ($vatInCost && $needCost && $useTax > 0.009 && $newCost > 0) {
                    $newCost = round($newCost * (1 + $useTax / 100), 2);
                }
                $dst = (float)($ex['discount_pct'] ?? 0);
                $lineTotal = $vatInCost
                    ? round($newQty * $newCost * (1 - $dst / 100), 2)
                    : round($newQty * $newCost * (1 - $dst / 100) * (1 + $useTax / 100), 2);
                $this->query(
                    "UPDATE purchase_invoice_import_items
                     SET quantity = ?, unit_cost = ?, tax_rate = ?, line_total = ?
                     WHERE id = ? AND import_id = ?",
                    [$newQty, $newCost, $useTax, $lineTotal, (int)$ex['id'], $importId]
                );
                $updated++;
                continue;
            }

            if (isset($existingOcByProduct[$pid])) {
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

            // Factura parcial: mostrar ítems solo-OC en amarillo (omitidos por defecto).
            $defaultAction = $invoiceHasLines ? 'skip' : 'add_stock';

            $ocCost = $cost;
            $ocTax = $tax;
            if ($docKind === self::DOC_REMISSION || ($docKind === self::DOC_STANDARD && !$applyVat)) {
                $ocTax = 0.0;
            } elseif ($docKind === self::DOC_ELECTRONIC) {
                $ocTax = $defaultTax;
                if ($ocCost > 0) {
                    $ocCost = round($ocCost * (1 + $ocTax / 100), 2);
                }
            }
            $lineTotal = $vatInCost
                ? round($qty * $ocCost, 2)
                : round($qty * $ocCost * (1 + $ocTax / 100), 2);
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
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 'purchase_order', ?, ?, ?, ?, 100)",
                [
                    $importId,
                    $lineNo,
                    'Desde OC ' . $orderLabel,
                    substr(trim((string)($oi['product_code'] ?? $product['code'] ?? '')), 0, 120) ?: null,
                    $this->normalizeBarcodeValue((string)($oi['barcode'] ?? $product['barcode'] ?? '')) ?: null,
                    substr((string)($oi['product_name'] ?? $product['name']), 0, 255),
                    $qty,
                    $ocCost,
                    $ocTax,
                    $lineTotal,
                    $pid,
                    $defaultAction,
                    !empty($oi['lot_code']) ? substr((string)$oi['lot_code'], 0, 60) : null,
                    $this->normalizeDate($oi['expiry_date'] ?? null),
                    $sale,
                ]
            );
            $added++;
            $existingOcByProduct[$pid] = ['id' => (int)$this->db->lastInsertId()];
        }

        $this->reapplyDocumentTaxToImport($importId);
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
            $parts[] = $invoiceHasLines
                ? 'OC casada: productos solo en OC visibles en amarillo (omitidos por defecto; cambie a Ingresar si aplica)'
                : 'OC casada (los productos ya estaban en el borrador)';
        }

        $this->logLifecycleEvent(
            $importId,
            $orderId,
            'invoice_oc_linked',
            'OC casada en revisión',
            trim($orderLabel . ' · ' . implode('. ', $parts)),
            ['order_id' => $orderId, 'added' => $added, 'updated' => $updated]
        );

        return [
            'import' => $fresh['import'] ?? $data['import'],
            'items' => $fresh['items'] ?? [],
            'added' => $added,
            'updated' => $updated,
            'message' => implode('. ', $parts),
            'order_match' => $this->buildOrderMatchReport($importId, $orderId),
        ];
    }

    /**
     * Cruza líneas de la factura (borrador) con la OC vinculada.
     * Compara por producto: cantidades, costos y faltantes/sobrantes.
     *
     * @return array<string,mixed>|null
     */
    public function buildOrderMatchReport(int $importId, ?int $orderId = null): ?array
    {
        $data = $this->get($importId);
        if (!$data) {
            return null;
        }
        $orderId = $orderId ?? (int)($data['import']['linked_order_id'] ?? 0);
        if ($orderId <= 0) {
            return null;
        }

        $purchasing = new PurchasingService($this->db);
        $order = $purchasing->getOrder($orderId);
        if (!$order) {
            return null;
        }

        /** @var array<int,array{qty:float,cost:float,name:string,lines:int}> $ocByProduct */
        $ocByProduct = [];
        foreach ($order['items'] as $oi) {
            $pid = (int)($oi['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            if (!isset($ocByProduct[$pid])) {
                $ocByProduct[$pid] = [
                    'qty' => 0.0,
                    'cost' => 0.0,
                    'name' => (string)($oi['product_name'] ?? ('#' . $pid)),
                    'lines' => 0,
                    'cost_sum' => 0.0,
                ];
            }
            $qty = max(0.0, (float)($oi['quantity'] ?? 0));
            $cost = max(0.0, (float)($oi['unit_cost'] ?? 0));
            $ocByProduct[$pid]['qty'] += $qty;
            $ocByProduct[$pid]['cost_sum'] += $cost * $qty;
            $ocByProduct[$pid]['lines']++;
            if ($qty > 0) {
                $ocByProduct[$pid]['cost'] = round($ocByProduct[$pid]['cost_sum'] / $ocByProduct[$pid]['qty'], 2);
            }
            if ($ocByProduct[$pid]['name'] === '' || $ocByProduct[$pid]['name'] === ('#' . $pid)) {
                $ocByProduct[$pid]['name'] = (string)($oi['product_name'] ?? ('#' . $pid));
            }
        }

        /** @var array<int,array{qty:float,cost:float,name:string,lines:int,cost_sum:float}> $invByProduct */
        $invByProduct = [];
        $unmatchedInvoice = [];
        foreach ($data['items'] as $it) {
            if (($it['row_action'] ?? '') === 'skip') {
                continue;
            }
            $pid = (int)($it['matched_product_id'] ?? 0);
            $qty = max(0.0, (float)($it['quantity'] ?? 0));
            $cost = max(0.0, (float)($it['unit_cost'] ?? 0));
            $name = trim((string)($it['matched_product_name'] ?? $it['description'] ?? ''));
            if ($pid <= 0) {
                $unmatchedInvoice[] = [
                    'description' => $name !== '' ? $name : 'Sin descripción',
                    'quantity' => round($qty, 3),
                    'unit_cost' => round($cost, 2),
                ];
                continue;
            }
            if (!isset($invByProduct[$pid])) {
                $invByProduct[$pid] = [
                    'qty' => 0.0,
                    'cost' => 0.0,
                    'name' => $name !== '' ? $name : ('#' . $pid),
                    'lines' => 0,
                    'cost_sum' => 0.0,
                ];
            }
            $invByProduct[$pid]['qty'] += $qty;
            $invByProduct[$pid]['cost_sum'] += $cost * max($qty, 0.0001);
            $invByProduct[$pid]['lines']++;
            if ($invByProduct[$pid]['qty'] > 0) {
                $invByProduct[$pid]['cost'] = round($invByProduct[$pid]['cost_sum'] / $invByProduct[$pid]['qty'], 2);
            }
            if ($name !== '') {
                $invByProduct[$pid]['name'] = $name;
            }
        }

        $matched = [];
        $qtyDiffs = [];
        $priceDiffs = [];
        $onlyInvoice = [];
        $onlyOrder = [];

        $allIds = array_unique(array_merge(array_keys($ocByProduct), array_keys($invByProduct)));
        sort($allIds);
        foreach ($allIds as $pid) {
            $oc = $ocByProduct[$pid] ?? null;
            $inv = $invByProduct[$pid] ?? null;
            $name = (string)($inv['name'] ?? $oc['name'] ?? ('#' . $pid));
            if ($oc && $inv) {
                $row = [
                    'product_id' => $pid,
                    'name' => $name,
                    'oc_qty' => round((float)$oc['qty'], 3),
                    'inv_qty' => round((float)$inv['qty'], 3),
                    'oc_cost' => round((float)$oc['cost'], 2),
                    'inv_cost' => round((float)$inv['cost'], 2),
                    'qty_delta' => round((float)$inv['qty'] - (float)$oc['qty'], 3),
                    'cost_delta' => round((float)$inv['cost'] - (float)$oc['cost'], 2),
                ];
                $matched[] = $row;
                if (abs($row['qty_delta']) > 0.0009) {
                    $qtyDiffs[] = $row;
                }
                if (abs($row['cost_delta']) > 0.009) {
                    $priceDiffs[] = $row;
                }
            } elseif ($inv) {
                $onlyInvoice[] = [
                    'product_id' => $pid,
                    'name' => $name,
                    'quantity' => round((float)$inv['qty'], 3),
                    'unit_cost' => round((float)$inv['cost'], 2),
                ];
            } else {
                $onlyOrder[] = [
                    'product_id' => $pid,
                    'name' => $name,
                    'quantity' => round((float)$oc['qty'], 3),
                    'unit_cost' => round((float)$oc['cost'], 2),
                ];
            }
        }

        $issues = count($qtyDiffs) + count($priceDiffs) + count($onlyInvoice) + count($onlyOrder) + count($unmatchedInvoice);
        $invoiceTotal = round((float)($data['import']['total'] ?? 0), 2);
        $orderTotal = round((float)($order['total'] ?? 0), 2);

        return [
            'order_id' => $orderId,
            'order_number' => (string)($order['order_number'] ?? ('#' . $orderId)),
            'order_total' => $orderTotal,
            'invoice_total' => $invoiceTotal,
            'total_delta' => round($invoiceTotal - $orderTotal, 2),
            'matched_count' => count($matched),
            'qty_diff_count' => count($qtyDiffs),
            'price_diff_count' => count($priceDiffs),
            'only_invoice_count' => count($onlyInvoice),
            'only_order_count' => count($onlyOrder),
            'unmatched_invoice_count' => count($unmatchedInvoice),
            'issue_count' => $issues,
            'ok' => $issues === 0,
            'matched' => $matched,
            'qty_diffs' => $qtyDiffs,
            'price_diffs' => $priceDiffs,
            'only_invoice' => $onlyInvoice,
            'only_order' => $onlyOrder,
            'unmatched_invoice' => $unmatchedInvoice,
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

        $headerParams = [
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
            isset($meta['commercial_discount_pct']) && $meta['commercial_discount_pct'] !== ''
                ? max(0, min(100, round((float)$meta['commercial_discount_pct'], 2)))
                : 0.0,
            isset($meta['commercial_discount_amount']) && $meta['commercial_discount_amount'] !== ''
                ? max(0, round((float)$meta['commercial_discount_amount'], 2))
                : 0.0,
            $importId,
        ];
        // Si hay monto $, anula % para que al confirmar no se recalcule mal.
        if ($headerParams[7] > 0.009) {
            $headerParams[6] = 0.0;
        }

        try {
            $this->query(
                "UPDATE purchase_invoice_imports SET
                    supplier_id = COALESCE(?, supplier_id),
                    invoice_number = COALESCE(NULLIF(?, ''), invoice_number),
                    invoice_date = COALESCE(?, invoice_date),
                    due_date = COALESCE(?, due_date),
                    warehouse_date = COALESCE(?, warehouse_date),
                    payment_mode = ?,
                    early_payment_discount_pct = ?,
                    commercial_discount_pct = ?,
                    commercial_discount_amount = ?
                 WHERE id = ? AND status = 'pending'",
                [
                    $headerParams[0],
                    $headerParams[1],
                    $headerParams[2],
                    $headerParams[3],
                    $this->normalizeDate($meta['warehouse_date'] ?? null),
                    $headerParams[4],
                    $headerParams[5],
                    $headerParams[6],
                    $headerParams[7],
                    $headerParams[8],
                ]
            );
        } catch (\Throwable $e) {
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
                    $headerParams[0],
                    $headerParams[1],
                    $headerParams[2],
                    $headerParams[3],
                    $headerParams[4],
                    $headerParams[5],
                    $headerParams[8],
                ]
            );
        }

        // IVA personalizado (F. Normal): monto exacto de la factura → 240802 al confirmar.
        if (array_key_exists('tax_override_amount', $meta) || array_key_exists('apply_product_vat', $meta)) {
            try {
                $overrideSql = null;
                $overrideVal = null;
                if (array_key_exists('tax_override_amount', $meta)) {
                    if ($meta['tax_override_amount'] === '' || $meta['tax_override_amount'] === null) {
                        $overrideSql = 'tax_override_amount = NULL';
                    } else {
                        $overrideSql = 'tax_override_amount = ?';
                        $overrideVal = round(max(0, (float)$meta['tax_override_amount']), 2);
                    }
                }
                $applySql = null;
                $applyVal = null;
                if (array_key_exists('apply_product_vat', $meta) && $meta['apply_product_vat'] !== null && $meta['apply_product_vat'] !== '') {
                    $applySql = 'apply_product_vat = ?';
                    $applyVal = self::parseApplyProductVat($meta['apply_product_vat']);
                }
                $sets = array_filter([$applySql, $overrideSql]);
                if ($sets !== []) {
                    $params = [];
                    if ($applyVal !== null) {
                        $params[] = $applyVal;
                    }
                    if ($overrideVal !== null) {
                        $params[] = $overrideVal;
                    }
                    $params[] = $importId;
                    $this->query(
                        'UPDATE purchase_invoice_imports SET ' . implode(', ', $sets)
                        . " WHERE id = ? AND status = 'pending'",
                        $params
                    );
                }
            } catch (\Throwable $e) {
                // Columna tax_override_amount aún no migrada.
            }
        }

        if (array_key_exists('review_notes', $meta)) {
            $reviewNotes = trim((string)($meta['review_notes'] ?? ''));
            if (function_exists('mb_substr')) {
                $reviewNotes = mb_substr($reviewNotes, 0, 2000);
            } else {
                $reviewNotes = substr($reviewNotes, 0, 2000);
            }
            try {
                $this->query(
                    "UPDATE purchase_invoice_imports
                     SET review_notes = NULLIF(?, '')
                     WHERE id = ? AND status = 'pending'",
                    [$reviewNotes, $importId]
                );
            } catch (\Throwable $e) {
                // Columna review_notes aún no migrada.
            }
        }

        try {
            $this->refreshTotals($importId);
        } catch (\Throwable $e) {
            // Borrador sin ítems o esquema incompleto.
        }
    }

    public function discard(int $importId, string $reason = ''): void
    {
        $actor = isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : null;
        if ($actor !== null && $actor <= 0) {
            $actor = null;
        }
        $reason = substr(trim($reason), 0, 255);
        try {
            $this->query(
                "UPDATE purchase_invoice_imports
                 SET status = 'discarded',
                     discarded_at = NOW(),
                     discarded_by = COALESCE(?, discarded_by),
                     parse_notes = COALESCE(NULLIF(?, ''), parse_notes)
                 WHERE id = ? AND status IN ('pending', 'processing')",
                [$actor, $reason, $importId]
            );
        } catch (\Throwable $e) {
            $this->query(
                "UPDATE purchase_invoice_imports
                 SET status = 'discarded',
                     parse_notes = COALESCE(NULLIF(?, ''), parse_notes)
                 WHERE id = ? AND status = 'pending'",
                [$reason, $importId]
            );
        }

        $linked = 0;
        try {
            $linked = (int)$this->query(
                "SELECT COALESCE(purchase_order_id, linked_order_id, 0)
                 FROM purchase_invoice_imports WHERE id = ? LIMIT 1",
                [$importId]
            )->fetchColumn();
        } catch (\Throwable $e) {
            $linked = 0;
        }
        $this->logLifecycleEvent(
            $importId,
            $linked > 0 ? $linked : null,
            'invoice_discarded',
            'Borrador descartado',
            $reason !== '' ? ('Motivo: ' . $reason) : 'Se eliminó de la cola de revisión'
        );
    }

    // ------------------------------------------------------------ confirmación

    /**
     * Convierte la importación en una orden de compra recibida:
     * crea los productos nuevos, ingresa el stock con su lote y contabiliza.
     *
     * @return array{order_id:int,created_products:int,received_items:int,skipped:int}
     */
    public function confirm(int $importId, ?string $warehouseDate = null, string $ocDisposition = 'close'): array
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
            return $this->confirmLocked($importId, $warehouseDate, $ocDisposition);
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
    private function confirmLocked(int $importId, ?string $warehouseDate = null, string $ocDisposition = 'close'): array
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

        return $this->confirmBuildOrder($importId, $data, $import, $supplierId, $warehouseDate, $ocDisposition);
    }

    private function releaseImportClaim(int $importId): void
    {
        try {
            // Si el stock ya entró (OC recibida), no devolver a pending: confirmar.
            $row = $this->query(
                "SELECT i.status, i.purchase_order_id, po.status AS order_status
                 FROM purchase_invoice_imports i
                 LEFT JOIN purchase_orders po ON po.id = i.purchase_order_id
                 WHERE i.id = ?
                 LIMIT 1",
                [$importId]
            )->fetch();
            if (
                $row
                && (string)($row['status'] ?? '') === 'processing'
                && (int)($row['purchase_order_id'] ?? 0) > 0
                && (string)($row['order_status'] ?? '') === 'received'
            ) {
                $this->query(
                    "UPDATE purchase_invoice_imports
                     SET status = 'confirmed',
                         confirmed_at = COALESCE(confirmed_at, NOW())
                     WHERE id = ? AND status = 'processing'",
                    [$importId]
                );
                return;
            }
        } catch (\Throwable $e) {
            // continuar con liberación clásica
        }
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
     * @return array{order_id:int,created_products:int,received_items:int,skipped:int,cash_note?:string,oc_kept_open?:bool,oc_remaining_qty?:int,source_order_id?:int}
     */
    private function confirmBuildOrder(
        int $importId,
        array $data,
        array $import,
        int $supplierId,
        ?string $warehouseDate,
        string $ocDisposition = 'close'
    ): array
    {
        $creados = 0;
        $omitidos = 0;
        $sinCantidad = 0;
        $lineas = [];
        $docKind = self::normalizeDocumentKind((string)($import['document_kind'] ?? ''));
        $vatInCost = self::documentEmbedsVatInCost($docKind);
        $applyMode = self::parseApplyProductVat($import['apply_product_vat'] ?? 0);
        $isCustomVat = $applyMode === 2;
        $taxOverrideAmt = $isCustomVat && isset($import['tax_override_amount']) && $import['tax_override_amount'] !== null && $import['tax_override_amount'] !== ''
            ? round(max(0, (float)$import['tax_override_amount']), 2)
            : null;
        if ($isCustomVat && ($taxOverrideAmt === null || $taxOverrideAmt < 0.01)) {
            throw new \RuntimeException(
                'IVA personalizado: indique el valor exacto del IVA de la factura antes de confirmar.'
            );
        }
        $catalogDefaultTax = TaxService::defaultRate($this->db);

        foreach ($data['items'] as $item) {
            if (($item['row_action'] ?? '') === 'skip') {
                $omitidos++;
                continue;
            }
            $item = $this->normalizeInvoiceLineAmounts([
                'quantity' => $this->toQuantity((string)($item['quantity'] ?? '0')),
                'unit_cost' => $this->toNumber((string)($item['unit_cost'] ?? '0')),
                'tax_rate' => $isCustomVat ? 0.0 : $this->toNumber((string)($item['tax_rate'] ?? '0')),
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
                $createItem = $item;
                if ($isCustomVat) {
                    // Catálogo de ventas: tasa estándar; el IVA de compra va prorrateado en purchase_price.
                    $createItem['tax_rate'] = $catalogDefaultTax;
                }
                $productoId = $this->createProductFromLine($createItem, $vatInCost);
                $creados++;
            }

            // El descuento de línea se traslada al costo: la OC no maneja descuento por ítem.
            $descuento = round(max(0, min(100, (float)($item['discount_pct'] ?? 0))));
            $costo = round((float)$item['unit_cost'] * (1 - $descuento / 100), 2);
            if ($costo <= 0 && (float)($item['line_total'] ?? 0) > 0) {
                $costo = round((float)$item['line_total'] / $cantidad, 2);
            }
            if ($costo < 0) {
                $costo = 0.0;
            }

            // F. Electrónica: IVA ya va en el costo → inventario al valor total; tasa 0 en la OC.
            // F. Normal %: costo neto + tax_rate en la OC (IVA descontable).
            // F. Normal personalizado: tax_rate 0 aquí; luego prorrateo de tax_override_amount.
            $lineTax = ($vatInCost || $isCustomVat) ? 0.0 : (float)($item['tax_rate'] ?? 0);
            $catalogTax = $isCustomVat
                ? $catalogDefaultTax
                : TaxService::normalizeRate((float)($item['tax_rate'] ?? 0));

            $lineas[] = [
                'product_id' => $productoId,
                'product_name' => (string)$item['description'],
                'quantity' => $cantidad,
                'unit_cost' => $costo,
                'tax_rate' => $lineTax,
                'catalog_tax_rate' => $catalogTax,
                'vat_in_cost' => $vatInCost,
                'lot_code' => (string)($item['lot_code'] ?? ''),
                'expiry_date' => $item['expiry_date'] ?? null,
                'barcode' => $this->normalizeBarcodeValue((string)($item['barcode'] ?? '')) ?: null,
                'product_code' => substr(trim((string)($item['supplier_sku'] ?? '')), 0, 255) ?: null,
            ];

            $this->links->link($productoId, $supplierId, array_filter([
                'supplier_sku' => (string)($item['supplier_sku'] ?? ''),
                'supplier_product_name' => (string)$item['description'],
                'last_cost' => $costo > 0.009 ? $costo : null,
                'last_purchase_date' => (string)($import['invoice_date'] ?? date('Y-m-d')),
            ], static fn($v) => $v !== null && $v !== ''));

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

        // IVA personalizado: prorratea el monto exacto de la factura entre líneas (cuadre 240802).
        if ($isCustomVat && $taxOverrideAmt !== null) {
            $sumBase = 0.0;
            foreach ($lineas as $ln) {
                $sumBase += max(0.0, (float)($ln['quantity'] ?? 0)) * max(0.0, (float)($ln['unit_cost'] ?? 0));
            }
            if ($sumBase <= 0.009) {
                throw new \RuntimeException(
                    'IVA personalizado: no se puede prorratear el IVA sobre líneas con costo $0. '
                    . 'Incluya al menos una línea con valor, o use bonificaciones junto a líneas pagadas.'
                );
            }
            $lineas = PurchasingService::applyTaxOverrideToLines($lineas, $taxOverrideAmt);
        }

        $purchasing = new PurchasingService($this->db);
        $fechaFactura = (string)($import['invoice_date'] ?? date('Y-m-d'));
        // Prioridad: lo enviado al confirmar → lo guardado en el borrador → fecha de factura.
        $fechaBodega = $this->normalizeDate($warehouseDate)
            ?? $this->normalizeDate($import['warehouse_date'] ?? null)
            ?? $fechaFactura;

        $linkedOrderId = !empty($import['linked_order_id']) ? (int)$import['linked_order_id'] : 0;
        $keepOpen = $linkedOrderId > 0 && $ocDisposition === 'keep_open';
        $orderId = 0;
        $sourceRemain = null;

        // Evitar duplicar la misma factura del proveedor (nueva importación).
        $invoiceNumber = trim((string)($import['invoice_number'] ?? ''));
        if ($invoiceNumber !== '') {
            $excludeId = (!$keepOpen && $linkedOrderId > 0) ? $linkedOrderId : 0;
            $dup = $this->query(
                "SELECT id, order_number, status FROM purchase_orders
                 WHERE supplier_id = ?
                   AND TRIM(COALESCE(invoice_number,'')) = ?
                   AND status IN ('received','ordered','draft')
                   AND id <> ?
                 ORDER BY id DESC
                 LIMIT 1",
                [$supplierId, $invoiceNumber, $excludeId]
            )->fetch();
            if ($dup) {
                throw new \RuntimeException(
                    'Ya existe la factura «' . $invoiceNumber . '» del proveedor'
                    . ' (OC ' . (string)($dup['order_number'] ?? ('#' . $dup['id'])) . ').'
                    . ' No se confirmó para evitar stock/CxP duplicados.'
                );
            }
        }

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

            if ($keepOpen) {
                // Factura parcial: crea OC/factura nueva y deja la OC origen con pendientes.
                $sourceLabel = (string)($linked['order_number'] ?? ('#' . $linkedOrderId));
                $orderId = $purchasing->createOrder(
                    $supplierId,
                    $fechaFactura,
                    $fechaBodega,
                    null,
                    'Factura importada #' . $importId . ' (parcial de OC ' . $sourceLabel . ')',
                    $lineas,
                    'ordered',
                    (float)($import['early_payment_discount_pct'] ?? 0),
                    $import['due_date'] ?? null,
                    $fechaFactura,
                    $import['invoice_number'] ?? null,
                    false,
                    (float)($import['commercial_discount_pct'] ?? 0),
                    (float)($import['commercial_discount_amount'] ?? 0)
                );
                $this->query(
                    "UPDATE purchase_orders SET origin = 'invoice_import', invoice_import_id = ?,
                        notes = CONCAT(COALESCE(notes,''),
                            CASE WHEN COALESCE(notes,'') = '' THEN '' ELSE ' | ' END,
                            'Cruce parcial OC ', ?)
                     WHERE id = ?",
                    [$importId, $sourceLabel, $orderId]
                );
                $sourceRemain = $purchasing->deductOpenOrderQuantities(
                    $linkedOrderId,
                    $lineas,
                    'Recepción parcial por factura importada #' . $importId
                );
            } else {
                $purchasing->replaceOrderItems($linkedOrderId, $lineas);
                $purchasing->applyInvoiceMeta($linkedOrderId, [
                    'due_date' => $import['due_date'] ?? null,
                    'invoice_date' => $fechaFactura,
                    'invoice_number' => $import['invoice_number'] ?? null,
                    'early_payment_discount_pct' => $import['early_payment_discount_pct'] ?? 0,
                    'commercial_discount_pct' => $import['commercial_discount_pct'] ?? 0,
                    'commercial_discount_amount' => $import['commercial_discount_amount'] ?? 0,
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
            }
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
                false, // no forzar descuento aliado sobre el total de factura
                (float)($import['commercial_discount_pct'] ?? 0),
                (float)($import['commercial_discount_amount'] ?? 0)
            );

            $this->query(
                "UPDATE purchase_orders SET origin = 'invoice_import', invoice_import_id = ? WHERE id = ?",
                [$importId, $orderId]
            );
        }

        // Vincular import → OC ANTES de receiveOrder: si falla después, releaseImportClaim
        // no devolverá el import a pending (evita doble ingreso de stock al reintentar).
        if ($orderId > 0) {
            try {
                $this->query(
                    "UPDATE purchase_invoice_imports SET purchase_order_id = ? WHERE id = ?",
                    [$orderId, $importId]
                );
            } catch (\Throwable $e) {
                // Continuar: el UPDATE final a confirmed también lo setea.
            }
        }

        $purchasing->receiveOrder(
            $orderId,
            $fechaBodega,
            (string)($import['invoice_number'] ?? ''),
            (string)($import['payment_mode'] ?? 'payable'),
            'Ingreso por factura importada'
        );

        $reviewNotes = trim((string)($import['review_notes'] ?? ''));
        if ($reviewNotes !== '') {
            if (function_exists('mb_substr')) {
                $reviewNotes = mb_substr($reviewNotes, 0, 2000);
            } else {
                $reviewNotes = substr($reviewNotes, 0, 2000);
            }
            try {
                $this->query(
                    "UPDATE purchase_orders SET review_notes = ? WHERE id = ?",
                    [$reviewNotes, $orderId]
                );
            } catch (\Throwable $e) {
                // Columna aún no migrada.
            }
            $this->logLifecycleEvent(
                $importId,
                $orderId,
                'invoice_observation',
                'Nota / observación',
                $reviewNotes,
                ['source' => 'invoice_confirm']
            );
        }

        // Confirmar apenas el stock entró: evita quedar atrapado en `processing`
        // si falla costo/contabilidad/caja después.
        $this->query(
            "UPDATE purchase_invoice_imports
             SET status = 'confirmed', purchase_order_id = ?, confirmed_at = NOW()
             WHERE id = ?",
            [$orderId, $importId]
        );

        TenantAuditService::safeLog(
            $this->db,
            'import',
            'compras',
            'Entrada de factura confirmada → OC #' . $orderId
                . (!empty($import['invoice_number']) ? (' / ' . $import['invoice_number']) : ''),
            'purchase_order',
            $orderId,
            [
                'import_id' => $importId,
                'invoice_number' => (string)($import['invoice_number'] ?? ''),
                'source' => (string)($import['source'] ?? ''),
            ]
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
            try {
                (new AccountingService($this->db))->enqueuePending(
                    'post_purchase_order',
                    'purchase_order',
                    $orderId,
                    $e->getMessage()
                );
            } catch (\Throwable $ignore) {
            }
        }

        $cashNote = '';
        try {
            $cashNote = $purchasing->syncCashForReceivedOrder($orderId);
        } catch (\Throwable $e) {
            error_log('Caja OC ' . $orderId . ' desde factura ' . $importId . ': ' . $e->getMessage());
        }

        try {
            $this->query(
                "UPDATE purchase_invoice_lifecycle_events
                 SET purchase_order_id = ?
                 WHERE import_id = ? AND (purchase_order_id IS NULL OR purchase_order_id = 0)",
                [$orderId, $importId]
            );
        } catch (\Throwable $e) {
            // ignore
        }
        $this->logLifecycleEvent(
            $importId,
            $orderId,
            'invoice_confirm',
            'Factura confirmada e ingresada',
            trim(
                'Se generó la OC, se actualizó inventario y contabilidad'
                . ($reviewNotes !== '' ? (' · Con observación') : '')
            )
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
            'source_order_id' => $keepOpen ? $linkedOrderId : 0,
            'oc_kept_open' => $keepOpen && is_array($sourceRemain) && empty($sourceRemain['closed']),
            'oc_remaining_qty' => $keepOpen && is_array($sourceRemain) ? (int)($sourceRemain['remaining_qty'] ?? 0) : 0,
            'oc_source_closed' => $keepOpen && is_array($sourceRemain) && !empty($sourceRemain['closed']),
            'oc_source_number' => $keepOpen && is_array($sourceRemain)
                ? (string)($sourceRemain['order_number'] ?? '')
                : '',
            'oc_excess_qty' => $keepOpen && is_array($sourceRemain)
                ? (int)array_sum(array_column($sourceRemain['excess'] ?? [], 'qty'))
                : 0,
        ];
    }

    /**
     * Deja el precio de compra del catálogo en el valor total de compra (con IVA si aplica).
     *
     * @param array<int,array<string,mixed>> $lineas
     */
    private function applyInvoiceCostsToCatalog(array $lineas): void
    {
        foreach ($lineas as $ln) {
            $pid = (int)($ln['product_id'] ?? 0);
            $cost = round((float)($ln['unit_cost'] ?? 0), 2);
            if ($pid <= 0) {
                continue;
            }
            $vatInCost = !empty($ln['vat_in_cost']);
            $catalogTax = isset($ln['catalog_tax_rate'])
                ? TaxService::normalizeRate((float)$ln['catalog_tax_rate'])
                : TaxService::normalizeRate((float)($ln['tax_rate'] ?? 0));
            // Precio de compra = valor total pagado (IVA incluido si F. Electrónica, tasa en línea, o prorrateo).
            $purchasePrice = $cost;
            if (!$vatInCost && array_key_exists('tax_amount', $ln) && $ln['tax_amount'] !== null && $ln['tax_amount'] !== '') {
                $qty = max(1, (int)($ln['quantity'] ?? 1));
                $purchasePrice = round($cost + (max(0, (float)$ln['tax_amount']) / $qty), 2);
            } elseif (!$vatInCost && $catalogTax > 0.009 && $cost > 0) {
                $purchasePrice = round($cost * (1 + $catalogTax / 100), 2);
            }
            if ($purchasePrice > 0) {
                $this->query(
                    "UPDATE products
                     SET purchase_price = ?, tax_rate = ?
                     WHERE id = ? AND COALESCE(product_type, 'product') = 'product'",
                    [$purchasePrice, $catalogTax, $pid]
                );
            } else {
                $this->query(
                    "UPDATE products
                     SET tax_rate = ?
                     WHERE id = ? AND COALESCE(product_type, 'product') = 'product'",
                    [$catalogTax, $pid]
                );
            }
        }
    }

    /**
     * Crea el producto de una línea sin coincidencia. El precio de venta se
     * estima con el margen configurado para que quede vendible de inmediato.
     * El IVA (tax_rate) tipifica el producto para ventas; el purchase_price es el valor total de compra.
     */
    private function createProductFromLine(array $item, bool $vatInCost = false): int
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
        $taxRate = TaxService::normalizeRate((float)($item['tax_rate'] ?? 0));
        $purchasePrice = $vatInCost || $taxRate <= 0.009
            ? $costo
            : round($costo * (1 + $taxRate / 100), 2);
        $precioVenta = !empty($item['sale_price']) && (float)$item['sale_price'] > 0
            ? round((float)$item['sale_price'], 2)
            : round($purchasePrice * (1 + $this->defaultMarginPct() / 100), 2);
        TaxService::ensureSchema($this->db);

        $this->query(
            "INSERT INTO products
                (code, barcode, name, product_type, purchase_price, sale_price, tax_rate, stock, min_stock, unit,
                 track_lots, expiry_alert_days, created_by, source_channel)
             VALUES (?, ?, ?, 'product', ?, ?, ?, 0, ?, 'UND', ?, 30, ?, 'manual')",
            [
                substr($codigo, 0, 255),
                $barcode !== '' ? $barcode : null,
                substr($nombre, 0, 255),
                $purchasePrice,
                $precioVenta,
                $taxRate,
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
        // Excel convierte EAN largos a notación científica (7,89988E+12 / 7.89988E+12).
        if (preg_match('/^\d+[,.]?\d*[eE][+\-]?\d+$/', $value) === 1) {
            $asFloat = (float)str_replace(',', '.', $value);
            if ($asFloat > 0 && $asFloat < 1e15) {
                $digits = sprintf('%.0f', $asFloat);
                if (preg_match('/^\d{8,14}$/', $digits) === 1) {
                    return $digits;
                }
            }
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
    private function matrixToInvoiceItems(array $matrix, string $separador = "\t"): array
    {
        if ($matrix === []) {
            return [];
        }

        $headerIdx = $this->findInvoiceHeaderRowIndex($matrix);
        $mapa = null;
        $start = 0;

        if ($headerIdx !== null) {
            $mapa = $this->mapCsvHeader($matrix[$headerIdx]);
            if ($mapa === [] && $this->looksLikeHeaderRow($matrix[$headerIdx])) {
                $mapa = $this->defaultPositionalMap(count($matrix[$headerIdx]));
            }
            $start = $headerIdx + 1;
        }

        // Sin encabezado claro: no usar la 1ª fila de metadatos; buscar 1ª fila de producto.
        if ($mapa === null || $mapa === []) {
            for ($i = 0; $i < count($matrix); $i++) {
                if ($this->isLikelyInvoiceMetaRow($matrix[$i])) {
                    continue;
                }
                $mapa = $this->inferCsvMap($matrix[$i]);
                if ($mapa !== [] && (isset($mapa['description']) || isset($mapa['barcode']) || isset($mapa['supplier_sku']))) {
                    $start = $i;
                    break;
                }
            }
        }

        if ($mapa === null || $mapa === []) {
            return [];
        }

        // Preferir EAN/SKU correctos en formatos "#, Ref, EAN, Description, Qty, Price…"
        $mapa = $this->refineInvoiceColumnMap($mapa, $matrix, $start);

        $filas = [];
        for ($i = $start; $i < count($matrix); $i++) {
            $campos = $matrix[$i];
            if ($this->isLikelyInvoiceMetaRow($campos) || $this->looksLikeHeaderRow($campos)) {
                continue;
            }
            $fila = $this->rowFromCsvFields($campos, $mapa, $separador);
            foreach ($this->expandBonusInvoiceRows($fila) as $expanded) {
                $filas[] = $expanded;
            }
        }

        // Primero rescatar montos mal mapeados; luego filtrar filas utilizables.
        $filas = $this->recoverZeroAmountRows($filas, $mapa);
        return array_values(array_filter(
            $filas,
            fn(array $fila): bool => $this->isUsableInvoiceProductRow($fila)
        ));
    }

    /**
     * Si hay columna BONIF / qty gratis, genera línea de bonificación (costo $0)
     * además de la línea paga, para el mismo SKU/EAN.
     *
     * @param array<string,mixed> $fila
     * @return list<array<string,mixed>>
     */
    private function expandBonusInvoiceRows(array $fila): array
    {
        $bonus = round(max(0, (float)($fila['bonus_qty'] ?? 0)), 3);
        unset($fila['bonus_qty']);
        $qty = (float)($fila['quantity'] ?? 0);
        $cost = (float)($fila['unit_cost'] ?? 0);
        $total = (float)($fila['line_total'] ?? 0);

        if ($bonus <= 0) {
            return [$fila];
        }
        // Solo bonificación en esa fila.
        if ($qty <= 0 && $cost <= 0 && $total <= 0) {
            $fila['quantity'] = $bonus;
            $fila['unit_cost'] = 0.0;
            $fila['line_total'] = 0.0;
            $fila['tax_rate'] = 0.0;
            $fila['raw_line'] = trim((string)($fila['raw_line'] ?? '') . ' [Bonificación]');
            return [$fila];
        }
        // Paga + bonificación: dos líneas del mismo producto.
        $gift = $fila;
        $gift['quantity'] = $bonus;
        $gift['unit_cost'] = 0.0;
        $gift['line_total'] = 0.0;
        $gift['tax_rate'] = 0.0;
        $gift['discount_pct'] = 0.0;
        $gift['raw_line'] = trim((string)($fila['raw_line'] ?? '') . ' [Bonificación]');
        return [$fila, $gift];
    }

    /**
     * Busca la fila de encabezado de productos (no la de Vendor/Date/Invoice).
     *
     * @param list<list<string>> $matrix
     */
    private function findInvoiceHeaderRowIndex(array $matrix): ?int
    {
        $bestIdx = null;
        $bestScore = 0;
        foreach ($matrix as $i => $campos) {
            if ($this->isLikelyInvoiceMetaRow($campos)) {
                continue;
            }
            $mapped = $this->mapCsvHeader($campos);
            $score = 0;
            if ($mapped !== []) {
                $score = 50 + count($mapped) * 10;
                if (isset($mapped['barcode'])) {
                    $score += 25;
                }
                if (isset($mapped['supplier_sku'])) {
                    $score += 15;
                }
                if (isset($mapped['quantity'], $mapped['unit_cost'])) {
                    $score += 20;
                }
                if (isset($mapped['line_total'])) {
                    $score += 10;
                }
            } elseif ($this->looksLikeHeaderRow($campos)) {
                $score = 20 + min(30, count($campos) * 3);
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIdx = $i;
            }
        }
        return $bestScore >= 40 ? $bestIdx : null;
    }

    /**
     * Filas de cabecera de factura (no productos): Vendor, Date, Invoice, totals…
     * Importante: NO usar substrings cortos (ej. "po") — rechazan "shampoo", "ampollas", etc.
     *
     * @param list<string> $campos
     */
    private function isLikelyInvoiceMetaRow(array $campos): bool
    {
        if ($campos === []) {
            return true;
        }

        $first = $this->slug((string)($campos[0] ?? ''));
        $metaExactFirst = [
            'vendor', 'proveedor', 'supplier', 'date', 'fecha', 'duedate', 'fechavencimiento',
            'invoice', 'factura', 'invoicenumber', 'numerofactura', 'subtotal', 'grandtotal',
            'taxsummary', 'resumeniva', 'ivaresumen', 'currency', 'moneda', 'nit',
            'customer', 'cliente', 'billto', 'shipto', 'ordencompra', 'purchaseorder',
            'paymentterms', 'formadepago', 'archivooriginal', 'originalfile',
            'iva', 'tax', 'total', 'po', 'oc', 'ponumber', 'ocnumber',
        ];
        if (count($campos) <= 4 && in_array($first, $metaExactFirst, true)) {
            return true;
        }
        // PO #123 / OC: 456 como etiqueta en 1ª celda
        if (count($campos) <= 4 && preg_match('/^(po|oc)(#|:|-)?\d*$/', $first) === 1) {
            return true;
        }

        $joined = $this->slug(implode(' ', array_slice($campos, 0, 3)));
        // Solo tokens largos o coincidencia exacta / prefijo de frase (nunca substring corto).
        foreach ([
            'archivooriginal', 'originalfile', 'purchaseorder', 'paymentterms', 'formadepago',
            'invoicenumber', 'numerofactura', 'fechavencimiento', 'taxsummary', 'resumeniva',
            'ivaresumen', 'grandtotal', 'ordencompra',
        ] as $token) {
            if ($joined === $token || str_starts_with($joined, $token)) {
                return true;
            }
        }
        if (in_array($joined, [
            'vendor', 'proveedor', 'supplier', 'date', 'fecha', 'invoice', 'factura',
            'subtotal', 'nit', 'billto', 'shipto', 'currency', 'moneda',
        ], true)) {
            return true;
        }

        return false;
    }

    /** Etiqueta meta exacta (descripción/SKU), sin substrings peligrosos. */
    private function isInvoiceMetaLabel(string $text): bool
    {
        $s = $this->slug(trim($text));
        if ($s === '') {
            return false;
        }
        return in_array($s, [
            'vendor', 'proveedor', 'supplier', 'date', 'fecha', 'invoice', 'factura',
            'subtotal', 'grandtotal', 'iva', 'tax', 'total', 'nit', 'currency', 'moneda',
            'description', 'descripcion', 'producto', 'nombre', 'ref', 'ean', 'qty', 'cantidad',
            'price', 'precio', 'unitcost', 'costo',
        ], true);
    }

    /**
     * Ajuste fino: si hay columna EAN y Ref, no confundir barcode con precio.
     *
     * @param array<string,int> $mapa
     * @param list<list<string>> $matrix
     * @return array<string,int>
     */
    private function refineInvoiceColumnMap(array $mapa, array $matrix, int $start): array
    {
        $sample = [];
        for ($i = $start; $i < count($matrix) && count($sample) < 8; $i++) {
            if ($this->isLikelyInvoiceMetaRow($matrix[$i]) || $this->looksLikeHeaderRow($matrix[$i])) {
                continue;
            }
            $sample[] = $matrix[$i];
        }
        if ($sample === []) {
            return $mapa;
        }

        // Si unit_cost apunta a una columna que en muestra parece EAN (8–14 dígitos),
        // y hay otra columna con precios típicos, corregir.
        if (isset($mapa['unit_cost'])) {
            $costIdx = (int)$mapa['unit_cost'];
            $eanish = 0;
            $priceish = 0;
            foreach ($sample as $row) {
                $val = trim((string)($row[$costIdx] ?? ''));
                if ($this->looksLikeBarcode($val)) {
                    $eanish++;
                }
                $n = $this->toNumber($val);
                if ($n > 0 && $n < 5000000 && !$this->looksLikeBarcode($val)) {
                    $priceish++;
                }
            }
            if ($eanish >= 2 && $priceish === 0) {
                // Buscar mejor columna de precio entre numéricas no usadas.
                $used = array_flip(array_values($mapa));
                $best = null;
                $bestHits = 0;
                $cols = count($sample[0]);
                for ($c = 0; $c < $cols; $c++) {
                    if (isset($used[$c])) {
                        continue;
                    }
                    $hits = 0;
                    foreach ($sample as $row) {
                        $val = trim((string)($row[$c] ?? ''));
                        $n = $this->toNumber($val);
                        if ($n > 10 && $n < 5000000 && !$this->looksLikeBarcode($val) && !str_contains($val, '%')) {
                            $hits++;
                        }
                    }
                    if ($hits > $bestHits) {
                        $bestHits = $hits;
                        $best = $c;
                    }
                }
                if ($best !== null && $bestHits >= 2) {
                    if (!isset($mapa['barcode'])) {
                        $mapa['barcode'] = $costIdx;
                    }
                    $mapa['unit_cost'] = $best;
                }
            }
        }

        return $mapa;
    }

    /**
     * @param array<string,mixed> $fila
     */
    private function isUsableInvoiceProductRow(array $fila): bool
    {
        $desc = trim((string)($fila['description'] ?? ''));
        $sku = trim((string)($fila['supplier_sku'] ?? ''));
        $ean = trim((string)($fila['barcode'] ?? ''));
        $qty = (float)($fila['quantity'] ?? 0);
        $cost = (float)($fila['unit_cost'] ?? 0);
        $total = (float)($fila['line_total'] ?? 0);

        if ($this->isInvoiceMetaLabel($desc) || $this->isInvoiceMetaLabel($sku)) {
            return false;
        }
        if ($desc === '' && $sku === '' && $ean === '') {
            return false;
        }
        // Encabezado colado como dato.
        if ($this->looksLikeHeaderRow([$desc, $sku, 'qty', 'price'])) {
            $slugDesc = $this->slug($desc);
            if (in_array($slugDesc, ['description', 'descripcion', 'producto', 'nombre', 'ref', 'ean'], true)) {
                return false;
            }
        }
        if ($qty <= 0 && $cost <= 0 && $total <= 0) {
            return false;
        }
        // Precio absurdo (= EAN mal mapeado).
        if ($cost >= 1.0e11 || ($this->looksLikeBarcode((string)(int)$cost) && $cost > 1.0e10)) {
            return false;
        }
        if ($ean !== '' && abs($cost - (float)$ean) < 0.01) {
            return false;
        }
        // Debe parecer producto: texto descriptivo o código + monto.
        $hasText = (bool)preg_match('/[A-Za-zÁÉÍÓÚáéíóúñÑ]/u', $desc . $sku);
        if (!$hasText && $ean === '') {
            return false;
        }
        if ($qty <= 0) {
            return false;
        }
        // Bonificación / regalo: qty > 0 con costo $0 es válida (mismo SKU/EAN, entra a inventario).
        if ($cost <= 0 && $total <= 0) {
            return $ean !== '' || $sku !== '' || $hasText;
        }
        return true;
    }

    /** @return list<list<string>> */
    private function readXlsxMatrix(string $absolute): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException(
                'Falta la extensión PHP Zip (ext-zip) para leer archivos .xlsx'
            );
        }
        $zip = new \ZipArchive();
        if ($zip->open($absolute) !== true) {
            throw new \RuntimeException('No se pudo abrir el archivo Excel (.xlsx)');
        }

        $shared = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($ssXml) && $ssXml !== '') {
            $ss = @simplexml_load_string($this->stripXmlNamespaces($ssXml));
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

        $sheetTargets = $this->xlsxSheetTargets($zip);
        if ($sheetTargets === []) {
            $sheetTargets = ['xl/worksheets/sheet1.xml'];
        }

        $bestMatrix = [];
        $bestScore = -1;
        foreach ($sheetTargets as $sheetPath) {
            $sheetXml = $zip->getFromName($sheetPath);
            if (!is_string($sheetXml) || $sheetXml === '') {
                // Algunos ZIP usan mayúsculas o paths relativos distintos.
                $alt = $this->xlsxFindSheetByBasename($zip, basename($sheetPath));
                if ($alt === null) {
                    continue;
                }
                $sheetXml = $alt;
            }
            $matrix = $this->xlsxSheetXmlToMatrix($sheetXml, $shared);
            if ($matrix === []) {
                continue;
            }
            $score = $this->scoreSpreadsheetMatrix($matrix);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatrix = $matrix;
            }
        }
        $zip->close();

        return $bestMatrix;
    }

    /**
     * @return list<string> rutas internas de hojas en el ZIP
     */
    private function xlsxSheetTargets(\ZipArchive $zip): array
    {
        $targets = [];
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (!is_string($wb) || $wb === '' || !is_string($rels) || $rels === '') {
            return $targets;
        }

        $relMap = [];
        if (preg_match_all(
            '/Relationship[^>]*Id="([^"]+)"[^>]*Target="([^"]+)"/i',
            $rels,
            $rm,
            PREG_SET_ORDER
        )) {
            foreach ($rm as $row) {
                $relMap[$row[1]] = $row[2];
            }
        }
        // También Target antes de Id.
        if (preg_match_all(
            '/Relationship[^>]*Target="([^"]+)"[^>]*Id="([^"]+)"/i',
            $rels,
            $rm2,
            PREG_SET_ORDER
        )) {
            foreach ($rm2 as $row) {
                $relMap[$row[2]] = $row[1];
            }
        }

        if (preg_match_all('/<sheet\b[^>]*>/i', $wb, $sheets)) {
            foreach ($sheets[0] as $sheetTag) {
                $rid = '';
                if (preg_match('/r:id="([^"]+)"/i', $sheetTag, $m)
                    || preg_match('/RelationshipId="([^"]+)"/i', $sheetTag, $m)) {
                    $rid = $m[1];
                }
                if ($rid === '' || empty($relMap[$rid])) {
                    continue;
                }
                $target = ltrim(str_replace('\\', '/', (string)$relMap[$rid]), '/');
                if (str_starts_with($target, '../')) {
                    $target = ltrim(substr($target, 3), '/');
                }
                if (!str_starts_with($target, 'xl/')) {
                    $target = 'xl/' . $target;
                }
                $targets[] = $target;
            }
        }

        return array_values(array_unique($targets));
    }

    private function xlsxFindSheetByBasename(\ZipArchive $zip, string $basename): ?string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
            if (strcasecmp(basename($name), $basename) === 0
                && str_contains(strtolower($name), 'worksheets/')) {
                $xml = $zip->getFromIndex($i);
                return is_string($xml) && $xml !== '' ? $xml : null;
            }
        }
        return null;
    }

    /**
     * @param list<string> $shared
     * @return list<list<string>>
     */
    private function xlsxSheetXmlToMatrix(string $sheetXml, array $shared): array
    {
        $sheet = @simplexml_load_string($this->stripXmlNamespaces($sheetXml));
        if ($sheet === false || !isset($sheet->sheetData)) {
            return [];
        }

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
                $type = strtolower((string)($c['t'] ?? ''));
                $value = '';
                if ($type === 's') {
                    $idx = (int)($c->v ?? -1);
                    $value = $shared[$idx] ?? '';
                } elseif ($type === 'inlineStr' || $type === 'str') {
                    if (isset($c->is->t)) {
                        $value = (string)$c->is->t;
                    } elseif (isset($c->is)) {
                        $parts = [];
                        foreach ($c->is->r as $r) {
                            $parts[] = (string)($r->t ?? '');
                        }
                        $value = $parts !== [] ? implode('', $parts) : (string)($c->v ?? '');
                    } else {
                        $value = (string)($c->v ?? '');
                    }
                } elseif ($type === 'b') {
                    $value = ((string)($c->v ?? '0')) === '1' ? '1' : '0';
                } else {
                    $value = (string)($c->v ?? '');
                    // Fechas seriales Excel: dejar el número; matrixToInvoiceItems no las usa como producto.
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
            // Ignorar filas totalmente vacías.
            if (implode('', $line) === '') {
                continue;
            }
            $matrix[] = $line;
        }

        return $matrix;
    }

    /** Quita namespaces XML para simplificar SimpleXML en OOXML. */
    private function stripXmlNamespaces(string $xml): string
    {
        $xml = preg_replace('/\sxmlns(:\w+)?="[^"]*"/i', '', $xml) ?? $xml;
        $xml = preg_replace('/(<\/?)[\w.]+:([\w.-]+)/', '$1$2', $xml) ?? $xml;
        return $xml;
    }

    /**
     * Puntúa una hoja: más alto = más probable tabla de productos.
     *
     * @param list<list<string>> $matrix
     */
    private function scoreSpreadsheetMatrix(array $matrix): int
    {
        if ($matrix === []) {
            return -1;
        }
        $items = $this->matrixToInvoiceItems($matrix);
        $score = count($items) * 100;
        $headerIdx = $this->findInvoiceHeaderRowIndex($matrix);
        if ($headerIdx !== null) {
            $score += 40;
        }
        $score += min(30, count($matrix));
        return $score;
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
            throw new \RuntimeException(
                'Falta la extensión PHP Zip (ext-zip) para leer archivos .ods'
            );
        }
        $zip = new \ZipArchive();
        if ($zip->open($absolute) !== true) {
            throw new \RuntimeException('No se pudo abrir el archivo ODS');
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
        $imp = null;
        try {
            $imp = $this->query(
                "SELECT COALESCE(document_kind, '') AS document_kind,
                        apply_product_vat, tax_override_amount
                 FROM purchase_invoice_imports WHERE id = ? LIMIT 1",
                [$importId]
            )->fetch();
        } catch (\Throwable $e) {
            $imp = [
                'document_kind' => (string)$this->query(
                    "SELECT COALESCE(document_kind, '') FROM purchase_invoice_imports WHERE id = ? LIMIT 1",
                    [$importId]
                )->fetchColumn(),
                'apply_product_vat' => 0,
                'tax_override_amount' => null,
            ];
        }

        $kind = (string)($imp['document_kind'] ?? '');
        $applyMode = self::parseApplyProductVat($imp['apply_product_vat'] ?? 0);
        $override = isset($imp['tax_override_amount']) && $imp['tax_override_amount'] !== null && $imp['tax_override_amount'] !== ''
            ? round(max(0, (float)$imp['tax_override_amount']), 2)
            : null;

        if (self::documentEmbedsVatInCost($kind)) {
            // Costo ya incluye IVA: el total de línea es bruto; se desglosa el IVA embebido.
            $this->query(
                "UPDATE purchase_invoice_imports i
                 SET i.subtotal = COALESCE((
                        SELECT SUM(ROUND(
                            it.line_total / NULLIF(1 + (it.tax_rate / 100), 0)
                        , 2))
                        FROM purchase_invoice_import_items it
                        WHERE it.import_id = i.id AND it.row_action <> 'skip'
                     ), 0),
                     i.tax_total = COALESCE((
                        SELECT SUM(ROUND(
                            it.line_total - (it.line_total / NULLIF(1 + (it.tax_rate / 100), 0))
                        , 2))
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
            $this->applyDiscountsToImportTotal($importId);
            return;
        }

        // IVA personalizado: subtotal de líneas; IVA = monto exacto; bruto = subtotal + IVA.
        if ($applyMode === 2 && $override !== null) {
            $this->query(
                "UPDATE purchase_invoice_imports i
                 SET i.subtotal = COALESCE((
                        SELECT SUM(ROUND(it.quantity * it.unit_cost * (1 - it.discount_pct / 100), 2))
                        FROM purchase_invoice_import_items it
                        WHERE it.import_id = i.id AND it.row_action <> 'skip'
                     ), 0),
                     i.tax_total = ?,
                     i.total = ROUND(
                        COALESCE((
                            SELECT SUM(ROUND(it.quantity * it.unit_cost * (1 - it.discount_pct / 100), 2))
                            FROM purchase_invoice_import_items it
                            WHERE it.import_id = i.id AND it.row_action <> 'skip'
                         ), 0) + ?, 2)
                 WHERE i.id = ?",
                [$override, $override, $importId]
            );
            $this->applyDiscountsToImportTotal($importId);
            return;
        }

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
        $this->applyDiscountsToImportTotal($importId);
    }

    /**
     * Ajusta purchase_invoice_imports.total restando desc. comercial y pronto pago
     * (misma lógica que la revisión / OC), para que el listado de pendientes coincida.
     */
    private function applyDiscountsToImportTotal(int $importId): void
    {
        try {
            $row = $this->query(
                "SELECT subtotal, tax_total,
                        COALESCE(early_payment_discount_pct, 0) AS early_payment_discount_pct,
                        COALESCE(commercial_discount_pct, 0) AS commercial_discount_pct,
                        COALESCE(commercial_discount_amount, 0) AS commercial_discount_amount
                 FROM purchase_invoice_imports WHERE id = ? LIMIT 1",
                [$importId]
            )->fetch();
        } catch (\Throwable $e) {
            return;
        }
        if (!$row) {
            return;
        }

        $disc = PurchasingService::resolvePurchaseDiscounts(
            (float)$row['subtotal'],
            (float)$row['tax_total'],
            (float)$row['early_payment_discount_pct'],
            (float)$row['commercial_discount_pct'],
            (float)$row['commercial_discount_amount']
        );

        $this->query(
            "UPDATE purchase_invoice_imports SET total = ? WHERE id = ?",
            [$disc['total'], $importId]
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

    /** Unidades de inventario: "7.000" = 7, no 7000. */
    private function toQuantity(string $valor): float
    {
        if (function_exists('\\SoftNova\\Core\\parse_quantity')) {
            return \SoftNova\Core\parse_quantity($valor);
        }
        return $this->toNumber($valor);
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
        // MySQL DATE/DATETIME o ISO: "2026-09-06" / "2026-09-06 14:30:00"
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T].*)?$/', $date, $m)) {
            if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
            }
            return null;
        }
        // Preferir d/m/Y (Colombia) antes que m/d/Y para evitar ambigüedad.
        foreach (['d/m/Y', 'd-m-Y', 'Y/m/d'] as $formato) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $formato, $date);
            if ($dt && $dt->format($formato) === $date) {
                return $dt->format('Y-m-d');
            }
        }
        return null;
    }
}
