<?php

namespace SoftNova\Services;

/**
 * Ofertas temporales de precio de venta.
 * Guarda el precio original, aplica el de oferta y lo restaura al vencer.
 */
class ProductOfferService
{
    private \PDO $db;
    private static bool $schemaReady = false;

    public const PERCENTS = [5, 10, 15, 20, 25, 30, 40, 50];

    /** @var array<string,string> code => label */
    public const REASONS = [
        'navidad' => 'Navidad',
        'san_valentin' => 'San Valentín',
        'dia_madre' => 'Día de la Madre',
        'dia_padre' => 'Día del Padre',
        'halloween' => 'Halloween',
        'ano_nuevo' => 'Año Nuevo',
        'black_friday' => 'Black Friday',
        'cyber_monday' => 'Cyber Monday',
        'pride_day' => 'Pride Day',
        'liquidacion' => 'Liquidación',
        'aniversario' => 'Aniversario',
        'otro' => 'Otro',
    ];

    /** @var array<string,string> code => label */
    public const DURATIONS = [
        '1h' => '1 hora',
        '6h' => '6 horas',
        '12h' => '12 horas',
        '24h' => '24 horas',
        '3d' => '3 días',
        '7d' => '7 días',
        '15d' => '15 días',
        '30d' => '30 días',
        'custom' => 'Fecha/hora personalizada',
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

        $cols = [
            'offer_active' => "ALTER TABLE products ADD COLUMN offer_active TINYINT(1) NOT NULL DEFAULT 0",
            'offer_price' => "ALTER TABLE products ADD COLUMN offer_price DECIMAL(12,2) NULL",
            'offer_original_price' => "ALTER TABLE products ADD COLUMN offer_original_price DECIMAL(12,2) NULL",
            'offer_percent' => "ALTER TABLE products ADD COLUMN offer_percent DECIMAL(5,2) NULL",
            'offer_reason' => "ALTER TABLE products ADD COLUMN offer_reason VARCHAR(40) NULL",
            'offer_reason_note' => "ALTER TABLE products ADD COLUMN offer_reason_note VARCHAR(180) NULL",
            'offer_starts_at' => "ALTER TABLE products ADD COLUMN offer_starts_at DATETIME NULL",
            'offer_ends_at' => "ALTER TABLE products ADD COLUMN offer_ends_at DATETIME NULL",
        ];
        foreach ($cols as $name => $sql) {
            try {
                $exists = $this->db->query("SHOW COLUMNS FROM products LIKE " . $this->db->quote($name))->fetch();
                if (!$exists) {
                    $this->db->exec($sql);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS product_offer_log (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    product_id INT UNSIGNED NOT NULL,
                    action VARCHAR(20) NOT NULL,
                    original_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                    offer_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                    percent DECIMAL(5,2) NULL,
                    reason VARCHAR(40) NULL,
                    reason_note VARCHAR(180) NULL,
                    starts_at DATETIME NULL,
                    ends_at DATETIME NULL,
                    user_id INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_pol_product (product_id),
                    INDEX idx_pol_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * Restaura ofertas vencidas y aplica las programadas cuyo inicio ya llegó.
     * @return int cantidad de cambios (expiradas + activadas)
     */
    public function expireDueOffers(): int
    {
        $this->ensureSchema();
        $n = $this->applyScheduledStarts();

        try {
            $rows = $this->db->query(
                "SELECT id, sale_price, offer_original_price, offer_price, offer_percent,
                        offer_reason, offer_reason_note, offer_starts_at, offer_ends_at
                 FROM products
                 WHERE offer_active = 1
                   AND offer_ends_at IS NOT NULL
                   AND offer_ends_at <= NOW()
                 LIMIT 200"
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return $n;
        }

        foreach ($rows as $row) {
            try {
                $this->restoreOffer((int)$row['id'], $row, 'expire');
                $n++;
            } catch (\Throwable $e) {
                error_log('expireDueOffers #' . ($row['id'] ?? '?') . ': ' . $e->getMessage());
            }
        }
        return $n;
    }

    /**
     * Ofertas programadas: al llegar offer_starts_at aplica el precio de oferta.
     */
    public function applyScheduledStarts(): int
    {
        try {
            $rows = $this->db->query(
                "SELECT id, sale_price, offer_price, offer_original_price, offer_reason, offer_ends_at, offer_starts_at
                 FROM products
                 WHERE offer_active = 1
                   AND offer_price IS NOT NULL
                   AND offer_starts_at IS NOT NULL
                   AND offer_starts_at <= NOW()
                   AND (offer_ends_at IS NULL OR offer_ends_at > NOW())
                   AND ROUND(sale_price, 2) <> ROUND(offer_price, 2)
                 LIMIT 200"
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return 0;
        }

        $n = 0;
        foreach ($rows as $row) {
            try {
                $offerPrice = round((float)$row['offer_price'], 2);
                $from = round((float)$row['sale_price'], 2);
                $upd = $this->db->prepare("UPDATE products SET sale_price = ? WHERE id = ?");
                $upd->execute([$offerPrice, (int)$row['id']]);
                $this->tracePriceChange(
                    (int)$row['id'],
                    $from,
                    $offerPrice,
                    (string)($row['offer_reason'] ?? 'oferta'),
                    $row['offer_ends_at'] ?? null,
                    'Oferta programada iniciada'
                );
                $this->log(
                    (int)$row['id'],
                    'activate',
                    round((float)($row['offer_original_price'] ?? $from), 2),
                    $offerPrice,
                    null,
                    (string)($row['offer_reason'] ?? ''),
                    '',
                    $row['offer_starts_at'] ?? null,
                    $row['offer_ends_at'] ?? null,
                    null
                );
                $n++;
            } catch (\Throwable $e) {
                error_log('applyScheduledStarts #' . ($row['id'] ?? '?') . ': ' . $e->getMessage());
            }
        }
        return $n;
    }

    /**
     * Activa (o reemplaza) una oferta.
     *
     * @param array{
     *   offer_price?:float|string,
     *   percent?:float|string|null,
     *   duration?:string,
     *   starts_at?:string,
     *   ends_at?:string,
     *   reason?:string,
     *   reason_note?:string
     * } $input
     * @return array<string,mixed>
     */
    public function activate(int $productId, array $input, ?int $userId = null): array
    {
        $this->ensureSchema();
        $this->expireDueOffers();

        if ($productId <= 0) {
            throw new \InvalidArgumentException('Producto inválido');
        }

        $stmt = $this->db->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
        $stmt->execute([$productId]);
        $product = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$product || ($product['status'] ?? '') !== 'active') {
            throw new \RuntimeException('Producto no encontrado o inactivo');
        }
        if (OpenItemProduct::isOpenItem($product)) {
            throw new \RuntimeException('El producto comodín no admite ofertas');
        }

        $basePrice = (int)($product['offer_active'] ?? 0) === 1
            && $product['offer_original_price'] !== null
            ? round((float)$product['offer_original_price'], 2)
            : round((float)($product['sale_price'] ?? 0), 2);

        if ($basePrice <= 0) {
            throw new \InvalidArgumentException('El producto debe tener un precio de venta mayor a 0 para crear oferta');
        }

        $percent = isset($input['percent']) && $input['percent'] !== '' && $input['percent'] !== null
            ? round((float)$input['percent'], 2)
            : null;
        if ($percent !== null && ($percent <= 0 || $percent >= 100)) {
            throw new \InvalidArgumentException('El porcentaje debe estar entre 1 y 99');
        }

        $offerPriceRaw = $input['offer_price'] ?? 0;
        if (is_string($offerPriceRaw)) {
            $offerPrice = round(\SoftNova\Core\parse_money($offerPriceRaw), 2);
        } else {
            $offerPrice = round((float)$offerPriceRaw, 2);
        }
        if ($percent !== null && $offerPrice <= 0) {
            $offerPrice = round($basePrice * (1 - ($percent / 100)), 2);
        }
        if ($offerPrice <= 0) {
            throw new \InvalidArgumentException('Indique el precio de oferta o un porcentaje válido');
        }
        if ($offerPrice >= $basePrice) {
            throw new \InvalidArgumentException('El precio de oferta debe ser menor al precio original (' . number_format($basePrice, 2, '.', '') . ')');
        }
        if ($percent === null && $basePrice > 0) {
            $percent = round((($basePrice - $offerPrice) / $basePrice) * 100, 2);
        }

        $startsAt = $this->resolveStartsAt((string)($input['starts_at'] ?? ''));
        $endsAt = $this->resolveEndsAt(
            (string)($input['duration'] ?? ''),
            (string)($input['ends_at'] ?? ''),
            $startsAt
        );
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('La fecha de fin debe ser posterior al inicio de la oferta');
        }

        $reason = strtolower(trim((string)($input['reason'] ?? '')));
        if ($reason === '' || !isset(self::REASONS[$reason])) {
            throw new \InvalidArgumentException('Seleccione un motivo de oferta válido');
        }
        $reasonNote = trim((string)($input['reason_note'] ?? ''));
        if ($reason === 'otro' && $reasonNote === '') {
            throw new \InvalidArgumentException('Describa el motivo de la oferta');
        }
        if (mb_strlen($reasonNote) > 180) {
            $reasonNote = mb_substr($reasonNote, 0, 180);
        }

        // Si ya había oferta, restaurar primero al original base (sin log de expire).
        if ((int)($product['offer_active'] ?? 0) === 1) {
            $this->clearOfferFlags($productId, $basePrice);
        }

        $now = date('Y-m-d H:i:s');
        $isLive = $startsAt <= $now;
        // Programada: mantiene precio original hasta la hora de inicio.
        $salePriceNow = $isLive ? $offerPrice : $basePrice;

        $upd = $this->db->prepare(
            "UPDATE products SET
                sale_price = ?,
                offer_active = 1,
                offer_price = ?,
                offer_original_price = ?,
                offer_percent = ?,
                offer_reason = ?,
                offer_reason_note = ?,
                offer_starts_at = ?,
                offer_ends_at = ?
             WHERE id = ?"
        );
        $upd->execute([
            $salePriceNow,
            $offerPrice,
            $basePrice,
            $percent,
            $reason,
            $reasonNote !== '' ? $reasonNote : null,
            $startsAt,
            $endsAt,
            $productId,
        ]);

        $action = $isLive ? 'activate' : 'schedule';
        $this->log($productId, $action, $basePrice, $offerPrice, $percent, $reason, $reasonNote, $startsAt, $endsAt, $userId);
        if ($isLive) {
            $this->tracePriceChange($productId, $basePrice, $offerPrice, $reason, $endsAt);
        }

        return [
            'product_id' => $productId,
            'original_price' => $basePrice,
            'offer_price' => $offerPrice,
            'percent' => $percent,
            'reason' => $reason,
            'reason_label' => self::REASONS[$reason],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'scheduled' => !$isLive,
        ];
    }

    public function cancel(int $productId, ?int $userId = null): array
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
        $stmt->execute([$productId]);
        $product = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$product) {
            throw new \RuntimeException('Producto no encontrado');
        }
        if ((int)($product['offer_active'] ?? 0) !== 1) {
            throw new \RuntimeException('Este producto no tiene una oferta activa');
        }
        return $this->restoreOffer($productId, $product, 'cancel', $userId);
    }

    /** @param array<string,mixed> $row */
    private function restoreOffer(int $productId, array $row, string $action, ?int $userId = null): array
    {
        $original = $row['offer_original_price'] !== null
            ? round((float)$row['offer_original_price'], 2)
            : round((float)($row['sale_price'] ?? 0), 2);
        $offerPrice = round((float)($row['offer_price'] ?? $row['sale_price'] ?? 0), 2);

        $this->clearOfferFlags($productId, $original);
        $this->log(
            $productId,
            $action,
            $original,
            $offerPrice,
            isset($row['offer_percent']) ? (float)$row['offer_percent'] : null,
            (string)($row['offer_reason'] ?? ''),
            (string)($row['offer_reason_note'] ?? ''),
            $row['offer_starts_at'] ?? null,
            $row['offer_ends_at'] ?? null,
            $userId
        );
        // Solo trazar cambio de precio si la oferta ya estaba aplicada en sale_price.
        $currentSale = round((float)($row['sale_price'] ?? 0), 2);
        $wasLive = abs($currentSale - $offerPrice) < 0.009;
        if ($wasLive && abs($offerPrice - $original) >= 0.009) {
            $this->tracePriceChange(
                $productId,
                $offerPrice,
                $original,
                (string)($row['offer_reason'] ?? 'oferta'),
                null,
                $action === 'expire' ? 'Oferta vencida · precio restaurado' : 'Oferta cancelada · precio restaurado'
            );
        }

        return [
            'product_id' => $productId,
            'restored_price' => $original,
            'action' => $action,
        ];
    }

    private function clearOfferFlags(int $productId, float $salePrice): void
    {
        $stmt = $this->db->prepare(
            "UPDATE products SET
                sale_price = ?,
                offer_active = 0,
                offer_price = NULL,
                offer_original_price = NULL,
                offer_percent = NULL,
                offer_reason = NULL,
                offer_reason_note = NULL,
                offer_starts_at = NULL,
                offer_ends_at = NULL
             WHERE id = ?"
        );
        $stmt->execute([$salePrice, $productId]);
    }

    private function resolveStartsAt(string $customStartsAt): string
    {
        $raw = trim($customStartsAt);
        if ($raw === '') {
            return date('Y-m-d H:i:s');
        }
        $raw = str_replace('T', ' ', $raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) {
            $raw .= ':00';
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i', substr($raw, 0, 16));
        if (!$dt) {
            throw new \InvalidArgumentException('Fecha de inicio de oferta inválida');
        }
        return $dt->format('Y-m-d H:i:s');
    }

    private function resolveEndsAt(string $duration, string $customEndsAt, ?string $fromStartsAt = null): string
    {
        $base = $fromStartsAt
            ? (\DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $fromStartsAt) ?: new \DateTimeImmutable('now'))
            : new \DateTimeImmutable('now');

        $duration = trim($duration);
        if ($duration === 'custom' || ($duration === '' && $customEndsAt !== '')) {
            $raw = trim($customEndsAt);
            if ($raw === '') {
                throw new \InvalidArgumentException('Indique la fecha y hora de fin de la oferta');
            }
            $raw = str_replace('T', ' ', $raw);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) {
                $raw .= ':00';
            }
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i', substr($raw, 0, 16));
            if (!$dt) {
                throw new \InvalidArgumentException('Fecha de fin de oferta inválida');
            }
            return $dt->format('Y-m-d H:i:s');
        }

        $map = [
            '1h' => '+1 hour',
            '6h' => '+6 hours',
            '12h' => '+12 hours',
            '24h' => '+1 day',
            '3d' => '+3 days',
            '7d' => '+7 days',
            '15d' => '+15 days',
            '30d' => '+30 days',
        ];
        if (!isset($map[$duration])) {
            throw new \InvalidArgumentException('Seleccione la duración de la oferta');
        }
        return $base->modify($map[$duration])->format('Y-m-d H:i:s');
    }

    private function log(
        int $productId,
        string $action,
        float $original,
        float $offer,
        ?float $percent,
        string $reason,
        string $reasonNote,
        ?string $startsAt,
        ?string $endsAt,
        ?int $userId
    ): void {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO product_offer_log
                    (product_id, action, original_price, offer_price, percent, reason, reason_note, starts_at, ends_at, user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $productId,
                $action,
                $original,
                $offer,
                $percent,
                $reason !== '' ? $reason : null,
                $reasonNote !== '' ? $reasonNote : null,
                $startsAt,
                $endsAt,
                $userId,
            ]);
        } catch (\Throwable $e) {
            // no bloquear
        }
    }

