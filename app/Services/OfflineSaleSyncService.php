<?php

namespace SoftNova\Services;

/**
 * Sincronización de ventas capturadas offline (Fase C).
 *
 * Cada venta se evalúa de forma independiente:
 * - ok / idempotent: quedó registrada (contabilidad en servidor post-commit)
 * - conflict: stock u otra regla de negocio; el cliente debe revisar
 * - retry: fallo transitorio (caja cerrada, DB, etc.)
 */
class OfflineSaleSyncService
{
    private \PDO $db;
    private SaleService $sales;
    private CashService $cash;
    private ReceivableService $receivables;
    private AccountingService $accounting;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        (new SalesDocumentService($db));
        $this->sales = new SaleService($db);
        $this->cash = new CashService($db);
        $this->receivables = new ReceivableService($db);
        $this->accounting = new AccountingService($db);
        try {
            (new ConsignmentService($db, $this->sales->stock()))->ensureSchema();
        } catch (\Throwable $e) {
            error_log('OfflineSaleSync consign schema: ' . $e->getMessage());
        }
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param list<array<string,mixed>> $sales
     * @return array{
     *   results: list<array<string,mixed>>,
     *   summary: array{ok:int,conflict:int,retry:int,idempotent:int}
     * }
     */
    public function syncBatch(array $sales, int $userId, array $settings = []): array
    {
        $results = [];
        $summary = ['ok' => 0, 'conflict' => 0, 'retry' => 0, 'idempotent' => 0];

        foreach ($sales as $index => $payload) {
            if (!is_array($payload)) {
                $results[] = [
                    'index' => $index,
                    'status' => 'conflict',
                    'message' => 'Payload de venta inválido',
                    'client_sale_uuid' => null,
                ];
                $summary['conflict']++;
                continue;
            }

            $result = $this->syncOne($payload, $userId, $settings);
            $result['index'] = $index;
            $results[] = $result;
            $status = (string)($result['status'] ?? 'retry');
            if (isset($summary[$status])) {
                $summary[$status]++;
            } else {
                $summary['retry']++;
            }
        }

        return ['results' => $results, 'summary' => $summary];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public function syncOne(array $payload, int $userId, array $settings = []): array
    {
        $payload = $this->normalizePayload($payload);
        $uuid = (string)($payload['client_sale_uuid'] ?? '');

        if ($uuid === '') {
            return [
                'status' => 'conflict',
                'message' => 'Falta client_sale_uuid; no se puede sincronizar de forma segura',
                'client_sale_uuid' => null,
            ];
        }

        $existing = $this->findByUuid($uuid);
        if ($existing) {
            return [
                'status' => 'idempotent',
                'message' => 'Venta ya registrada: ' . $existing['invoice_number'],
                'client_sale_uuid' => $uuid,
                'sale_id' => (int)$existing['id'],
                'invoice_number' => (string)$existing['invoice_number'],
            ];
        }

        // Offline solo admite contado; el crédito exige evaluación online.
        if (($payload['payment_type'] ?? 'full') === 'credit') {
            return [
                'status' => 'conflict',
                'message' => 'Las ventas a crédito no se sincronizan desde cola offline',
                'client_sale_uuid' => $uuid,
                'code' => 'credit_not_allowed',
            ];
        }

        if (($payload['document_type'] ?? 'invoice') === 'electronic') {
            return [
                'status' => 'conflict',
                'message' => 'La factura electrónica debe emitirse en línea',
                'client_sale_uuid' => $uuid,
                'code' => 'einvoice_online_only',
            ];
        }

        $openCash = $this->query(
            "SELECT id FROM cash_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1"
        )->fetch();
        $needsCashDrawer = $this->payloadNeedsCashDrawer($payload);
        if ($needsCashDrawer && !$openCash) {
            return [
                'status' => 'retry',
                'message' => 'No hay caja abierta; abra caja e intente de nuevo',
                'client_sale_uuid' => $uuid,
                'code' => 'cash_closed',
            ];
        }

        $preflight = $this->preflightStock($payload['items'] ?? []);
        if ($preflight['status'] !== 'ok') {
            return array_merge($preflight, ['client_sale_uuid' => $uuid]);
        }

        try {
            return $this->commitSale($payload, $userId, $settings, $preflight['items']);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains(strtolower($msg), 'stock insuficiente') || str_contains(strtolower($msg), 'stock')) {
                // Carrera entre preflight y commit.
                $again = $this->preflightStock($payload['items'] ?? []);
                return [
                    'status' => 'conflict',
                    'message' => $msg,
                    'client_sale_uuid' => $uuid,
                    'code' => 'stock',
                    'stock_conflicts' => $again['stock_conflicts'] ?? [],
                ];
            }
            if (str_contains(strtolower($msg), 'duplicate') && $uuid !== '') {
                $dup = $this->findByUuid($uuid);
                if ($dup) {
                    return [
                        'status' => 'idempotent',
                        'message' => 'Venta ya registrada: ' . $dup['invoice_number'],
                        'client_sale_uuid' => $uuid,
                        'sale_id' => (int)$dup['id'],
                        'invoice_number' => (string)$dup['invoice_number'],
                    ];
                }
            }
            return [
                'status' => 'retry',
                'message' => 'Error temporal: ' . $msg,
                'client_sale_uuid' => $uuid,
                'code' => 'exception',
            ];
        }
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public function normalizePayload(array $raw): array
    {
        $uuid = trim((string)($raw['client_sale_uuid'] ?? ''));
        if ($uuid !== '' && !preg_match('/^[A-Za-z0-9\-_]{8,64}$/', $uuid)) {
            $uuid = '';
        }

        $items = $raw['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        // Reconstruye items[0][product_id] planos (FormData / colas antiguas).
        if ($items === []) {
            foreach ($raw as $key => $value) {
                if (!is_string($key) || !preg_match('/^items\[(\d+)\]\[(\w+)\]$/', $key, $m)) {
                    continue;
                }
                $idx = (int)$m[1];
                if (!isset($items[$idx]) || !is_array($items[$idx])) {
                    $items[$idx] = [];
                }
                $items[$idx][$m[2]] = $value;
            }
        }

        $normalizedItems = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $pid = (int)($item['product_id'] ?? 0);
            $qty = (int)($item['quantity'] ?? 0);
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            $normalizedItems[] = [
                'product_id' => $pid,
                'quantity' => $qty,
                'unit_price' => isset($item['unit_price']) ? (float)$item['unit_price'] : null,
                'product_name' => (string)($item['product_name'] ?? ''),
                'stock_oversell_reason' => (string)($item['stock_oversell_reason'] ?? ($item['oversell_reason'] ?? '')),
                'stock_oversell_note' => (string)($item['stock_oversell_note'] ?? ($item['oversell_note'] ?? '')),
            ];
        }

        $paymentType = (string)($raw['payment_type'] ?? 'full');
        if (!in_array($paymentType, ['full', 'credit'], true)) {
            $paymentType = 'full';
        }

        $documentType = (string)($raw['document_type'] ?? 'invoice');
        if (!isset(SalesDocumentService::documentTypes()[$documentType])) {
            $documentType = 'invoice';
        }

        $saleDate = trim((string)($raw['sale_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate)) {
            $saleDate = date('Y-m-d');
        }
        $saleDate = \SoftNova\Core\BusinessTimezone::clampBusinessDate($saleDate, $this->db);

        return [
            'client_sale_uuid' => $uuid,
            'customer_id' => !empty($raw['customer_id']) ? (int)$raw['customer_id'] : null,
            'payment_method' => PaymentMethodCatalog::normalize($raw['payment_method'] ?? 'cash'),
            'payment_type' => $paymentType,
            'document_type' => $documentType,
            'payment_terms' => (string)($raw['payment_terms'] ?? ($paymentType === 'credit' ? 'net_30' : 'cash')),
            'sale_date' => $saleDate,
            'due_date' => trim((string)($raw['due_date'] ?? '')),
            'received_date' => trim((string)($raw['received_date'] ?? '')),
            'initial_payment' => \SoftNova\Core\parse_money($raw['initial_payment'] ?? 0),
            'discount_percent' => max(0, min(100, (float)($raw['discount_percent'] ?? 0))),
            'delivery_fee' => max(0, round(\SoftNova\Core\parse_money($raw['delivery_fee'] ?? 0), 2)),
            'delivery_company_fee' => max(0, round(\SoftNova\Core\parse_money($raw['delivery_company_fee'] ?? 0), 2)),
            'delivery_total' => max(0, round(\SoftNova\Core\parse_money($raw['delivery_total'] ?? 0), 2)),
            'sale_channel' => in_array(strtolower(trim((string)($raw['sale_channel'] ?? 'retail'))), ['retail', 'wholesale'], true)
                ? strtolower(trim((string)($raw['sale_channel'] ?? 'retail')))
                : 'retail',
            'notes' => trim((string)($raw['notes'] ?? '')),
            'items' => $normalizedItems,
            'payment_splits' => SalePaymentSplitter::extractFromArray($raw),
            'cash_received' => isset($raw['cash_received']) ? \SoftNova\Core\parse_money($raw['cash_received']) : null,
            'cash_change' => isset($raw['cash_change']) ? \SoftNova\Core\parse_money($raw['cash_change']) : null,
        ];
    }

    /** @return array{id:int,invoice_number:string}|null */
    private function findByUuid(string $uuid): ?array
    {
        try {
            $row = $this->query(
                "SELECT id, invoice_number FROM sales WHERE client_sale_uuid = ? LIMIT 1",
                [$uuid]
            )->fetch();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Alineado con ventas online: caja abierta solo si hay efectivo a registrar. */
    private function payloadNeedsCashDrawer(array $payload): bool
    {
        $method = PaymentMethodCatalog::normalize($payload['payment_method'] ?? 'cash');
        if ($method === 'cash') {
            return true;
        }
        $splits = is_array($payload['payment_splits'] ?? null) ? $payload['payment_splits'] : [];
        foreach ($splits as $split) {
            if (!is_array($split)) {
                continue;
            }
            $m = PaymentMethodCatalog::normalize($split['method'] ?? ($split['payment_method'] ?? ''));
            if ($m === 'cash' && (float)($split['amount'] ?? 0) > 0.009) {
                return true;
            }
        }
        // Mixto sin splits explícitos: asumir que puede incluir efectivo.
        return $method === 'mixed' && empty($splits);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function preflightStock(array $items): array
    {
        if (empty($items)) {
            return [
                'status' => 'conflict',
                'message' => 'Debe agregar al menos un producto',
                'code' => 'empty_items',
                'stock_conflicts' => [],
            ];
        }

        // Agrupa líneas del mismo producto para no aprobar 3+3 con stock 5.
        // Excepción: producto comodín (open item) — cada línea es independiente
        // (nombre/precio distintos); fusionarlas distorsiona totales.
        $grouped = [];
        $openItemLines = [];
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = (int)($item['quantity'] ?? 0);
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }
            $probe = $this->query(
                "SELECT id, code, COALESCE(product_type,'product') product_type FROM products WHERE id = ?",
                [$productId]
            )->fetch();
            if ($probe && OpenItemProduct::isOpenItem($probe)) {
                $row = $item;
                $row['quantity'] = $quantity;
                $openItemLines[] = $row;
                continue;
            }
            if (!isset($grouped[$productId])) {
                $grouped[$productId] = $item;
                $grouped[$productId]['quantity'] = $quantity;
            } else {
                $grouped[$productId]['quantity'] = (int)$grouped[$productId]['quantity'] + $quantity;
                // Conserva motivo si alguna línea lo trae (prioridad a la última no vacía).
                $r = (string)($item['stock_oversell_reason'] ?? '');
                if ($r !== '') {
                    $grouped[$productId]['stock_oversell_reason'] = $r;
                    $grouped[$productId]['stock_oversell_note'] = (string)($item['stock_oversell_note'] ?? '');
                }
            }
        }

        $valid = [];
        $conflicts = [];
        TaxService::ensureSchema($this->db);
        $defaultTax = TaxService::defaultRate($this->db);

        foreach (array_merge(array_values($grouped), $openItemLines) as $item) {
            $productId = (int)$item['product_id'];
            $quantity = (int)$item['quantity'];
            $product = $this->query(
                "SELECT id, name, code, sale_price, wholesale_price, stock, purchase_price, status,
                        COALESCE(product_type,'product') product_type,
                        COALESCE(ownership,'owned') ownership,
                        consignor_id, COALESCE(tax_rate, 0) AS tax_rate
                 FROM products WHERE id = ?",
                [$productId]
            )->fetch();

            if (!$product || ($product['status'] ?? '') !== 'active') {
                $conflicts[] = [
                    'product_id' => $productId,
                    'code' => (string)($item['product_name'] ?? ''),
                    'name' => 'Producto no disponible',
                    'requested' => $quantity,
                    'available' => 0,
                    'reason' => 'missing',
                ];
                continue;
            }

            $isService = ($product['product_type'] ?? 'product') === 'service';
            $available = (int)$product['stock'];
            $oversellReason = '';
            $oversellNote = '';
            if (!$isService && $available < $quantity) {
                $isConsignmentEarly = ($product['ownership'] ?? 'owned') === 'consignment';
                if ($isConsignmentEarly) {
                    $conflicts[] = [
                        'product_id' => $productId,
                        'code' => (string)($product['code'] ?? ''),
                        'name' => (string)$product['name'],
                        'requested' => $quantity,
                        'available' => $available,
                        'reason' => 'stock',
                        'message' => 'No se permite sobreventa de consignación',
                    ];
                    continue;
                }
                try {
                    $parsed = StockOversell::parse(
                        $item['stock_oversell_reason'] ?? null,
                        $item['stock_oversell_note'] ?? null
                    );
                } catch (\InvalidArgumentException $e) {
                    $conflicts[] = [
                        'product_id' => $productId,
                        'code' => (string)($product['code'] ?? ''),
                        'name' => (string)$product['name'],
                        'requested' => $quantity,
                        'available' => $available,
                        'reason' => 'stock',
                        'message' => $e->getMessage(),
                    ];
                    continue;
                }
                if ($parsed === null) {
                    $conflicts[] = [
                        'product_id' => $productId,
                        'code' => (string)($product['code'] ?? ''),
                        'name' => (string)$product['name'],
                        'requested' => $quantity,
                        'available' => $available,
                        'reason' => 'stock',
                    ];
                    continue;
                }
                $oversellReason = $parsed['reason'];
                $oversellNote = $parsed['note'];
            }

            $isOpenItem = OpenItemProduct::isOpenItem($product);
            $catalogPrice = round((float)($product['sale_price'] ?? 0), 2);
            $wholesalePrice = round((float)($product['wholesale_price'] ?? 0), 2);
            // En sync offline el canal puede venir en la línea; por defecto retail.
            $lineChannel = strtolower(trim((string)($item['sale_channel'] ?? 'retail')));
            if ($lineChannel === 'wholesale' && $wholesalePrice > 0.009) {
                $catalogPrice = $wholesalePrice;
            }
            $up = array_key_exists('unit_price', $item) && $item['unit_price'] !== null
                ? round((float)$item['unit_price'], 2)
                : $catalogPrice;
            if ($up < 0) {
                $up = 0.0;
            }
            $lineName = (string)$product['name'];
            $qtyDiscApplied = false;
            if ($isOpenItem) {
                try {
                    $lineName = OpenItemProduct::sanitizeLineName(
                        (string)($item['product_name'] ?? $item['name'] ?? '')
                    );
                    $up = OpenItemProduct::sanitizeLinePrice($up);
                } catch (\InvalidArgumentException $e) {
                    $conflicts[] = [
                        'product_id' => $productId,
                        'code' => (string)($product['code'] ?? ''),
                        'name' => (string)$product['name'],
                        'requested' => $quantity,
                        'available' => 0,
                        'reason' => 'open_item',
                        'message' => $e->getMessage(),
                    ];
                    continue;
                }
            } else {
                try {
                    $resolvedQty = (new CommissionService($this->db))->resolveQuantityDiscount($quantity);
                    if (!empty($resolvedQty['matched'])) {
                        $rate = (float)$resolvedQty['rate'];
                        $expected = round($catalogPrice * (1 - $rate / 100), 2);
                        if (abs($up - $expected) < 0.02 || abs($up - $catalogPrice) < 0.02 || $up > $expected + 0.009) {
                            $up = $expected;
                            $qtyDiscApplied = true;
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
            $isConsignment = ($product['ownership'] ?? 'owned') === 'consignment';
            $unitCost = $isOpenItem ? 0.0 : round((float)($product['purchase_price'] ?? 0), 2);
            if (!$isOpenItem && !ProductPriceGuard::saleExceedsCost($up, $unitCost)) {
                $conflicts[] = [
                    'product_id' => $productId,
                    'code' => (string)($product['code'] ?? ''),
                    'name' => (string)$product['name'],
                    'requested' => $quantity,
                    'available' => (int)($product['stock'] ?? 0),
                    'reason' => 'price_cost',
                    'message' => ProductPriceGuard::message((string)$product['name'], $up, $unitCost),
                ];
                continue;
            }
            $lineTaxRate = TaxService::resolveProductRate(
                $product,
                $defaultTax,
                $this->db,
                $isOpenItem,
                $isOpenItem ? ($item['tax_rate'] ?? null) : null
            );
            $valid[] = [
                'product_id' => $productId,
                'product_name' => $lineName,
                'quantity' => $quantity,
                'unit_price' => $up,
                'catalog_price' => $catalogPrice,
                'sync_catalog_price' => false,
                'unit_cost' => $unitCost,
                'subtotal' => $up * $quantity,
                'tax_rate' => $lineTaxRate,
                'product_type' => $isOpenItem ? 'service' : (string)($product['product_type'] ?? 'product'),
                'is_consignment' => $isConsignment,
                'consignor_id' => $isConsignment ? (int)($product['consignor_id'] ?? 0) : 0,
                'stock_oversell_reason' => $oversellReason,
                'stock_oversell_note' => $oversellNote,
            ];
        }

        if ($conflicts) {
            return [
                'status' => 'conflict',
                'message' => 'Stock insuficiente, precio inválido o productos no disponibles',
                'code' => 'stock',
                'stock_conflicts' => $conflicts,
            ];
        }

        if (empty($valid)) {
            return [
                'status' => 'conflict',
                'message' => 'No se encontraron productos válidos',
                'code' => 'empty_items',
                'stock_conflicts' => [],
            ];
        }

        return ['status' => 'ok', 'items' => $valid];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $settings
     * @param list<array<string,mixed>> $validItems
     * @return array<string,mixed>
     */
    private function commitSale(array $payload, int $userId, array $settings, array $validItems): array
    {
        $uuid = (string)$payload['client_sale_uuid'];
        $documentType = (string)$payload['document_type'];
        $paymentType = (string)$payload['payment_type'];
        $paymentMethod = (string)$payload['payment_method'];
        $paymentTerms = (string)$payload['payment_terms'];
        if (!isset(SalesDocumentService::paymentTerms()[$paymentTerms])) {
            $paymentTerms = $paymentType === 'credit' ? 'net_30' : 'cash';
        }

        $saleDate = (string)$payload['sale_date'];
        $saleDate = \SoftNova\Core\BusinessTimezone::clampBusinessDate($saleDate, $this->db);
        $dueDateInput = (string)$payload['due_date'];
        $receivedDate = (string)$payload['received_date'];
        $dueDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDateInput)
            ? $dueDateInput
            : SalesDocumentService::resolveDueDate($paymentTerms, $saleDate);
        if ($receivedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedDate)) {
            $receivedDate = '';
        }

        $prefix = SalesDocumentService::prefixFor($documentType);
        if ($documentType === 'invoice') {
            $prefix = (string)($settings['invoice_prefix'] ?? 'FAC-');
        }
        $invoiceNumber = $prefix . date('Ymd', strtotime($saleDate)) . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

        $subtotal = 0.0;
        foreach ($validItems as $vi) {
            $subtotal += (float)$vi['subtotal'];
        }
        $discountPercent = (float)$payload['discount_percent'];
        $discount = round($subtotal * ($discountPercent / 100), 2);
        TaxService::ensureSchema($this->db);
        $taxBreakdown = TaxService::applyDocumentDiscountAndTax($validItems, $discountPercent);
        $tax = (float)$taxBreakdown['tax'];
        $taxable = (float)$taxBreakdown['taxable'];
        $validItems = $taxBreakdown['lines'];
        TaxService::assertHeaderMatchesLines($tax, $validItems);
        $deliveryFee = max(0, round((float)($payload['delivery_fee'] ?? 0), 2));
        $deliveryCompanyFee = max(0, round((float)($payload['delivery_company_fee'] ?? 0), 2));
        $deliveryTotalPosted = max(0, round((float)($payload['delivery_total'] ?? 0), 2));
        // Solo la parte del cliente entra al total; empresa → gasto Transporte.
        $deliveryTotal = $deliveryTotalPosted;
        if ($deliveryTotal <= 0.009 && ($deliveryFee > 0.009 || $deliveryCompanyFee > 0.009)) {
            $deliveryTotal = round($deliveryFee + $deliveryCompanyFee, 2);
        }
        if ($deliveryTotal > 0.009) {
            if ($deliveryCompanyFee > $deliveryTotal) {
                $deliveryCompanyFee = $deliveryTotal;
            }
            if (abs($deliveryFee - $deliveryTotal) < 0.011 && $deliveryCompanyFee > 0.009) {
                $deliveryFee = round(max(0, $deliveryTotal - $deliveryCompanyFee), 2);
            } elseif ($deliveryFee <= 0.009 && $deliveryCompanyFee > 0.009) {
                $deliveryFee = round(max(0, $deliveryTotal - $deliveryCompanyFee), 2);
            } elseif ($deliveryFee > $deliveryTotal) {
                $deliveryFee = $deliveryTotal;
            }
            $sumParts = round($deliveryFee + $deliveryCompanyFee, 2);
            if ($deliveryCompanyFee <= 0.009 && $deliveryFee < $deliveryTotal) {
                $deliveryCompanyFee = round(max(0, $deliveryTotal - $deliveryFee), 2);
            } elseif (abs($sumParts - $deliveryTotal) > 0.02 && $deliveryCompanyFee > 0.009) {
                $deliveryFee = round(max(0, $deliveryTotal - $deliveryCompanyFee), 2);
            }
        }

        $creditSurcharge = 0.0;
        $creditSurchargeRate = 0.0;
        $threshold = 60;
        if ($paymentType === 'credit') {
            $termDays = SalesDocumentService::termDays($paymentTerms);
            try {
                $resolved = (new CommissionService($this->db))->resolveCreditSurcharge($termDays);
                if (!empty($resolved['matched'])) {
                    $threshold = (int)$resolved['min_days'];
                    $creditSurchargeRate = (float)$resolved['rate'];
                    $creditSurcharge = round($taxable * ($creditSurchargeRate / 100), 2);
                }
            } catch (\Throwable $e) {
            }
        }
        $total = $taxable + $tax + $creditSurcharge + $deliveryFee;

        $notes = (string)$payload['notes'];
        if ($discountPercent > 0) {
            $discountNote = 'Descuento ' . rtrim(rtrim(number_format($discountPercent, 2, '.', ''), '0'), '.') . '%';
            $notes = $notes !== '' ? ($notes . ' · ' . $discountNote) : $discountNote;
        }
        $saleChannel = (string)($payload['sale_channel'] ?? 'retail');
        if ($saleChannel === 'wholesale') {
            $notes = $notes !== '' ? ($notes . ' · Venta al por mayor') : 'Venta al por mayor';
        }
        if ($creditSurcharge > 0.009) {
            $sNote = 'Recargo crédito >' . (int)$threshold . 'd '
                . number_format($creditSurchargeRate, 2, '.', '') . '%: '
                . number_format($creditSurcharge, 2, '.', '');
            $notes = $notes !== '' ? ($notes . ' · ' . $sNote) : $sNote;
        }
        $deliveryClientShare = $deliveryFee;
        $deliveryTotalNote = isset($deliveryTotal) && $deliveryTotal > 0.009
            ? $deliveryTotal
            : round($deliveryFee + $deliveryCompanyFee, 2);
        if ($deliveryTotalNote > 0.009) {
            $deliveryNote = 'Domicilio: ' . number_format($deliveryTotalNote, 2, '.', '');
            if ($deliveryCompanyFee > 0.009) {
                $deliveryNote .= ' (cliente ' . number_format($deliveryClientShare, 2, '.', '')
                    . ' + empresa ' . number_format($deliveryCompanyFee, 2, '.', '') . ')';
            }
            $notes = $notes !== '' ? ($notes . ' · ' . $deliveryNote) : $deliveryNote;
        }
        if (!str_contains($notes, 'Offline sync')) {
            $notes = $notes !== '' ? ($notes . ' · Offline sync') : 'Offline sync';
        }

        $paymentStatus = $paymentType === 'credit' ? 'pending' : 'paid';
        $initialPayment = max(0, (float)$payload['initial_payment']);
        if ($paymentType === 'credit') {
            $initialPayment = min($initialPayment, $total);
            if ($initialPayment >= $total && $total > 0) {
                $paymentStatus = 'paid';
            }
        }

        $paidNow = $paymentType === 'credit' ? $initialPayment : $total;
        $payPlan = SalePaymentSplitter::resolve(
            $payload['payment_splits'] ?? [],
            $paymentMethod,
            $paidNow,
            $this->db
        );
        $paymentMethod = $payPlan['method'];
        if ($payPlan['label'] !== '' && str_contains($payPlan['label'], 'Mixto')) {
            $notes = $notes !== '' ? ($notes . ' · ' . $payPlan['label']) : $payPlan['label'];
        }

        $customerId = $payload['customer_id'];

        // DDL fuera de la transacción (MySQL hace COMMIT implícito con CREATE/ALTER).
        try {
            new SalesDocumentService($this->db);
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            SupplyChainSchema::ensure($this->db);
            $this->sales->stock()->lots();
        } catch (\Throwable $e) {
            // ignore
        }

        $cashReceivedSync = isset($payload['cash_received']) ? max(0, round((float)$payload['cash_received'], 2)) : null;
        $cashChangeSync = null;
        if ($cashReceivedSync !== null && $cashReceivedSync > 0.009) {
            $dueChange = (float)($payPlan['cash_total'] ?? 0);
            if ($dueChange <= 0.009) {
                $dueChange = $paymentType === 'credit' ? min((float)$initialPayment, $total) : $total;
            }
            $cashChangeSync = \SoftNova\Core\cash_change_amount(
                $cashReceivedSync,
                $dueChange,
                $payload['cash_change'] ?? null
            );
        }

        $this->db->beginTransaction();
        try {
            $this->query(
                "INSERT INTO sales
                    (invoice_number, document_type, customer_id, user_id, sale_date, due_date, received_date,
                     subtotal, tax, discount, delivery_fee, delivery_company_fee, cash_received, cash_change,
                     total, payment_method, payment_terms, payment_status, notes,
                     client_sale_uuid, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $invoiceNumber,
                    $documentType,
                    $customerId,
                    $userId,
                    $saleDate . ' ' . date('H:i:s'),
                    $dueDate,
                    $receivedDate !== '' ? $receivedDate : null,
                    $subtotal,
                    $tax,
                    $discount,
                    $deliveryFee,
                    $deliveryCompanyFee,
                    $cashReceivedSync,
                    $cashChangeSync,
                    $total,
                    $paymentMethod,
                    $paymentTerms,
                    $paymentStatus,
                    $notes,
                    $uuid,
                    $paymentStatus === 'paid' ? 'completed' : 'pending',
                ]
            );
            $saleId = (int)$this->db->lastInsertId();

            foreach ($validItems as $vi) {
                $isConsignment = !empty($vi['is_consignment']);
                try {
                    $this->query(
                        "INSERT INTO sale_items
                            (sale_id, product_id, product_name, quantity, unit_price, unit_cost,
                             is_consignment, consignor_id, subtotal, tax_rate, tax_amount,
                             discount_amount, taxable,
                             stock_oversell_reason, stock_oversell_note)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $saleId,
                            $vi['product_id'],
                            $vi['product_name'],
                            $vi['quantity'],
                            $vi['unit_price'],
                            $vi['unit_cost'] ?? 0,
                            $isConsignment ? 1 : 0,
                            $isConsignment && !empty($vi['consignor_id']) ? (int)$vi['consignor_id'] : null,
                            $vi['subtotal'],
                            round((float)($vi['tax_rate'] ?? 0), 2),
                            round((float)($vi['tax_amount'] ?? 0), 2),
                            round((float)($vi['discount_amount'] ?? 0), 2),
                            round((float)($vi['taxable'] ?? $vi['subtotal'] ?? 0), 2),
                            !empty($vi['stock_oversell_reason']) ? $vi['stock_oversell_reason'] : null,
                            !empty($vi['stock_oversell_note']) ? $vi['stock_oversell_note'] : null,
                        ]
                    );
                } catch (\Throwable $e) {
                    try {
                        $this->query(
                            "INSERT INTO sale_items
                                (sale_id, product_id, product_name, quantity, unit_price, unit_cost,
                                 is_consignment, consignor_id, subtotal, tax_rate, tax_amount,
                                 stock_oversell_reason, stock_oversell_note)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [
                                $saleId,
                                $vi['product_id'],
                                $vi['product_name'],
                                $vi['quantity'],
                                $vi['unit_price'],
                                $vi['unit_cost'] ?? 0,
                                $isConsignment ? 1 : 0,
                                $isConsignment && !empty($vi['consignor_id']) ? (int)$vi['consignor_id'] : null,
                                $vi['subtotal'],
                                round((float)($vi['tax_rate'] ?? 0), 2),
                                round((float)($vi['tax_amount'] ?? 0), 2),
                                !empty($vi['stock_oversell_reason']) ? $vi['stock_oversell_reason'] : null,
                                !empty($vi['stock_oversell_note']) ? $vi['stock_oversell_note'] : null,
                            ]
                        );
                    } catch (\Throwable $e2) {
                    try {
                        $this->query(
                            "INSERT INTO sale_items
                                (sale_id, product_id, product_name, quantity, unit_price, unit_cost, subtotal,
                                 stock_oversell_reason, stock_oversell_note)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [
                                $saleId,
                                $vi['product_id'],
                                $vi['product_name'],
                                $vi['quantity'],
                                $vi['unit_price'],
                                $vi['unit_cost'] ?? 0,
                                $vi['subtotal'],
                                !empty($vi['stock_oversell_reason']) ? $vi['stock_oversell_reason'] : null,
                                !empty($vi['stock_oversell_note']) ? $vi['stock_oversell_note'] : null,
                            ]
                        );
                    } catch (\Throwable $e3) {
                        $this->query(
                            "INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, unit_cost, subtotal)
                             VALUES (?, ?, ?, ?, ?, ?, ?)",
                            [$saleId, $vi['product_id'], $vi['product_name'], $vi['quantity'], $vi['unit_price'], $vi['unit_cost'] ?? 0, $vi['subtotal']]
                        );
                    }
                    }
                }
                $saleItemId = (int)$this->db->lastInsertId();
                if (!empty($vi['sync_catalog_price'])) {
                    $this->sales->stock()->recordSalePriceChange(
                        (int)$vi['product_id'],
                        (float)$vi['catalog_price'],
                        (float)$vi['unit_price'],
                        $saleId,
                        $invoiceNumber
                    );
                }
                $oversell = null;
                if (!empty($vi['stock_oversell_reason'])) {
                    $oversell = [
                        'reason' => (string)$vi['stock_oversell_reason'],
                        'note' => (string)($vi['stock_oversell_note'] ?? ''),
                        'sale_item_id' => $saleItemId,
                    ];
                }
                $this->sales->stock()->decrease(
                    $vi['product_id'],
                    $vi['quantity'],
                    'sale',
                    $saleId,
                    'Venta: ' . $invoiceNumber,
                    (float)($vi['unit_cost'] ?? 0),
                    $oversell
                );
                if ($isConsignment) {
                    try {
                        (new ConsignmentService($this->db, $this->sales->stock()))->registerSaleDebt(
                            $saleId,
                            $saleItemId,
                            (int)$vi['product_id'],
                            (int)$vi['quantity'],
                            (float)($vi['unit_cost'] ?? 0),
                            (int)($vi['consignor_id'] ?? 0) ?: null
                        );
                    } catch (\Throwable $e) {
                        error_log('consignment sale debt: ' . $e->getMessage());
                        throw $e;
                    }
                }
            }

            $cashAmount = 0.0;
            if ($paidNow > 0.009 && !empty($payPlan['splits'])) {
                foreach ($payPlan['splits'] as $split) {
                    $this->query(
                        "INSERT INTO sale_payments (sale_id, amount, payment_method, notes, user_id)
                         VALUES (?, ?, ?, ?, ?)",
                        [
                            $saleId,
                            $split['amount'],
                            $split['method'],
                            $paymentType === 'credit'
                                ? ('Pago inicial - credito · ' . PaymentMethodCatalog::label($split['method'], $this->db))
                                : ('Pago · ' . PaymentMethodCatalog::label($split['method'], $this->db)),
                            $userId,
                        ]
                    );
                }
                $cashAmount = (float)$payPlan['cash_total'];
                if ($paymentType === 'credit' && $paidNow < $total) {
                    $this->query("UPDATE sales SET payment_status = 'partial' WHERE id = ?", [$saleId]);
                }
            }

            if ($this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        // Contabilidad y efectos secundarios SIEMPRE en servidor, después del commit.
        $accountingPosted = false;
        try {
            $this->accounting->postSaleCascade($saleId);
            $accountingPosted = true;
        } catch (\Throwable $e) {
            error_log('Contabilidad venta offline ' . $saleId . ': ' . $e->getMessage());
            $this->accounting->enqueuePending(
                'post_sale',
                'sale',
                $saleId,
                $e->getMessage()
            );
        }

        try {
            (new CommissionService($this->db))->processSale($saleId);
        } catch (\Throwable $e) {
            error_log('Comisiones venta offline ' . $saleId . ': ' . $e->getMessage());
        }

        if ($cashAmount > 0.009) {
            try {
                $label = ($paymentType === 'credit' && $paymentStatus !== 'paid')
                    ? 'Abono inicial: ' . $invoiceNumber
                    : 'Venta: ' . $invoiceNumber;
                if ($paymentMethod === 'mixed') {
                    $label .= ' (efectivo mixto)';
                }
                if (!$this->cash->registerIncome($cashAmount, $label, 'sale', $saleId)) {
                    error_log('Caja: venta offline ' . $saleId . ' sin movimiento de ingreso');
                }
            } catch (\Throwable $e) {
                error_log('Caja venta offline ' . $saleId . ': ' . $e->getMessage());
            }
        }

        if ($deliveryCompanyFee > 0.009) {
            try {
                (new DeliveryCompanyExpenseService($this->db))->postForSale(
                    $saleId,
                    $invoiceNumber,
                    $deliveryCompanyFee,
                    $userId
                );
            } catch (\Throwable $e) {
                error_log('Gasto transporte domicilio offline ' . $saleId . ': ' . $e->getMessage());
            }
        }

        if ($paymentType === 'credit') {
            $paidForRx = min($initialPayment, $total);
            $finalStatus = $this->query("SELECT payment_status FROM sales WHERE id = ?", [$saleId])->fetch()['payment_status'] ?? $paymentStatus;
            if ($finalStatus !== 'paid') {
                $this->receivables->upsertFromSale(
                    $saleId,
                    $customerId,
                    $invoiceNumber,
                    $total,
                    $paidForRx,
                    $notes ?: null,
                    $dueDate
                );
            }
        }

        try {
            (new TenantAuditService($this->db))->log(
                'create',
                'ventas',
                'Venta offline sincronizada: ' . $invoiceNumber . ' total=' . $total,
                'sale',
                $saleId
            );
        } catch (\Throwable $e) {
            error_log('audit offline sale: ' . $e->getMessage());
        }

        return [
            'status' => 'ok',
            'message' => 'Venta sincronizada: ' . $invoiceNumber,
            'client_sale_uuid' => $uuid,
            'sale_id' => $saleId,
            'invoice_number' => $invoiceNumber,
            'total' => $total,
            'accounting_posted' => $accountingPosted,
        ];
    }
}
