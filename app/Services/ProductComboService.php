<?php

namespace SoftNova\Services;

/**
 * Combos / kits de venta (estilo POS ERP).
 *
 * Plantillas reutilizables: varios productos + precio de combo (prorrateado
 * al vender). Se guardan como sugerencia (fechas especiales, tinte+agua, etc.).
 * En la venta se expanden a líneas reales → stock e IVA por componente.
 */
class ProductComboService
{
    private \PDO $db;
    private static bool $schemaReady = false;

    /** @var array<string,string> code => label */
    public const REASONS = [
        'promocion' => 'Promoción',
        'fecha_especial' => 'Fecha especial',
        'navidad' => 'Navidad',
        'san_valentin' => 'San Valentín',
        'dia_madre' => 'Día de la Madre',
        'dia_padre' => 'Día del Padre',
        'halloween' => 'Halloween',
        'ano_nuevo' => 'Año Nuevo',
        'black_friday' => 'Black Friday',
        'servicio' => 'Servicio (ej. tinte + agua)',
        'paquete' => 'Paquete / kit',
        'liquidacion' => 'Liquidación',
        'otro' => 'Otro',
    ];

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        self::$schemaReady = true;

        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS product_combos (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(160) NOT NULL,
                    code VARCHAR(40) NULL,
                    sale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                    reason VARCHAR(40) NULL,
                    reason_note VARCHAR(180) NULL,
                    valid_from DATE NULL,
                    valid_until DATE NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    times_used INT UNSIGNED NOT NULL DEFAULT 0,
                    last_used_at DATETIME NULL,
                    created_by INT UNSIGNED NULL,
                    notes TEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_pc_active (is_active, name),
                    INDEX idx_pc_valid (valid_from, valid_until),
                    INDEX idx_pc_used (times_used)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable $e) {
            // ignore
        }

        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS product_combo_items (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    combo_id INT UNSIGNED NOT NULL,
                    product_id INT UNSIGNED NOT NULL,
                    quantity INT UNSIGNED NOT NULL DEFAULT 1,
                    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    INDEX idx_pci_combo (combo_id),
                    INDEX idx_pci_product (product_id),
                    UNIQUE KEY uq_pci_combo_product (combo_id, product_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable $e) {
            // ignore
        }

        // Columnas en sale_items para factura (1 renglón) + stock por componente.
        $saleCols = [
            'is_combo' => "ALTER TABLE sale_items ADD COLUMN is_combo TINYINT(1) NOT NULL DEFAULT 0",
            'document_hidden' => "ALTER TABLE sale_items ADD COLUMN document_hidden TINYINT(1) NOT NULL DEFAULT 0",
            'combo_id' => "ALTER TABLE sale_items ADD COLUMN combo_id INT UNSIGNED NULL",
        ];
        foreach ($saleCols as $name => $sql) {
            try {
                $exists = $this->db->query("SHOW COLUMNS FROM sale_items LIKE " . $this->db->quote($name))->fetch();
                if (!$exists) {
                    $this->db->exec($sql);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    public static function reasonLabel(?string $code, ?string $note = null): string
    {
        $code = strtolower(trim((string)$code));
        if ($code === '' || $code === 'otro') {
            $note = trim((string)$note);
            return $note !== '' ? $note : (self::REASONS['otro'] ?? 'Otro');
        }
        return self::REASONS[$code] ?? $code;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCombos(bool $activeOnly = true, int $limit = 80): array
    {
        $this->ensureSchema();
        $limit = max(1, min(200, $limit));
        $where = $activeOnly ? 'WHERE c.is_active = 1' : '';
        try {
            $rows = $this->db->query(
                "SELECT c.*,
                        (SELECT COUNT(*) FROM product_combo_items i WHERE i.combo_id = c.id) AS item_count
                 FROM product_combos c
                 {$where}
                 ORDER BY c.times_used DESC, c.updated_at DESC, c.id DESC
                 LIMIT {$limit}"
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        $today = date('Y-m-d');
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrateCombo($row, false, $today);
        }
        return $out;
    }

    /**
     * Combos vigentes (activos y dentro de ventana de fechas si aplica).
     *
     * @return list<array<string,mixed>>
     */
    public function listAvailable(int $limit = 40): array
    {
        $all = $this->listCombos(true, $limit);
        return array_values(array_filter($all, static fn(array $c): bool => !empty($c['is_available'])));
    }

    public function getCombo(int $id, bool $withItems = true): ?array
    {
        $this->ensureSchema();
        if ($id <= 0) {
            return null;
        }
        try {
            $st = $this->db->prepare(
                "SELECT c.*,
                        (SELECT COUNT(*) FROM product_combo_items i WHERE i.combo_id = c.id) AS item_count
                 FROM product_combos c WHERE c.id = ? LIMIT 1"
            );
            $st->execute([$id]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$row) {
            return null;
        }
        return $this->hydrateCombo($row, $withItems);
    }

    /**
     * @param array{name:string,sale_price:float|int|string,reason?:string,reason_note?:string,valid_from?:?string,valid_until?:?string,notes?:string,items:list<array{product_id:int,quantity?:int}>} $data
     * @return array{ok:bool,message:string,combo?:array<string,mixed>}
     */
    public function saveCombo(array $data, ?int $userId = null, ?int $comboId = null): array
    {
        $this->ensureSchema();

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) < 2) {
            return ['ok' => false, 'message' => 'Indique un nombre para el combo (mín. 2 caracteres)'];
        }
        if (mb_strlen($name) > 160) {
            $name = mb_substr($name, 0, 160);
        }

        $salePrice = round((float)($data['sale_price'] ?? 0), 2);
        if ($salePrice < 0) {
            $salePrice = 0.0;
        }

        $reason = strtolower(trim((string)($data['reason'] ?? 'promocion')));
        if (!isset(self::REASONS[$reason])) {
            $reason = 'otro';
        }
        $reasonNote = trim((string)($data['reason_note'] ?? ''));
        if (mb_strlen($reasonNote) > 180) {
            $reasonNote = mb_substr($reasonNote, 0, 180);
        }

        $validFrom = $this->normalizeDate($data['valid_from'] ?? null);
        $validUntil = $this->normalizeDate($data['valid_until'] ?? null);
        if ($validFrom && $validUntil && $validUntil < $validFrom) {
            return ['ok' => false, 'message' => 'La fecha hasta no puede ser anterior a la fecha desde'];
        }

        $notes = trim((string)($data['notes'] ?? ''));
        if (mb_strlen($notes) > 2000) {
            $notes = mb_substr($notes, 0, 2000);
        }

        $rawItems = $data['items'] ?? [];
        if (!is_array($rawItems) || count($rawItems) < 2) {
            return ['ok' => false, 'message' => 'Un combo necesita al menos 2 productos'];
        }

        $byProduct = [];
        foreach ($rawItems as $it) {
            if (!is_array($it)) {
                continue;
            }
            $pid = (int)($it['product_id'] ?? 0);
            $qty = (int)($it['quantity'] ?? 1);
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            if ($qty > 999) {
                $qty = 999;
            }
            if (!isset($byProduct[$pid])) {
                $byProduct[$pid] = 0;
            }
            $byProduct[$pid] += $qty;
        }
        if (count($byProduct) < 2) {
            return ['ok' => false, 'message' => 'Un combo necesita al menos 2 productos distintos'];
        }

        // Validar productos activos y costos vs precio de combo.
        $components = [];
        $retailSum = 0.0;
        $costSum = 0.0;
        $sort = 0;
        foreach ($byProduct as $pid => $qty) {
            try {
                $st = $this->db->prepare(
                    "SELECT id, name, code, sale_price, COALESCE(purchase_price,0) AS purchase_price,
                            COALESCE(product_type,'product') AS product_type, stock, status
                     FROM products WHERE id = ? LIMIT 1"
                );
                $st->execute([$pid]);
                $p = $st->fetch(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                $p = false;
            }
            if (!$p || ($p['status'] ?? '') !== 'active') {
                return ['ok' => false, 'message' => 'Producto #' . $pid . ' no disponible'];
            }
            if (OpenItemProduct::isOpenItem($p)) {
                return ['ok' => false, 'message' => 'El comodín no puede ir en un combo guardado'];
            }
            $unit = round((float)($p['sale_price'] ?? 0), 2);
            $cost = round((float)($p['purchase_price'] ?? 0), 2);
            $retailSum += $unit * $qty;
            $costSum += $cost * $qty;
            $components[] = [
                'product_id' => (int)$p['id'],
                'product_name' => (string)$p['name'],
                'code' => (string)($p['code'] ?? ''),
                'quantity' => $qty,
                'unit_price' => $unit,
                'purchase_price' => $cost,
                'stock' => (int)($p['stock'] ?? 0),
                'product_type' => (string)($p['product_type'] ?? 'product'),
                'sort_order' => $sort++,
            ];
        }

        if ($salePrice > 0.009 && $costSum > 0.009 && $salePrice + 0.009 < $costSum) {
            return [
                'ok' => false,
                'message' => 'El precio del combo (' . number_format($salePrice, 2, ',', '.')
                    . ') es menor al costo de los componentes (' . number_format($costSum, 2, ',', '.') . ')',
            ];
        }

        $code = $this->suggestCode($name, $comboId);

        try {
            $this->db->beginTransaction();

            if ($comboId && $comboId > 0) {
                $chk = $this->db->prepare('SELECT id FROM product_combos WHERE id = ? LIMIT 1');
                $chk->execute([$comboId]);
                if (!$chk->fetch()) {
                    $this->db->rollBack();
                    return ['ok' => false, 'message' => 'Combo no encontrado'];
                }
                $up = $this->db->prepare(
                    "UPDATE product_combos
                     SET name = ?, code = ?, sale_price = ?, reason = ?, reason_note = ?,
                         valid_from = ?, valid_until = ?, notes = ?, is_active = 1
                     WHERE id = ?"
                );
                $up->execute([
                    $name, $code, $salePrice, $reason, $reasonNote !== '' ? $reasonNote : null,
                    $validFrom, $validUntil, $notes !== '' ? $notes : null, $comboId,
                ]);
                $this->db->prepare('DELETE FROM product_combo_items WHERE combo_id = ?')->execute([$comboId]);
                $id = $comboId;
            } else {
                $ins = $this->db->prepare(
                    "INSERT INTO product_combos
                        (name, code, sale_price, reason, reason_note, valid_from, valid_until,
                         is_active, created_by, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)"
                );
                $ins->execute([
                    $name, $code, $salePrice, $reason, $reasonNote !== '' ? $reasonNote : null,
                    $validFrom, $validUntil, $userId ?: null, $notes !== '' ? $notes : null,
                ]);
                $id = (int)$this->db->lastInsertId();
            }

            $insItem = $this->db->prepare(
                "INSERT INTO product_combo_items (combo_id, product_id, quantity, sort_order)
                 VALUES (?, ?, ?, ?)"
            );
            foreach ($components as $c) {
                $insItem->execute([$id, $c['product_id'], $c['quantity'], $c['sort_order']]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('ProductComboService::saveCombo: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'No se pudo guardar el combo'];
        }

        $combo = $this->getCombo($id, true);
        return [
            'ok' => true,
            'message' => $comboId ? 'Combo actualizado' : 'Combo guardado como sugerencia',
            'combo' => $combo,
            'retail_sum' => round($retailSum, 2),
            'cost_sum' => round($costSum, 2),
        ];
    }

    public function deactivate(int $id): bool
    {
        $this->ensureSchema();
        if ($id <= 0) {
            return false;
        }
        try {
            $st = $this->db->prepare('UPDATE product_combos SET is_active = 0 WHERE id = ?');
            $st->execute([$id]);
            return $st->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function recordUsage(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        try {
            $this->db->prepare(
                'UPDATE product_combos SET times_used = times_used + 1, last_used_at = NOW() WHERE id = ?'
            )->execute([$id]);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * Expande un combo a líneas de carrito con precios prorrateados.
     *
     * @return array{ok:bool,message:string,lines?:list<array<string,mixed>>,combo?:array<string,mixed>}
     */
    public function expandForSale(int $comboId, int $packs = 1, ?float $overridePrice = null): array
    {
        $combo = $this->getCombo($comboId, true);
        if (!$combo) {
            return ['ok' => false, 'message' => 'Combo no encontrado'];
        }
        if (empty($combo['is_active'])) {
            return ['ok' => false, 'message' => 'El combo está inactivo'];
        }
        if (empty($combo['is_available'])) {
            return ['ok' => false, 'message' => 'El combo no está vigente en esta fecha'];
        }
        $packs = max(1, min(99, $packs));
        $items = $combo['items'] ?? [];
        if (count($items) < 2) {
            return ['ok' => false, 'message' => 'El combo no tiene componentes válidos'];
        }

        $comboPrice = $overridePrice !== null
            ? round(max(0, $overridePrice), 2)
            : round((float)($combo['sale_price'] ?? 0), 2);

        $priced = $this->allocatePrices($items, $comboPrice);
        if (!$priced['ok']) {
            return $priced;
        }

        $lines = [];
        foreach ($priced['lines'] as $line) {
            $qty = (int)$line['quantity'] * $packs;
            $unit = (float)$line['unit_price'];
            $lines[] = array_merge($line, [
                'quantity' => $qty,
                'subtotal' => round($unit * $qty, 2),
                'combo_id' => (int)$combo['id'],
                'combo_name' => (string)$combo['name'],
                'price_manual' => true,
            ]);
        }

        return [
            'ok' => true,
            'message' => 'OK',
            'combo' => $combo,
            'lines' => $lines,
            'combo_price' => round($comboPrice * $packs, 2),
            'retail_sum' => round((float)$priced['retail_sum'] * $packs, 2),
        ];
    }

    /**
     * Prorratea el precio del combo entre componentes según su precio de lista.
     *
     * @param list<array<string,mixed>> $items
     * @return array{ok:bool,message?:string,lines?:list<array<string,mixed>>,retail_sum?:float}
     */
    public function allocatePrices(array $items, float $comboPrice): array
    {
        if (count($items) < 1) {
            return ['ok' => false, 'message' => 'Sin componentes'];
        }

        $weights = [];
        $retailSum = 0.0;
        foreach ($items as $i => $it) {
            $qty = max(1, (int)($it['quantity'] ?? 1));
            $unit = round((float)($it['unit_price'] ?? $it['sale_price'] ?? $it['retail_price'] ?? 0), 2);
            if ($unit < 0) {
                $unit = 0.0;
            }
            $w = $unit * $qty;
            // Si todos valen 0, peso uniforme.
            $weights[$i] = $w;
            $retailSum += $w;
        }

        if ($retailSum < 0.009) {
            $n = count($items);
            foreach ($items as $i => $_) {
                $weights[$i] = 1.0;
            }
            $retailSum = (float)$n;
        }

        $comboPrice = round(max(0, $comboPrice), 2);
        $n = count($items);
        $lineTotals = [];
        $assigned = 0.0;

        for ($i = 0; $i < $n; $i++) {
            if ($i === $n - 1) {
                $lineTotals[$i] = round($comboPrice - $assigned, 2);
            } else {
                $share = $weights[$i] / $retailSum;
                $lineTotals[$i] = round($comboPrice * $share, 2);
                $assigned += $lineTotals[$i];
            }
            if ($lineTotals[$i] < 0) {
                $lineTotals[$i] = 0.0;
            }
        }

        $lines = [];
        foreach ($items as $i => $it) {
            $qty = max(1, (int)($it['quantity'] ?? 1));
            $lineTotal = $lineTotals[$i];
            $unitPrice = round($lineTotal / $qty, 2);
            $subtotal = round($unitPrice * $qty, 2);

            $cost = round((float)($it['purchase_price'] ?? 0), 2);
            if ($cost > 0.009 && !ProductPriceGuard::saleExceedsCost($unitPrice, $cost)) {
                return [
                    'ok' => false,
                    'message' => ProductPriceGuard::message(
                        (string)($it['product_name'] ?? $it['name'] ?? 'Producto'),
                        $unitPrice,
                        $cost
                    ),
                ];
            }

            $lines[] = [
                'product_id' => (int)($it['product_id'] ?? $it['id'] ?? 0),
                'product_name' => (string)($it['product_name'] ?? $it['name'] ?? ''),
                'code' => (string)($it['code'] ?? ''),
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'retail_price' => round((float)($it['unit_price'] ?? $it['sale_price'] ?? $it['retail_price'] ?? 0), 2),
                'wholesale_price' => round((float)($it['wholesale_price'] ?? 0), 2),
                'purchase_price' => $cost,
                'subtotal' => $subtotal,
                'tax_rate' => $it['tax_rate'] ?? 0,
                'stock' => (int)($it['stock'] ?? 0),
                'product_type' => (string)($it['product_type'] ?? 'product'),
                'is_consignment' => !empty($it['is_consignment']) || (($it['ownership'] ?? '') === 'consignment'),
                'ownership' => (string)($it['ownership'] ?? 'owned'),
                'consignor_id' => (int)($it['consignor_id'] ?? 0),
            ];
        }

        // Ajuste de centavos: Σ subtotales = precio combo.
        $sumLines = 0.0;
        foreach ($lines as $l) {
            $sumLines += (float)$l['subtotal'];
        }
        $diff = round($comboPrice - $sumLines, 2);
        if (abs($diff) >= 0.01 && $lines !== []) {
            $last = count($lines) - 1;
            $qty = max(1, (int)$lines[$last]['quantity']);
            $newSub = round((float)$lines[$last]['subtotal'] + $diff, 2);
            if ($newSub < 0) {
                $newSub = 0.0;
            }
            $lines[$last]['subtotal'] = $newSub;
            $lines[$last]['unit_price'] = round($newSub / $qty, 2);
        }

        return [
            'ok' => true,
            'lines' => $lines,
            'retail_sum' => round(array_sum($weights), 2),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateCombo(array $row, bool $withItems = false, ?string $today = null): array
    {
        $today = $today ?: date('Y-m-d');
        $from = $row['valid_from'] ?? null;
        $until = $row['valid_until'] ?? null;
        $inWindow = true;
        if ($from && $today < (string)$from) {
            $inWindow = false;
        }
        if ($until && $today > (string)$until) {
            $inWindow = false;
        }
        $active = (int)($row['is_active'] ?? 0) === 1;

        $combo = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'code' => (string)($row['code'] ?? ''),
            'sale_price' => round((float)($row['sale_price'] ?? 0), 2),
            'reason' => (string)($row['reason'] ?? ''),
            'reason_note' => (string)($row['reason_note'] ?? ''),
            'reason_label' => self::reasonLabel($row['reason'] ?? null, $row['reason_note'] ?? null),
            'valid_from' => $from ? (string)$from : null,
            'valid_until' => $until ? (string)$until : null,
            'is_active' => $active,
            'is_available' => $active && $inWindow,
            'times_used' => (int)($row['times_used'] ?? 0),
            'last_used_at' => $row['last_used_at'] ?? null,
            'item_count' => (int)($row['item_count'] ?? 0),
            'notes' => (string)($row['notes'] ?? ''),
            'items' => [],
        ];

        if ($withItems) {
            $combo['items'] = $this->loadItems((int)$row['id']);
            $combo['item_count'] = count($combo['items']);
            $retail = 0.0;
            foreach ($combo['items'] as $it) {
                $retail += (float)$it['unit_price'] * (int)$it['quantity'];
            }
            $combo['retail_sum'] = round($retail, 2);
            $combo['savings'] = round(max(0, $retail - (float)$combo['sale_price']), 2);
        }

        return $combo;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadItems(int $comboId): array
    {
        try {
            $st = $this->db->prepare(
                "SELECT i.product_id, i.quantity, i.sort_order,
                        p.name AS product_name, p.code, p.sale_price AS unit_price,
                        COALESCE(p.wholesale_price, 0) AS wholesale_price,
                        COALESCE(p.purchase_price, 0) AS purchase_price,
                        COALESCE(p.stock, 0) AS stock,
                        COALESCE(p.product_type, 'product') AS product_type,
                        COALESCE(p.ownership, 'owned') AS ownership,
                        p.consignor_id,
                        COALESCE(p.tax_rate, 0) AS tax_rate,
                        p.status
                 FROM product_combo_items i
                 INNER JOIN products p ON p.id = i.product_id
                 WHERE i.combo_id = ?
                 ORDER BY i.sort_order ASC, i.id ASC"
            );
            $st->execute([$comboId]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            if (($r['status'] ?? '') !== 'active') {
                continue;
            }
            $out[] = [
                'product_id' => (int)$r['product_id'],
                'product_name' => (string)$r['product_name'],
                'code' => (string)($r['code'] ?? ''),
                'quantity' => max(1, (int)$r['quantity']),
                'unit_price' => round((float)$r['unit_price'], 2),
                'wholesale_price' => round((float)$r['wholesale_price'], 2),
                'purchase_price' => round((float)$r['purchase_price'], 2),
                'stock' => (int)$r['stock'],
                'product_type' => (string)$r['product_type'],
                'ownership' => (string)$r['ownership'],
                'consignor_id' => (int)($r['consignor_id'] ?? 0),
                'is_consignment' => ($r['ownership'] ?? '') === 'consignment',
                'tax_rate' => $r['tax_rate'],
            ];
        }
        return $out;
    }

    private function normalizeDate(mixed $v): ?string
    {
        $v = trim((string)($v ?? ''));
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        return $v;
    }

    private function suggestCode(string $name, ?int $excludeId = null): string
    {
        $ascii = $name;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if (is_string($converted) && $converted !== '') {
                $ascii = $converted;
            }
        }
        $base = strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '', $ascii));
        $base = substr($base !== '' ? $base : 'COMBO', 0, 8);
        $code = 'CB-' . $base;
        try {
            $n = 0;
            while ($n < 20) {
                $try = $n === 0 ? $code : ($code . $n);
                $sql = 'SELECT id FROM product_combos WHERE code = ?';
                $params = [$try];
                if ($excludeId) {
                    $sql .= ' AND id <> ?';
                    $params[] = $excludeId;
                }
                $sql .= ' LIMIT 1';
                $st = $this->db->prepare($sql);
                $st->execute($params);
                if (!$st->fetch()) {
                    return substr($try, 0, 40);
                }
                $n++;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return substr($code . substr(bin2hex(random_bytes(2)), 0, 4), 0, 40);
    }
}