    private function tracePriceChange(
        int $productId,
        float $from,
        float $to,
        string $reason,
        ?string $endsAt = null,
        ?string $prefix = null
    ): void {
        try {
            $stock = new StockService($this->db);
            $label = self::REASONS[$reason] ?? $reason;
            $note = ($prefix ?? 'Oferta') . ': ' . $label;
            if ($endsAt) {
                $note .= ' · hasta ' . date('d/m/Y H:i', strtotime($endsAt));
            }
            // recordSalePriceChange también hace UPDATE sale_price — ya actualizado.
            // Solo dejamos movimiento de auditoría sin re-escribir precio:
            $product = $this->db->prepare("SELECT stock FROM products WHERE id = ?");
            $product->execute([$productId]);
            $stockAfter = (int)$product->fetchColumn();
            $stock->addMovement(
                $productId,
                'adjustment',
                0,
                'price_change',
                null,
                'Cambio precio venta: '
                    . number_format($from, 2, '.', '')
                    . ' → '
                    . number_format($to, 2, '.', '')
                    . ' · '
                    . $note,
                null,
                $to,
                $stockAfter
            );
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public static function reasonLabel(?string $code): string
    {
        $code = strtolower(trim((string)$code));
        return self::REASONS[$code] ?? ($code !== '' ? $code : '—');
    }

    /**
     * Ofertas activas más recientes (para sugeridos en Caja).
     * @return list<array<string,mixed>>
     */
    public function latestActiveOffers(int $limit = 3): array
    {
        $this->ensureSchema();
        $this->expireDueOffers();
        $limit = max(1, min(20, $limit));
        try {
            $stmt = $this->db->prepare(
                "SELECT id, name, code, sale_price, COALESCE(wholesale_price, 0) AS wholesale_price,
                        COALESCE(purchase_price, 0) AS purchase_price,
                        stock, COALESCE(product_type, 'product') AS product_type,
                        offer_active, offer_price, offer_original_price, offer_percent,
                        offer_reason, offer_ends_at, offer_starts_at
                 FROM products
                 WHERE status = 'active'
                   AND offer_active = 1
                   AND (offer_starts_at IS NULL OR offer_starts_at <= NOW())
                   AND (offer_ends_at IS NULL OR offer_ends_at > NOW())
                   AND UPPER(COALESCE(code, '')) <> ?
                 ORDER BY offer_starts_at DESC, id DESC
                 LIMIT {$limit}"
            );
            $stmt->execute([OpenItemProduct::CODE]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        foreach ($rows as &$r) {
            $r['is_offer'] = 1;
            $r['offer_reason_label'] = self::reasonLabel($r['offer_reason'] ?? '');
        }
        unset($r);
        return $rows;
    }

    /** @return array{percents:list<int>,reasons:array<string,string>,durations:array<string,string>} */
    public static function formOptions(): array
    {
        return [
            'percents' => self::PERCENTS,
            'reasons' => self::REASONS,
            'durations' => self::DURATIONS,
        ];
    }
}
