<?php

namespace SoftNova\Services;

/**
 * Reposición de inventario: detecta qué falta, cuánto pedir y a quién.
 *
 * La cantidad sugerida busca llevar el stock al nivel objetivo (`max_stock` o el
 * doble del mínimo) y además cubrir la demanda del tiempo de entrega del proveedor,
 * de modo que el pedido no se agote antes de llegar.
 */
class ReplenishmentService
{
    private \PDO $db;
    /** @var array<string,list<array<string,mixed>>> Evita repetir la consulta pesada en una misma petición */
    private array $needsCache = [];

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        SupplyChainSchema::ensure($db);
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Productos agotados o por agotarse, con proveedor sugerido y cantidad a pedir.
     *
     * @param array{state?:string,supplier_id?:int,category_id?:int,q?:string,days?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function needsList(array $filters = [], int $limit = 300): array
    {
        $cacheKey = md5(json_encode($filters) . '|' . $limit);
        if (isset($this->needsCache[$cacheKey])) {
            return $this->needsCache[$cacheKey];
        }

        $estado = (string)($filters['state'] ?? '');
        $dias = max(7, min(365, (int)($filters['days'] ?? 30)));

        $where = [
            "p.status = 'active'",
            "COALESCE(p.product_type, 'product') = 'product'",
        ];
        $params = [$dias];

        if ($estado === 'out') {
            $where[] = 'p.stock <= 0';
        } elseif ($estado === 'low') {
            $where[] = 'p.stock > 0 AND p.stock <= p.min_stock';
        } else {
            $where[] = 'p.stock <= p.min_stock';
        }

        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = ?';
            $params[] = (int)$filters['category_id'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(p.name LIKE ? OR p.code LIKE ? OR p.barcode LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like);
        }

        if (!empty($filters['supplier_id'])) {
            $where[] = 'ps.supplier_id = ?';
            $params[] = (int)$filters['supplier_id'];
        }

        $sql = "SELECT p.id, p.code, p.barcode, p.name, p.stock, p.min_stock, p.max_stock,
                       p.purchase_price, p.sale_price, p.unit, p.next_expiry_date,
                       c.name category_name,
                       COALESCE(v.sold_qty, 0) sold_qty,
                       ps.supplier_id, ps.supplier_sku, ps.last_cost, ps.lead_time_days, ps.min_order_qty,
                       s.name supplier_name, s.email supplier_email, s.phone supplier_phone
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN (
                    SELECT si.product_id, SUM(si.quantity) sold_qty
                    FROM sale_items si
                    JOIN sales sa ON sa.id = si.sale_id
                    WHERE sa.status != 'cancelled'
                      AND sa.sale_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                    GROUP BY si.product_id
                ) v ON v.product_id = p.id
                LEFT JOIN product_suppliers ps ON ps.id = (
                    SELECT ps2.id FROM product_suppliers ps2
                    WHERE ps2.product_id = p.id AND ps2.status = 'active'
                    ORDER BY ps2.is_primary DESC, ps2.last_cost ASC, ps2.id ASC
                    LIMIT 1
                )
                LEFT JOIN suppliers s ON s.id = ps.supplier_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY (p.stock <= 0) DESC, COALESCE(v.sold_qty, 0) DESC, p.name ASC
                LIMIT " . max(1, min(1000, $limit));

        try {
            $filas = $this->query($sql, $params)->fetchAll();
        } catch (\Throwable $e) {
            error_log('ReplenishmentService::needsList: ' . $e->getMessage());
            return [];
        }

        foreach ($filas as &$fila) {
            $fila['suggested_qty'] = $this->suggestedQty($fila, $dias);
            $fila['unit_cost'] = round((float)($fila['last_cost'] ?: $fila['purchase_price']), 2);
            $fila['estimated_total'] = round($fila['suggested_qty'] * $fila['unit_cost'], 2);
            $fila['daily_demand'] = round(((float)$fila['sold_qty']) / $dias, 3);
            $fila['days_of_cover'] = $fila['daily_demand'] > 0
                ? (int)floor((int)$fila['stock'] / $fila['daily_demand'])
                : null;
        }
        unset($fila);

        $this->needsCache[$cacheKey] = $filas;

        return $filas;
    }

    /**
     * Cantidad a pedir: llena hasta el nivel objetivo y suma el consumo estimado
     * durante el tiempo de entrega, respetando el mínimo de pedido del proveedor.
     *
     * @param array<string,mixed> $fila
     */
    private function suggestedQty(array $fila, int $diasVentana): int
    {
        $stock = (int)$fila['stock'];
        $minimo = max(0, (int)$fila['min_stock']);
        $objetivo = (int)($fila['max_stock'] ?? 0);
        if ($objetivo <= 0) {
            $objetivo = max($minimo * 2, $minimo + 1);
        }

        $demandaDiaria = $diasVentana > 0 ? ((float)$fila['sold_qty']) / $diasVentana : 0.0;
        $entrega = max(0, (int)($fila['lead_time_days'] ?? 0));
        $colchonEntrega = (int)ceil($demandaDiaria * $entrega);

        $cantidad = max(0, $objetivo - $stock) + $colchonEntrega;
        $minimoPedido = max(1, (int)($fila['min_order_qty'] ?? 1));

        if ($cantidad <= 0) {
            return 0;
        }
        // Redondea hacia arriba al múltiplo del mínimo de pedido del proveedor.
        return (int)(ceil($cantidad / $minimoPedido) * $minimoPedido);
    }

    /**
     * Resumen para tarjetas del panel.
     *
     * @return array{out_of_stock:int,low_stock:int,without_supplier:int,estimated_cost:float}
     */
    public function summary(): array
    {
        $vacio = ['out_of_stock' => 0, 'low_stock' => 0, 'without_supplier' => 0, 'estimated_cost' => 0.0];

        try {
            $fila = $this->query(
                "SELECT
                    SUM(CASE WHEN p.stock <= 0 THEN 1 ELSE 0 END) out_of_stock,
                    SUM(CASE WHEN p.stock > 0 AND p.stock <= p.min_stock THEN 1 ELSE 0 END) low_stock,
                    SUM(CASE WHEN NOT EXISTS (
                            SELECT 1 FROM product_suppliers ps
                            WHERE ps.product_id = p.id AND ps.status = 'active'
                        ) THEN 1 ELSE 0 END) without_supplier
                 FROM products p
                 WHERE p.status = 'active'
                   AND COALESCE(p.product_type, 'product') = 'product'
                   AND p.stock <= p.min_stock"
            )->fetch();
        } catch (\Throwable $e) {
            return $vacio;
        }

        if (!$fila) {
            return $vacio;
        }

        $costo = 0.0;
        foreach ($this->needsList([], 500) as $item) {
            $costo += (float)$item['estimated_total'];
        }

        return [
            'out_of_stock' => (int)($fila['out_of_stock'] ?? 0),
            'low_stock' => (int)($fila['low_stock'] ?? 0),
            'without_supplier' => (int)($fila['without_supplier'] ?? 0),
            'estimated_cost' => round($costo, 2),
        ];
    }

    /**
     * Agrupa las necesidades por proveedor para generar una OC por cada uno.
     *
     * @param list<int> $productIds Si viene vacío, toma toda la lista de reposición
     * @return array<int,array{supplier:array<string,mixed>,items:list<array<string,mixed>>,total:float}>
     */
    public function groupBySupplier(array $productIds = [], array $overrides = []): array
    {
        $lista = $this->needsList([], 1000);
        $grupos = [];

        foreach ($lista as $item) {
            $productId = (int)$item['id'];
            if ($productIds && !in_array($productId, $productIds, true)) {
                continue;
            }

            $cantidad = isset($overrides[$productId])
                ? max(0, (int)$overrides[$productId])
                : (int)$item['suggested_qty'];
            if ($cantidad <= 0) {
                continue;
            }

            // 0 = sin proveedor: se genera una OC igualmente
            $supplierId = !empty($item['supplier_id']) ? (int)$item['supplier_id'] : 0;
            if (!isset($grupos[$supplierId])) {
                $grupos[$supplierId] = [
                    'supplier' => [
                        'id' => $supplierId > 0 ? $supplierId : null,
                        'name' => $supplierId > 0
                            ? (string)$item['supplier_name']
                            : 'Sin proveedor',
                        'email' => (string)($item['supplier_email'] ?? ''),
                        'phone' => (string)($item['supplier_phone'] ?? ''),
                    ],
                    'items' => [],
                    'total' => 0.0,
                ];
            }

            $costo = round((float)$item['unit_cost'], 2);
            // Sin tax_rate explícito: PurchasingService hereda products.tax_rate.
            $grupos[$supplierId]['items'][] = [
                'product_id' => $productId,
                'product_name' => (string)$item['name'],
                'product_code' => (string)($item['code'] ?? ''),
                'barcode' => (string)($item['barcode'] ?? ''),
                'supplier_sku' => (string)($item['supplier_sku'] ?? ''),
                'quantity' => $cantidad,
                'unit_cost' => $costo,
            ];
            $grupos[$supplierId]['total'] += round($cantidad * $costo, 2);
        }

        return $grupos;
    }

    /**
     * Crea una orden de compra en borrador por cada proveedor de la selección.
     * Productos sin proveedor van a una OC «Sin proveedor».
     *
     * @param list<int> $productIds
     * @param array<int,int> $overrides Cantidad manual por producto
     * @return array{orders:list<array{order_id:int,supplier_name:string,total:float}>,skipped_no_supplier:int}
     */
    public function generateOrders(array $productIds = [], array $overrides = []): array
    {
        $grupos = $this->groupBySupplier($productIds, $overrides);
        if (empty($grupos)) {
            throw new \RuntimeException('No hay productos seleccionados para generar órdenes');
        }

        $purchasing = new PurchasingService($this->db);
        $hoy = date('Y-m-d');
        $ordenes = [];

        foreach ($grupos as $grupo) {
            $supplierId = !empty($grupo['supplier']['id']) ? (int)$grupo['supplier']['id'] : null;
            $orderId = $purchasing->createOrder(
                $supplierId,
                $hoy,
                null,
                null,
                $supplierId
                    ? 'Generada desde el panel de reposición'
                    : 'Generada desde reposición (sin proveedor; asígnelo al recibir o cargar factura)',
                $grupo['items'],
                'draft'
            );
            $this->query(
                "UPDATE purchase_orders SET origin = 'replenishment' WHERE id = ?",
                [$orderId]
            );
            $ordenes[] = [
                'order_id' => $orderId,
                'supplier_name' => $grupo['supplier']['name'],
                'total' => round($grupo['total'], 2),
            ];
        }

        return ['orders' => $ordenes, 'skipped_no_supplier' => 0];
    }

    // ------------------------------------------------------------------ Bandeja acumulable

    /** @return list<array<string,mixed>> */
    public function draftList(): array
    {
        try {
            return $this->query(
                "SELECT d.id, d.product_id, d.quantity, d.unit_cost, d.updated_at,
                        p.name, p.code, p.barcode, p.stock, p.min_stock, p.purchase_price,
                        ps.supplier_id, ps.supplier_sku, s.name supplier_name
                 FROM replenishment_draft_items d
                 JOIN products p ON p.id = d.product_id
                 LEFT JOIN product_suppliers ps ON ps.id = (
                    SELECT ps2.id FROM product_suppliers ps2
                    WHERE ps2.product_id = p.id AND ps2.status = 'active'
                    ORDER BY ps2.is_primary DESC, ps2.last_cost ASC, ps2.id ASC
                    LIMIT 1
                 )
                 LEFT JOIN suppliers s ON s.id = ps.supplier_id
                 ORDER BY d.updated_at DESC, d.id DESC"
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function draftCount(): int
    {
        try {
            return (int)$this->query("SELECT COUNT(*) FROM replenishment_draft_items")->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Suma productos a la bandeja (acumula cantidad si ya existía).
     *
     * @param list<int> $productIds
     * @param array<int,int> $overrides
     * @return array{added:int,updated:int}
     */
    public function draftAdd(array $productIds, array $overrides = []): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (empty($productIds)) {
            throw new \InvalidArgumentException('Seleccione al menos un producto');
        }

        $needsMap = [];
        foreach ($this->needsList([], 1000) as $item) {
            $needsMap[(int)$item['id']] = $item;
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $products = $this->query(
            "SELECT id, name, purchase_price, status, COALESCE(product_type, 'product') product_type
             FROM products WHERE id IN ($placeholders)",
            $productIds
        )->fetchAll();
        $byId = [];
        foreach ($products as $p) {
            $byId[(int)$p['id']] = $p;
        }

        $added = 0;
        $updated = 0;
        $userId = $_SESSION['tenant_user_id'] ?? null;
        $upsert = $this->db->prepare(
            "INSERT INTO replenishment_draft_items (product_id, quantity, unit_cost, added_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                quantity = quantity + VALUES(quantity),
                unit_cost = COALESCE(VALUES(unit_cost), unit_cost),
                updated_at = NOW()"
        );

        foreach ($productIds as $pid) {
            $prod = $byId[$pid] ?? null;
            if (!$prod || ($prod['status'] ?? '') !== 'active' || ($prod['product_type'] ?? '') === 'service') {
                continue;
            }
            $need = $needsMap[$pid] ?? null;
            $qty = array_key_exists($pid, $overrides)
                ? max(0, (int)$overrides[$pid])
                : (int)($need['suggested_qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $cost = $need
                ? round((float)$need['unit_cost'], 2)
                : round((float)$prod['purchase_price'], 2);

            $existed = (bool)$this->query(
                "SELECT 1 FROM replenishment_draft_items WHERE product_id = ? LIMIT 1",
                [$pid]
            )->fetchColumn();
            $upsert->execute([$pid, $qty, $cost, $userId]);
            if ($existed) {
                $updated++;
            } else {
                $added++;
            }
        }

        if ($added + $updated === 0) {
            throw new \RuntimeException('No se agregó ningún producto (revise cantidades o estado del producto)');
        }

        return ['added' => $added, 'updated' => $updated];
    }

    public function draftUpdateQty(int $productId, int $quantity): void
    {
        if ($productId <= 0) {
            throw new \InvalidArgumentException('Producto no válido');
        }
        if ($quantity <= 0) {
            $this->draftRemove($productId);
            return;
        }
        $stmt = $this->query(
            "UPDATE replenishment_draft_items SET quantity = ?, updated_at = NOW() WHERE product_id = ?",
            [$quantity, $productId]
        );
        if ($stmt->rowCount() < 1) {
            throw new \RuntimeException('El producto no está en la bandeja');
        }
    }

    public function draftRemove(int $productId): void
    {
        $this->query("DELETE FROM replenishment_draft_items WHERE product_id = ?", [$productId]);
    }

    public function draftClear(): void
    {
        $this->query("DELETE FROM replenishment_draft_items");
    }

    /**
     * Genera OC desde la bandeja con las cantidades guardadas (no depende de needsList)
     * y la vacía solo al terminar bien. Evita doble clic con bloqueo de filas.
     *
     * @return array{orders:list<array{order_id:int,supplier_name:string,total:float}>,skipped_no_supplier:int}
     */
    public function generateFromDraft(): array
    {
        $this->db->beginTransaction();
        try {
            // Bloqueo: otra pestaña no puede generar en paralelo.
            $lock = $this->query(
                "SELECT product_id FROM replenishment_draft_items ORDER BY product_id FOR UPDATE"
            )->fetchAll();
            if (empty($lock)) {
                throw new \RuntimeException('La bandeja de reposición está vacía');
            }

            $draft = $this->draftList();
            if (empty($draft)) {
                throw new \RuntimeException('La bandeja de reposición está vacía');
            }

            $grupos = $this->groupDraftLines($draft);
            if (empty($grupos)) {
                throw new \RuntimeException('No hay líneas válidas en la bandeja para generar órdenes');
            }

            $purchasing = new PurchasingService($this->db);
            $hoy = date('Y-m-d');
            $ordenes = [];

            foreach ($grupos as $grupo) {
                $supplierId = !empty($grupo['supplier']['id']) ? (int)$grupo['supplier']['id'] : null;
                $orderId = $purchasing->createOrder(
                    $supplierId,
                    $hoy,
                    null,
                    null,
                    $supplierId
                        ? 'Generada desde el panel de reposición'
                        : 'Generada desde reposición (sin proveedor; asígnelo al recibir o cargar factura)',
                    $grupo['items'],
                    'draft'
                );
                $this->query(
                    "UPDATE purchase_orders SET origin = 'replenishment' WHERE id = ?",
                    [$orderId]
                );
                $ordenes[] = [
                    'order_id' => $orderId,
                    'supplier_name' => $grupo['supplier']['name'],
                    'total' => round($grupo['total'], 2),
                ];
            }

            $this->draftClear();
            $this->db->commit();
            return ['orders' => $ordenes, 'skipped_no_supplier' => 0];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Agrupa líneas de bandeja por proveedor (independiente de needsList).
     *
     * @param list<array<string,mixed>> $draft
     * @return array<int,array{supplier:array<string,mixed>,items:list<array<string,mixed>>,total:float}>
     */
    private function groupDraftLines(array $draft): array
    {
        $grupos = [];
        foreach ($draft as $row) {
            $productId = (int)($row['product_id'] ?? 0);
            $cantidad = (int)($row['quantity'] ?? 0);
            if ($productId <= 0 || $cantidad <= 0) {
                continue;
            }
            $supplierId = !empty($row['supplier_id']) ? (int)$row['supplier_id'] : 0;
            if (!isset($grupos[$supplierId])) {
                $grupos[$supplierId] = [
                    'supplier' => [
                        'id' => $supplierId > 0 ? $supplierId : null,
                        'name' => $supplierId > 0
                            ? (string)($row['supplier_name'] ?? 'Proveedor')
                            : 'Sin proveedor',
                    ],
                    'items' => [],
                    'total' => 0.0,
                ];
            }
            $costo = round((float)($row['unit_cost'] ?? $row['purchase_price'] ?? 0), 2);
            // Sin tax_rate explícito: PurchasingService hereda products.tax_rate.
            $grupos[$supplierId]['items'][] = [
                'product_id' => $productId,
                'product_name' => (string)($row['name'] ?? ''),
                'product_code' => (string)($row['code'] ?? ''),
                'barcode' => (string)($row['barcode'] ?? ''),
                'supplier_sku' => (string)($row['supplier_sku'] ?? ''),
                'quantity' => $cantidad,
                'unit_cost' => $costo,
            ];
            $grupos[$supplierId]['total'] += round($cantidad * $costo, 2);
        }
        return $grupos;
    }

    /**
     * Órdenes generadas desde el panel que aún no llegan a bodega, para poder
     * reenviarlas o descargar el PDF sin salir de la pantalla.
     *
     * @return list<array<string,mixed>>
     */
    public function pendingOrders(int $limit = 30): array
    {
        try {
            return $this->query(
                "SELECT po.*, s.name supplier_name, s.email supplier_email, s.phone supplier_phone
                 FROM purchase_orders po
                 LEFT JOIN suppliers s ON s.id = po.supplier_id
                 WHERE po.status IN ('draft', 'ordered')
                 ORDER BY po.sent_at IS NOT NULL ASC, po.id DESC
                 LIMIT " . max(1, min(100, $limit))
            )->fetchAll();
        } catch (\Throwable $e) {
            error_log('ReplenishmentService::pendingOrders: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Registra que la orden fue enviada al proveedor y por qué medio.
     */
    public function markSent(int $orderId, string $via, string $destination = ''): void
    {
        if (!in_array($via, ['pdf', 'email', 'whatsapp'], true)) {
            $via = 'pdf';
        }
        $this->query(
            "UPDATE purchase_orders
             SET sent_at = NOW(), sent_via = ?, sent_to = NULLIF(?, ''),
                 status = CASE WHEN status = 'draft' THEN 'ordered' ELSE status END
             WHERE id = ?",
            [$via, substr($destination, 0, 180), $orderId]
        );
    }

    /**
     * Mensaje de WhatsApp con el detalle de la orden, listo para wa.me.
     */
    public function whatsappMessage(int $orderId): string
    {
        $purchasing = new PurchasingService($this->db);
        $order = $purchasing->getOrder($orderId);
        if (!$order) {
            throw new \RuntimeException('Orden no encontrada');
        }

        $empresa = $this->companyName();
        $lineas = [
            'Orden de compra ' . $order['order_number'] . ' - ' . $empresa,
            'Fecha: ' . substr((string)$order['order_date'], 0, 10),
            '',
            'Productos:',
        ];

        foreach ($order['items'] as $item) {
            $ean = trim((string)($item['barcode'] ?? ''));
            $lineas[] = sprintf(
                '- %s%s x %d',
                (string)$item['product_name'],
                $ean !== '' ? (' [EAN ' . $ean . ']') : '',
                (int)$item['quantity']
            );
        }

        $lineas[] = '';
        $lineas[] = 'Total estimado: ' . number_format((float)$order['total'], 2, ',', '.');
        $lineas[] = 'Favor confirmar disponibilidad y fecha de entrega. Gracias.';

        return implode("\n", $lineas);
    }

    /** Enlace wa.me con el mensaje ya codificado. */
    public function whatsappLink(int $orderId, string $phone): string
    {
        $numero = preg_replace('/\D/', '', $phone) ?? '';
        $base = $numero !== '' ? 'https://wa.me/' . $numero : 'https://wa.me/';
        return $base . '?text=' . rawurlencode($this->whatsappMessage($orderId));
    }

    private function companyName(): string
    {
        try {
            $valor = (string)$this->query(
                "SELECT setting_value FROM settings WHERE setting_key = 'company_name' LIMIT 1"
            )->fetchColumn();
            return $valor !== '' ? $valor : 'Nuestra empresa';
        } catch (\Throwable $e) {
            return 'Nuestra empresa';
        }
    }
}
