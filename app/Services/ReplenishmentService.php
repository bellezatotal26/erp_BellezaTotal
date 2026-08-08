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
            $where[] = '(p.name LIKE ? OR p.code LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like);
        }

        if (!empty($filters['supplier_id'])) {
            $where[] = 'ps.supplier_id = ?';
            $params[] = (int)$filters['supplier_id'];
        }

        $sql = "SELECT p.id, p.code, p.name, p.stock, p.min_stock, p.max_stock,
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
            if (empty($item['supplier_id'])) {
                continue;
            }

            $cantidad = isset($overrides[$productId])
                ? max(0, (int)$overrides[$productId])
                : (int)$item['suggested_qty'];
            if ($cantidad <= 0) {
                continue;
            }

            $supplierId = (int)$item['supplier_id'];
            if (!isset($grupos[$supplierId])) {
                $grupos[$supplierId] = [
                    'supplier' => [
                        'id' => $supplierId,
                        'name' => (string)$item['supplier_name'],
                        'email' => (string)($item['supplier_email'] ?? ''),
                        'phone' => (string)($item['supplier_phone'] ?? ''),
                    ],
                    'items' => [],
                    'total' => 0.0,
                ];
            }

            $costo = round((float)$item['unit_cost'], 2);
            $grupos[$supplierId]['items'][] = [
                'product_id' => $productId,
                'product_name' => (string)$item['name'],
                'supplier_sku' => (string)($item['supplier_sku'] ?? ''),
                'quantity' => $cantidad,
                'unit_cost' => $costo,
                'tax_rate' => 0.0,
            ];
            $grupos[$supplierId]['total'] += round($cantidad * $costo, 2);
        }

        return $grupos;
    }

    /**
     * Crea una orden de compra en borrador por cada proveedor de la selección.
     *
     * @param list<int> $productIds
     * @param array<int,int> $overrides Cantidad manual por producto
     * @return array{orders:list<array{order_id:int,supplier_name:string,total:float}>,skipped_no_supplier:int}
     */
    public function generateOrders(array $productIds = [], array $overrides = []): array
    {
        $grupos = $this->groupBySupplier($productIds, $overrides);
        if (empty($grupos)) {
            throw new \RuntimeException('No hay productos con proveedor asignado para generar órdenes');
        }

        $purchasing = new PurchasingService($this->db);
        $hoy = date('Y-m-d');
        $ordenes = [];

        foreach ($grupos as $supplierId => $grupo) {
            $orderId = $purchasing->createOrder(
                $supplierId,
                $hoy,
                null,
                null,
                'Generada desde el panel de reposición',
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

        $sinProveedor = 0;
        foreach ($this->needsList([], 1000) as $item) {
            if (empty($item['supplier_id']) && (!$productIds || in_array((int)$item['id'], $productIds, true))) {
                $sinProveedor++;
            }
        }

        return ['orders' => $ordenes, 'skipped_no_supplier' => $sinProveedor];
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
            $lineas[] = sprintf(
                '- %s x %d',
                (string)$item['product_name'],
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
