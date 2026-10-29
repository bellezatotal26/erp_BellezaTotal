<?php

namespace SoftNova\Services;

/**
 * Mercancía en consignación: terceros (consignantes) prestan productos
 * que no son inventario propio ni compras a proveedores.
 *
 * - Stock físico en products.stock con ownership=consignment (excluido del inventario propio).
 * - Entrada: sin capitalizar 143501 (off-balance hasta la venta).
 * - Venta: ingreso normal + CxP al consignante por el costo pactado.
 * - Liquidación: pago al consignante de lo vendido.
 */
class ConsignmentService
{
    public const OWNERSHIP_OWNED = 'owned';
    public const OWNERSHIP_CONSIGNMENT = 'consignment';
    /** Mercancía que nos prestaron (no es consignación). */
    public const OWNERSHIP_BORROWED = 'borrowed';
    public const LOCATION_LABEL = 'CONSIGNACION';

    private \PDO $db;
    private StockService $stock;
    private static array $schemaDone = [];

    public function __construct(\PDO $db, ?StockService $stock = null)
    {
        $this->db = $db;
        $this->stock = $stock ?? new StockService($db);
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        $key = spl_object_id($this->db);
        if (!empty(self::$schemaDone[$key])) {
            return;
        }
        self::$schemaDone[$key] = true;

        $ddl = [
            "CREATE TABLE IF NOT EXISTS consignors (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(160) NOT NULL,
                document_type VARCHAR(20) NULL,
                document_number VARCHAR(40) NULL,
                phone VARCHAR(40) NULL,
                email VARCHAR(120) NULL,
                address VARCHAR(255) NULL,
                notes TEXT NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                INDEX idx_consignor_status (status),
                INDEX idx_consignor_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS consignment_receipts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                consignor_id INT UNSIGNED NOT NULL,
                receipt_number VARCHAR(40) NULL,
                receipt_date DATE NOT NULL,
                notes TEXT NULL,
                user_id INT UNSIGNED NULL,
                status ENUM('open','closed','cancelled') NOT NULL DEFAULT 'open',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cr_consignor (consignor_id),
                INDEX idx_cr_date (receipt_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS consignment_receipt_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                receipt_id BIGINT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                quantity INT NOT NULL,
                unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                stock_movement_id INT UNSIGNED NULL,
                notes VARCHAR(255) NULL,
                INDEX idx_cri_receipt (receipt_id),
                INDEX idx_cri_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS consignment_sale_debts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                sale_id INT UNSIGNED NOT NULL,
                sale_item_id INT UNSIGNED NULL,
                product_id INT UNSIGNED NOT NULL,
                consignor_id INT UNSIGNED NOT NULL,
                qty INT NOT NULL,
                qty_settled INT NOT NULL DEFAULT 0,
                unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                status ENUM('open','partial','settled','void') NOT NULL DEFAULT 'open',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                INDEX idx_csd_status (status),
                INDEX idx_csd_consignor (consignor_id, status),
                INDEX idx_csd_sale (sale_id),
                INDEX idx_csd_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS consignment_settlements (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                consignor_id INT UNSIGNED NOT NULL,
                settlement_date DATE NOT NULL,
                amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                payment_method VARCHAR(40) NOT NULL DEFAULT 'cash',
                notes TEXT NULL,
                user_id INT UNSIGNED NULL,
                accounting_entry_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cs_consignor (consignor_id),
                INDEX idx_cs_date (settlement_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS consignment_settlement_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                settlement_id BIGINT UNSIGNED NOT NULL,
                debt_id BIGINT UNSIGNED NOT NULL,
                qty INT NOT NULL,
                amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                INDEX idx_csi_settlement (settlement_id),
                INDEX idx_csi_debt (debt_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        foreach ($ddl as $sql) {
            try {
                $this->db->exec($sql);
            } catch (\Throwable $e) {
                error_log('ConsignmentService schema: ' . $e->getMessage());
            }
        }

        foreach ([
            'ownership' => "ALTER TABLE products ADD COLUMN ownership VARCHAR(20) NOT NULL DEFAULT 'owned' AFTER product_type",
            'consignor_id' => "ALTER TABLE products ADD COLUMN consignor_id INT UNSIGNED NULL AFTER ownership",
        ] as $col => $sql) {
            try {
                $exists = $this->db->query("SHOW COLUMNS FROM products LIKE " . $this->db->quote($col))->fetch();
                if (!$exists) {
                    $this->db->exec($sql);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        try {
            $idx = $this->db->query("SHOW INDEX FROM products WHERE Key_name = 'idx_products_ownership'")->fetch();
            if (!$idx) {
                $this->db->exec('ALTER TABLE products ADD INDEX idx_products_ownership (ownership)');
            }
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            $idx = $this->db->query("SHOW INDEX FROM products WHERE Key_name = 'idx_products_consignor'")->fetch();
            if (!$idx) {
                $this->db->exec('ALTER TABLE products ADD INDEX idx_products_consignor (consignor_id)');
            }
        } catch (\Throwable $e) {
            // ignore
        }

        foreach ([
            'is_consignment' => "ALTER TABLE sale_items ADD COLUMN is_consignment TINYINT(1) NOT NULL DEFAULT 0 AFTER unit_cost",
            'consignor_id' => "ALTER TABLE sale_items ADD COLUMN consignor_id INT UNSIGNED NULL AFTER is_consignment",
        ] as $col => $sql) {
            try {
                $exists = $this->db->query("SHOW COLUMNS FROM sale_items LIKE " . $this->db->quote($col))->fetch();
                if (!$exists) {
                    $this->db->exec($sql);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        try {
            $idx = $this->db->query("SHOW INDEX FROM consignors WHERE Key_name = 'idx_consignor_doc'")->fetch();
            if (!$idx) {
                $this->db->exec('ALTER TABLE consignors ADD INDEX idx_consignor_doc (document_type, document_number)');
            }
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            $exists = $this->db->query("SHOW COLUMNS FROM consignors LIKE 'supplier_id'")->fetch();
            if (!$exists) {
                $this->db->exec('ALTER TABLE consignors ADD COLUMN supplier_id INT UNSIGNED NULL AFTER id');
            }
            $idx = $this->db->query("SHOW INDEX FROM consignors WHERE Key_name = 'idx_consignor_supplier'")->fetch();
            if (!$idx) {
                $this->db->exec('ALTER TABLE consignors ADD UNIQUE INDEX idx_consignor_supplier (supplier_id)');
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /** SQL fragment: solo mercancía propia (inventario / valoración). */
    public static function ownedOnlySql(string $alias = 'p'): string
    {
        return "COALESCE({$alias}.ownership, 'owned') = 'owned'";
    }

    /** Propio + prestado a nosotros (excluye consignación de terceros). */
    public static function stockableOwnershipSql(string $alias = 'p'): string
    {
        return "COALESCE({$alias}.ownership, 'owned') IN ('owned','borrowed')";
    }

    public static function isBorrowedProduct(array $product): bool
    {
        return ($product['ownership'] ?? self::OWNERSHIP_OWNED) === self::OWNERSHIP_BORROWED;
    }

    public static function isConsignmentProduct(array $product): bool
    {
        return ($product['ownership'] ?? self::OWNERSHIP_OWNED) === self::OWNERSHIP_CONSIGNMENT;
    }

    public function listConsignors(bool $activeOnly = true): array
    {
        $sql = 'SELECT c.*, s.name AS supplier_name
                FROM consignors c
                LEFT JOIN suppliers s ON s.id = c.supplier_id';
        if ($activeOnly) {
            $sql .= " WHERE c.status = 'active'";
        }
        $sql .= ' ORDER BY c.name ASC';
        return $this->db->query($sql)->fetchAll() ?: [];
    }

    public function findConsignorBySupplier(int $supplierId): ?array
    {
        if ($supplierId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM consignors WHERE supplier_id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Obtiene o crea el consignante vinculado a un proveedor activo del catálogo.
     */
    public function ensureConsignorFromSupplier(int $supplierId): int
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Seleccione un proveedor');
        }
        $stmt = $this->db->prepare(
            "SELECT id, name, document_type, document_number, phone, email, address
             FROM suppliers
             WHERE id = ? AND status = 'active'
             LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch();
        if (!$supplier) {
            throw new \InvalidArgumentException('Proveedor no encontrado o inactivo');
        }

        $existing = $this->findConsignorBySupplier($supplierId);
        if ($existing) {
            if (($existing['status'] ?? '') !== 'active') {
                $this->db->prepare(
                    "UPDATE consignors
                     SET name = ?, document_type = ?, document_number = ?, phone = ?, email = ?, address = ?,
                         status = 'active', updated_at = NOW()
                     WHERE id = ?"
                )->execute([
                    (string)$supplier['name'],
                    $supplier['document_type'] ?? null,
                    $supplier['document_number'] ?? null,
                    $supplier['phone'] ?? null,
                    $supplier['email'] ?? null,
                    $supplier['address'] ?? null,
                    (int)$existing['id'],
                ]);
            }
            return (int)$existing['id'];
        }

        $stmt = $this->db->prepare(
            "INSERT INTO consignors
                (supplier_id, name, document_type, document_number, phone, email, address, notes, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')"
        );
        $stmt->execute([
            $supplierId,
            (string)$supplier['name'],
            $supplier['document_type'] ?? null,
            $supplier['document_number'] ?? null,
            $supplier['phone'] ?? null,
            $supplier['email'] ?? null,
            $supplier['address'] ?? null,
            'Vinculado desde proveedor #' . $supplierId,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * @param array{supplier_id?:int,consignor_id?:int} $data
     */
    public function resolveConsignorIdForProduct(array $data): int
    {
        $supplierId = (int)($data['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            return $this->ensureConsignorFromSupplier($supplierId);
        }
        $consignorId = (int)($data['consignor_id'] ?? 0);
        $consignor = $this->findConsignor($consignorId);
        if (!$consignor || ($consignor['status'] ?? '') !== 'active') {
            throw new \InvalidArgumentException('Seleccione un proveedor activo');
        }
        return $consignorId;
    }

    public function findConsignor(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM consignors WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function saveConsignor(array $data, ?int $id = null): int
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre del consignante es obligatorio');
        }
        $fields = [
            $name,
            trim((string)($data['document_type'] ?? '')) ?: null,
            trim((string)($data['document_number'] ?? '')) ?: null,
            trim((string)($data['phone'] ?? '')) ?: null,
            trim((string)($data['email'] ?? '')) ?: null,
            trim((string)($data['address'] ?? '')) ?: null,
            trim((string)($data['notes'] ?? '')) ?: null,
            in_array(($data['status'] ?? 'active'), ['active', 'inactive'], true)
                ? $data['status']
                : 'active',
        ];
        $docType = $fields[1];
        $docNumber = $fields[2];
        if ($docType !== null && $docNumber !== null && $docNumber !== '') {
            $dupSql = 'SELECT id FROM consignors WHERE document_type = ? AND document_number = ?';
            $dupParams = [$docType, $docNumber];
            if ($id && $id > 0) {
                $dupSql .= ' AND id <> ?';
                $dupParams[] = $id;
            }
            $dupSql .= ' LIMIT 1';
            $dup = $this->db->prepare($dupSql);
            $dup->execute($dupParams);
            if ($dup->fetch()) {
                throw new \InvalidArgumentException('Ya existe un consignante con ese documento');
            }
        }

        $supplierId = (int)($data['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            $dupSupplier = $this->db->prepare(
                'SELECT id FROM consignors WHERE supplier_id = ?' . ($id && $id > 0 ? ' AND id <> ?' : '') . ' LIMIT 1'
            );
            $dupSupplier->execute($id && $id > 0 ? [$supplierId, $id] : [$supplierId]);
            if ($dupSupplier->fetch()) {
                throw new \InvalidArgumentException('Ese proveedor ya está vinculado a otro consignante');
            }
        } else {
            $supplierId = null;
        }

        if ($id && $id > 0) {
            $stmt = $this->db->prepare(
                "UPDATE consignors
                 SET supplier_id=?, name=?, document_type=?, document_number=?, phone=?, email=?, address=?, notes=?, status=?, updated_at=NOW()
                 WHERE id=?"
            );
            $stmt->execute([$supplierId, ...$fields, $id]);
            return $id;
        }
        $stmt = $this->db->prepare(
            "INSERT INTO consignors (supplier_id, name, document_type, document_number, phone, email, address, notes, status)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([$supplierId, ...$fields]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Alta de producto consignado (no entra al catálogo de inventario propio).
     */
    public function createProduct(array $data): int
    {
        $consignorId = $this->resolveConsignorIdForProduct($data);
        $name = trim((string)($data['name'] ?? ''));
        $code = trim((string)($data['code'] ?? ''));
        if ($name === '' || $code === '') {
            throw new \InvalidArgumentException('Código y nombre son obligatorios');
        }
        $dup = $this->db->prepare('SELECT id FROM products WHERE code = ? LIMIT 1');
        $dup->execute([$code]);
        if ($dup->fetch()) {
            throw new \InvalidArgumentException('Ya existe un producto con ese código/SKU');
        }
        $barcode = trim((string)($data['barcode'] ?? ''));
        if ($barcode !== '') {
            $dupB = $this->db->prepare('SELECT id FROM products WHERE barcode = ? LIMIT 1');
            $dupB->execute([$barcode]);
            if ($dupB->fetch()) {
                throw new \InvalidArgumentException('Ya existe un producto con ese código de barras');
            }
        }
        $unitCost = round((float)($data['purchase_price'] ?? $data['unit_cost'] ?? 0), 2);
        $salePrice = round((float)($data['sale_price'] ?? 0), 2);
        if ($salePrice < 0 || $unitCost < 0) {
            throw new \InvalidArgumentException('Costos y precios no pueden ser negativos');
        }
        TaxService::ensureSchema($this->db);
        // 0 es exento válido; solo vacío/null usa la tasa de Configuración (igual que inventario).
        if (array_key_exists('tax_rate', $data) && $data['tax_rate'] !== null && trim((string)$data['tax_rate']) !== '') {
            $taxRate = TaxService::normalizeRate((float)$data['tax_rate']);
        } else {
            $taxRate = TaxService::defaultRate($this->db);
        }
        $initialQty = max(0, (int)($data['initial_qty'] ?? 0));

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO products
                    (code, barcode, name, description, product_type, ownership, consignor_id,
                     purchase_price, sale_price, tax_rate, stock, min_stock, location, status, notes, created_at)
                 VALUES (?, ?, ?, ?, 'product', 'consignment', ?, ?, ?, ?, 0, 0, ?, 'active', ?, NOW())"
            );
            $stmt->execute([
                $code,
                $barcode !== '' ? $barcode : null,
                $name,
                trim((string)($data['description'] ?? '')) ?: null,
                $consignorId,
                $unitCost,
                $salePrice,
                $taxRate,
                self::LOCATION_LABEL,
                trim((string)($data['notes'] ?? '')) ?: null,
            ]);
            $productId = (int)$this->db->lastInsertId();

            if ($initialQty > 0) {
                $this->receiveStock([
                    'consignor_id' => $consignorId,
                    'receipt_date' => (string)($data['receipt_date'] ?? date('Y-m-d')),
                    'notes' => 'Stock inicial consignación',
                    'items' => [[
                        'product_id' => $productId,
                        'quantity' => $initialQty,
                        'unit_cost' => $unitCost,
                    ]],
                ], (int)($data['user_id'] ?? 0));
            }

            if ($ownTx) {
                if ($this->db->inTransaction()) {
                    $this->db->commit();
                }
            }
            return $productId;
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function updateProduct(int $productId, array $data): void
    {
        $product = $this->getProduct($productId);
        if (!$product || !self::isConsignmentProduct($product)) {
            throw new \InvalidArgumentException('Producto de consignación no encontrado');
        }
        $name = trim((string)($data['name'] ?? $product['name']));
        $salePrice = round((float)($data['sale_price'] ?? $product['sale_price']), 2);
        $unitCost = round((float)($data['purchase_price'] ?? $product['purchase_price']), 2);
        $barcode = trim((string)($data['barcode'] ?? ($product['barcode'] ?? '')));
        TaxService::ensureSchema($this->db);
        if (array_key_exists('tax_rate', $data) && $data['tax_rate'] !== null && trim((string)$data['tax_rate']) !== '') {
            $taxRate = TaxService::normalizeRate((float)$data['tax_rate']);
        } else {
            $taxRate = TaxService::normalizeRate((float)($product['tax_rate'] ?? 0));
        }
        $status = in_array(($data['status'] ?? $product['status']), ['active', 'inactive'], true)
            ? ($data['status'] ?? $product['status'])
            : 'active';
        $userId = (int)($data['user_id'] ?? 0);
        if (array_key_exists('stock', $data) && $data['stock'] !== null && $data['stock'] !== '') {
            $this->adjustStock($productId, max(0, (int)$data['stock']), $userId, 'Edición producto consignado');
        }
        if ($barcode !== '') {
            $dupB = $this->db->prepare('SELECT id FROM products WHERE barcode = ? AND id <> ? LIMIT 1');
            $dupB->execute([$barcode, $productId]);
            if ($dupB->fetch()) {
                throw new \InvalidArgumentException('Código de barras ya usado');
            }
        }
        $stmt = $this->db->prepare(
            "UPDATE products
             SET name=?, barcode=?, sale_price=?, purchase_price=?, tax_rate=?, notes=?, status=?, location=?
             WHERE id=? AND ownership='consignment'"
        );
        $stmt->execute([
            $name,
            $barcode !== '' ? $barcode : null,
            $salePrice,
            $unitCost,
            $taxRate,
            trim((string)($data['notes'] ?? ($product['notes'] ?? ''))) ?: null,
            $status,
            self::LOCATION_LABEL,
            $productId,
        ]);
    }

    /**
     * Ajusta stock consignado (ingreso o devolución según la diferencia).
     */
    public function adjustStock(int $productId, int $newStock, int $userId = 0, string $notes = ''): void
    {
        $product = $this->getProduct($productId);
        if (!$product || !self::isConsignmentProduct($product)) {
            throw new \InvalidArgumentException('Producto de consignación no encontrado');
        }
        if ($newStock < 0) {
            throw new \InvalidArgumentException('El stock no puede ser negativo');
        }
        $current = (int)$product['stock'];
        if ($newStock === $current) {
            return;
        }
        $consignorId = (int)($product['consignor_id'] ?? 0);
        if ($consignorId <= 0) {
            throw new \InvalidArgumentException('Producto sin consignante asignado');
        }
        $diff = $newStock - $current;
        if ($diff > 0) {
            $this->receiveStock([
                'consignor_id' => $consignorId,
                'receipt_date' => date('Y-m-d'),
                'notes' => $notes !== '' ? 'Ajuste stock: ' . $notes : 'Ajuste de stock consignación',
                'items' => [[
                    'product_id' => $productId,
                    'quantity' => $diff,
                    'unit_cost' => (float)($product['purchase_price'] ?? 0),
                ]],
            ], $userId);
            return;
        }
        $this->returnToConsignor($productId, -$diff, $notes !== '' ? $notes : 'Ajuste de stock', $userId);
    }

    /**
     * Desactiva un producto consignado (soft-delete). Requiere stock 0 y sin deudas abiertas.
     */
    public function deleteProduct(int $productId): void
    {
        $product = $this->getProduct($productId);
        if (!$product || !self::isConsignmentProduct($product)) {
            throw new \InvalidArgumentException('Producto de consignación no encontrado');
        }
        if ((int)$product['stock'] > 0) {
            throw new \InvalidArgumentException(
                'Deje el stock en 0 antes de eliminar (use Editar stock o Devolver)'
            );
        }
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM consignment_sale_debts
             WHERE product_id = ? AND status IN ('open','partial') AND (qty - qty_settled) > 0"
        );
        $stmt->execute([$productId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new \InvalidArgumentException(
                'No se puede eliminar: hay unidades vendidas pendientes de liquidar'
            );
        }
        $this->db->prepare(
            "UPDATE products SET status = 'inactive' WHERE id = ? AND ownership = 'consignment'"
        )->execute([$productId]);
    }

    public function getProduct(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT p.*, c.name AS consignor_name
             FROM products p
             LEFT JOIN consignors c ON c.id = p.consignor_id
             WHERE p.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listProducts(
        ?int $consignorId = null,
        string $q = '',
        ?int $supplierId = null,
        bool $includeInactive = false
    ): array {
        $where = ["p.ownership = 'consignment'", "p.product_type = 'product'"];
        $params = [];
        if (!$includeInactive) {
            $where[] = "p.status = 'active'";
        }
        if ($supplierId && $supplierId > 0) {
            $where[] = 'c.supplier_id = ?';
            $params[] = $supplierId;
        } elseif ($consignorId && $consignorId > 0) {
            $where[] = 'p.consignor_id = ?';
            $params[] = $consignorId;
        }
        if ($q !== '') {
            $where[] = '(p.name LIKE ? OR p.code LIKE ? OR COALESCE(p.barcode,\'\') LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }
        $sql = 'SELECT p.*, c.name AS consignor_name
                FROM products p
                LEFT JOIN consignors c ON c.id = p.consignor_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY p.name ASC
                LIMIT 500';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Ingreso de mercancía consignada (bodega virtual): sube stock sin asiento de compra.
     *
     * @param array{consignor_id:int,receipt_date?:string,notes?:string,items:list<array{product_id:int,quantity:int,unit_cost?:float}>} $payload
     */
    public function receiveStock(array $payload, int $userId = 0): int
    {
        $consignorId = (int)($payload['consignor_id'] ?? 0);
        $consignor = $this->findConsignor($consignorId);
        if (!$consignor) {
            throw new \InvalidArgumentException('Consignante inválido');
        }
        $items = $payload['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new \InvalidArgumentException('Agregue al menos un producto');
        }
        $date = (string)($payload['receipt_date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $number = 'CSG-' . date('Ymd') . '-' . substr((string)time(), -4);
            $stmt = $this->db->prepare(
                "INSERT INTO consignment_receipts (consignor_id, receipt_number, receipt_date, notes, user_id, status)
                 VALUES (?, ?, ?, ?, ?, 'closed')"
            );
            $stmt->execute([
                $consignorId,
                $number,
                $date,
                trim((string)($payload['notes'] ?? '')) ?: null,
                $userId > 0 ? $userId : null,
            ]);
            $receiptId = (int)$this->db->lastInsertId();

            foreach ($items as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $qty = (int)($item['quantity'] ?? 0);
                if ($productId <= 0 || $qty <= 0) {
                    throw new \InvalidArgumentException('Cantidad inválida en la remesa');
                }
                $product = $this->getProduct($productId);
                if (!$product || !self::isConsignmentProduct($product)) {
                    throw new \InvalidArgumentException('Solo se reciben productos de consignación');
                }
                if ((int)($product['consignor_id'] ?? 0) !== $consignorId) {
                    throw new \InvalidArgumentException('El producto pertenece a otro consignante');
                }
                $unitCost = round((float)($item['unit_cost'] ?? $product['purchase_price'] ?? 0), 2);
                if ($unitCost > 0 && abs($unitCost - (float)$product['purchase_price']) > 0.009) {
                    $upd = $this->db->prepare('UPDATE products SET purchase_price = ? WHERE id = ?');
                    $upd->execute([$unitCost, $productId]);
                }
                $debtCover = null;
                $movementId = $this->stock->increase(
                    $productId,
                    $qty,
                    'consignment_in',
                    $receiptId,
                    'Ingreso consignación ' . $number . ' · ' . $consignor['name'],
                    'in',
                    $unitCost,
                    null,
                    null,
                    false,
                    $date,
                    null,
                    $debtCover,
                    false
                );
                $itemStmt = $this->db->prepare(
                    "INSERT INTO consignment_receipt_items
                        (receipt_id, product_id, quantity, unit_cost, stock_movement_id)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $itemStmt->execute([$receiptId, $productId, $qty, $unitCost, $movementId]);
            }

            if ($ownTx) {
                if ($this->db->inTransaction()) {
                    $this->db->commit();
                }
            }
            return $receiptId;
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Devolución física de unidades no vendidas al consignante.
     */
    public function returnToConsignor(int $productId, int $qty, string $notes = '', int $userId = 0): int
    {
        $product = $this->getProduct($productId);
        if (!$product || !self::isConsignmentProduct($product)) {
            throw new \InvalidArgumentException('Producto de consignación no encontrado');
        }
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Cantidad inválida');
        }
        $stock = (int)$product['stock'];
        if ($qty > $stock) {
            throw new \InvalidArgumentException("Stock consignado insuficiente (disponible: {$stock})");
        }
        $unitCost = (float)$product['purchase_price'];
        return $this->stock->decrease(
            $productId,
            $qty,
            'consignment_return',
            (int)$product['consignor_id'],
            'Devolución a consignante' . ($notes !== '' ? ': ' . $notes : ''),
            $unitCost,
            null
        );
    }

    /**
     * Registra deuda al consignante tras una venta (llamar dentro de la tx de venta).
     */
    public function registerSaleDebt(
        int $saleId,
        int $saleItemId,
        int $productId,
        int $qty,
        float $unitCost,
        ?int $consignorId = null
    ): void {
        if ($qty <= 0) {
            return;
        }
        $product = $this->getProduct($productId);
        if (!$product || !self::isConsignmentProduct($product)) {
            return;
        }
        $cid = $consignorId ?: (int)($product['consignor_id'] ?? 0);
        if ($cid <= 0) {
            throw new \RuntimeException('Producto consignado sin consignante asignado');
        }
        if ($saleItemId > 0) {
            $exists = $this->db->prepare(
                'SELECT id FROM consignment_sale_debts WHERE sale_item_id = ? LIMIT 1'
            );
            $exists->execute([$saleItemId]);
            if ($exists->fetch()) {
                try {
                    $this->db->prepare(
                        'UPDATE sale_items SET is_consignment = 1, consignor_id = ? WHERE id = ?'
                    )->execute([$cid, $saleItemId]);
                } catch (\Throwable $e) {
                    // ignore
                }
                return;
            }
        }
        $cost = $unitCost > 0 ? $unitCost : (float)($product['purchase_price'] ?? 0);
        $stmt = $this->db->prepare(
            "INSERT INTO consignment_sale_debts
                (sale_id, sale_item_id, product_id, consignor_id, qty, qty_settled, unit_cost, status)
             VALUES (?, ?, ?, ?, ?, 0, ?, 'open')"
        );
        $stmt->execute([$saleId, $saleItemId > 0 ? $saleItemId : null, $productId, $cid, $qty, $cost]);

        try {
            $this->db->prepare(
                'UPDATE sale_items SET is_consignment = 1, consignor_id = ? WHERE id = ?'
            )->execute([$cid, $saleItemId]);
        } catch (\Throwable $e) {
            // columnas pueden faltar en installs muy antiguos; ensureSchema ya las crea
        }
    }

    /**
     * Impide anular una venta si ya se liquidó (parcial o total) al consignante.
     */
    public function assertSaleCancellable(int $saleId): void
    {
        if ($saleId <= 0) {
            return;
        }
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM consignment_sale_debts
             WHERE sale_id = ? AND qty_settled > 0 AND status <> 'void'"
        );
        $stmt->execute([$saleId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new \RuntimeException(
                'No se puede anular: hay unidades consignadas ya liquidadas al consignante. '
                . 'Use nota de crédito solo sobre lo pendiente o ajuste la liquidación primero.'
            );
        }
    }

    public function hasOpenConsignmentDebt(int $saleId): bool
    {
        if ($saleId <= 0) {
            return false;
        }
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM consignment_sale_debts
             WHERE sale_id = ? AND status IN ('open','partial') AND (qty - qty_settled) > 0"
        );
        $stmt->execute([$saleId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Reduce deuda operativa al emitir NC (solo sobre lo aún no liquidado).
     */
    public function reduceDebtForCreditNote(int $saleItemId, int $qty): void
    {
        if ($saleItemId <= 0 || $qty <= 0) {
            return;
        }
        $stmt = $this->db->prepare(
            "SELECT * FROM consignment_sale_debts
             WHERE sale_item_id = ? AND status IN ('open','partial','settled')
             LIMIT 1 FOR UPDATE"
        );
        try {
            $stmt->execute([$saleItemId]);
        } catch (\Throwable $e) {
            $stmt = $this->db->prepare(
                "SELECT * FROM consignment_sale_debts
                 WHERE sale_item_id = ? AND status IN ('open','partial','settled')
                 LIMIT 1"
            );
            $stmt->execute([$saleItemId]);
        }
        $debt = $stmt->fetch();
        if (!$debt) {
            // Fallback: deuda ligada solo por sale_id + product (installs viejas)
            return;
        }
        $pending = (int)$debt['qty'] - (int)$debt['qty_settled'];
        if ($qty > $pending) {
            throw new \RuntimeException(
                'No se puede devolver consignación ya liquidada al consignante '
                . '(pendiente: ' . max(0, $pending) . ' uds).'
            );
        }
        $newQty = (int)$debt['qty'] - $qty;
        if ($newQty <= 0) {
            $this->db->prepare(
                "UPDATE consignment_sale_debts
                 SET qty = 0, status = 'void', updated_at = NOW() WHERE id = ?"
            )->execute([(int)$debt['id']]);
            return;
        }
        $status = (int)$debt['qty_settled'] >= $newQty
            ? 'settled'
            : ((int)$debt['qty_settled'] > 0 ? 'partial' : 'open');
        $this->db->prepare(
            "UPDATE consignment_sale_debts
             SET qty = ?, status = ?, updated_at = NOW() WHERE id = ?"
        )->execute([$newQty, $status, (int)$debt['id']]);
    }

    public function voidSaleDebts(int $saleId): void
    {
        $this->assertSaleCancellable($saleId);
        $stmt = $this->db->prepare(
            "UPDATE consignment_sale_debts
             SET status = 'void', updated_at = NOW()
             WHERE sale_id = ? AND status IN ('open','partial')"
        );
        $stmt->execute([$saleId]);
    }

    public function listOpenDebts(?int $consignorId = null): array
    {
        $where = ["d.status IN ('open','partial')", '(d.qty - d.qty_settled) > 0'];
        $params = [];
        if ($consignorId && $consignorId > 0) {
            $where[] = 'd.consignor_id = ?';
            $params[] = $consignorId;
        }
        $sql = "SELECT d.*, p.name product_name, p.code product_code,
                       c.name consignor_name, s.invoice_number, s.sale_date,
                       (d.qty - d.qty_settled) AS qty_pending,
                       ROUND((d.qty - d.qty_settled) * d.unit_cost, 2) AS amount_pending
                FROM consignment_sale_debts d
                JOIN products p ON p.id = d.product_id
                JOIN consignors c ON c.id = d.consignor_id
                LEFT JOIN sales s ON s.id = d.sale_id
                WHERE " . implode(' AND ', $where) . '
                ORDER BY d.created_at ASC
                LIMIT 500';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    public function dashboardStats(): array
    {
        $products = (int)$this->db->query(
            "SELECT COUNT(*) FROM products WHERE ownership='consignment' AND status='active'"
        )->fetchColumn();
        $units = (int)$this->db->query(
            "SELECT COALESCE(SUM(GREATEST(stock,0)),0) FROM products WHERE ownership='consignment' AND status='active'"
        )->fetchColumn();
        $stockValue = (float)$this->db->query(
            "SELECT COALESCE(SUM(GREATEST(stock,0) * COALESCE(purchase_price,0)),0)
             FROM products WHERE ownership='consignment' AND status='active'"
        )->fetchColumn();
        $pending = $this->db->query(
            "SELECT COALESCE(SUM((qty - qty_settled) * unit_cost),0) AS amount,
                    COALESCE(SUM(qty - qty_settled),0) AS units
             FROM consignment_sale_debts
             WHERE status IN ('open','partial') AND (qty - qty_settled) > 0"
        )->fetch() ?: ['amount' => 0, 'units' => 0];
        $consignors = (int)$this->db->query(
            "SELECT COUNT(*) FROM consignors WHERE status='active'"
        )->fetchColumn();

        return [
            'products' => $products,
            'units_on_hand' => $units,
            'stock_value_memo' => round($stockValue, 2),
            'payable_amount' => round((float)($pending['amount'] ?? 0), 2),
            'payable_units' => (int)($pending['units'] ?? 0),
            'consignors' => $consignors,
        ];
    }

    /**
     * Liquida deudas abiertas (pago al consignante por lo vendido).
     *
     * @param list<array{debt_id:int,qty:int}> $items
     * @return array{settlement_id:int,amount:float}
     */
    public function settle(int $consignorId, array $items, string $paymentMethod, string $notes, int $userId, string $date = ''): array
    {
        $consignor = $this->findConsignor($consignorId);
        if (!$consignor) {
            throw new \InvalidArgumentException('Consignante inválido');
        }
        if ($items === []) {
            throw new \InvalidArgumentException('Seleccione líneas a liquidar');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $paymentMethod = PaymentMethodCatalog::normalize($paymentMethod) ?: 'cash';

        // DDL contable antes de la TX (MySQL hace COMMIT implícito en CREATE/ALTER).
        AccountingService::warm($this->db);

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $total = 0.0;
            $resolved = [];
            foreach ($items as $row) {
                $debtId = (int)($row['debt_id'] ?? 0);
                $qty = (int)($row['qty'] ?? 0);
                if ($debtId <= 0 || $qty <= 0) {
                    continue;
                }
                $stmt = $this->db->prepare(
                    "SELECT * FROM consignment_sale_debts WHERE id = ? FOR UPDATE"
                );
                $stmt->execute([$debtId]);
                $debt = $stmt->fetch();
                if (!$debt || (int)$debt['consignor_id'] !== $consignorId) {
                    throw new \InvalidArgumentException('Deuda de consignación inválida');
                }
                if (!in_array($debt['status'], ['open', 'partial'], true)) {
                    throw new \InvalidArgumentException('La línea ya está liquidada o anulada');
                }
                $pending = (int)$debt['qty'] - (int)$debt['qty_settled'];
                if ($qty > $pending) {
                    throw new \InvalidArgumentException('Cantidad a liquidar supera lo pendiente');
                }
                $amount = round($qty * (float)$debt['unit_cost'], 2);
                $total += $amount;
                $resolved[] = ['debt' => $debt, 'qty' => $qty, 'amount' => $amount];
            }
            if ($resolved === [] || $total <= 0) {
                throw new \InvalidArgumentException('No hay montos a liquidar');
            }

            $ins = $this->db->prepare(
                "INSERT INTO consignment_settlements
                    (consignor_id, settlement_date, amount, payment_method, notes, user_id)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $ins->execute([
                $consignorId,
                $date,
                round($total, 2),
                $paymentMethod,
                trim($notes) !== '' ? trim($notes) : null,
                $userId > 0 ? $userId : null,
            ]);
            $settlementId = (int)$this->db->lastInsertId();

            $itemIns = $this->db->prepare(
                "INSERT INTO consignment_settlement_items (settlement_id, debt_id, qty, amount)
                 VALUES (?, ?, ?, ?)"
            );
            foreach ($resolved as $r) {
                $itemIns->execute([$settlementId, (int)$r['debt']['id'], $r['qty'], $r['amount']]);
                $newSettled = (int)$r['debt']['qty_settled'] + $r['qty'];
                $status = $newSettled >= (int)$r['debt']['qty'] ? 'settled' : 'partial';
                $this->db->prepare(
                    "UPDATE consignment_sale_debts
                     SET qty_settled = ?, status = ?, updated_at = NOW()
                     WHERE id = ?"
                )->execute([$newSettled, $status, (int)$r['debt']['id']]);
            }

            // Efectivo: exige caja abierta y falla la TX si no se registra el egreso.
            if (PaymentMethodCatalog::affectsCash($paymentMethod)) {
                $cash = new CashService($this->db);
                if (!$cash->getOpenSession()) {
                    throw new \RuntimeException(
                        'Debe abrir caja para liquidar consignación en efectivo'
                    );
                }
                $cashOk = $cash->registerMovement(
                    round($total, 2),
                    'Liquidación consignación · ' . $consignor['name'],
                    'expense',
                    'consignment_settlement',
                    $settlementId
                );
                if (!$cashOk) {
                    throw new \RuntimeException(
                        'No se pudo registrar el egreso en caja; la liquidación se canceló'
                    );
                }
            }

            $accounting = new AccountingService($this->db);
            $entryId = $accounting->postConsignmentSettlement($settlementId);
            if (!$entryId) {
                throw new \RuntimeException(
                    'No se pudo contabilizar la liquidación; se canceló el pago'
                );
            }
            $this->db->prepare(
                'UPDATE consignment_settlements SET accounting_entry_id = ? WHERE id = ?'
            )->execute([$entryId, $settlementId]);

            if ($ownTx) {
                if ($this->db->inTransaction()) {
                    $this->db->commit();
                }
            }
            return ['settlement_id' => $settlementId, 'amount' => round($total, 2)];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function listReceipts(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return $this->db->query(
            "SELECT r.*, c.name consignor_name,
                    (SELECT COALESCE(SUM(quantity),0) FROM consignment_receipt_items i WHERE i.receipt_id = r.id) AS total_units
             FROM consignment_receipts r
             JOIN consignors c ON c.id = r.consignor_id
             ORDER BY r.id DESC
             LIMIT {$limit}"
        )->fetchAll() ?: [];
    }

    public function listSettlements(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return $this->db->query(
            "SELECT s.*, c.name consignor_name
             FROM consignment_settlements s
             JOIN consignors c ON c.id = s.consignor_id
             ORDER BY s.id DESC
             LIMIT {$limit}"
        )->fetchAll() ?: [];
    }

    public const STATUS_LABELS = ['active' => 'Activo', 'inactive' => 'Inactivo'];

    public const STOCK_STATE_LABELS = [
        'with_stock' => 'Con stock',
        'no_stock' => 'Sin stock',
        'pending' => 'Vendidos por liquidar',
        'sold' => 'Con ventas',
        'unsold' => 'Sin ventas',
    ];

    public const SORT_LABELS = [
        'name' => 'Nombre A-Z',
        'consignor' => 'Consignante',
        'stock_desc' => 'Mayor stock',
        'sold_desc' => 'Más vendidos',
        'profit_desc' => 'Mayor utilidad',
        'margin_desc' => 'Mayor margen %',
        'pending_desc' => 'Mayor saldo por liquidar',
    ];

    public const TRACE_TYPE_LABELS = [
        'consignment_in' => 'Ingreso remesa',
        'sale' => 'Venta',
        'return' => 'Anulación de venta',
        'sale_credit_note' => 'Nota crédito',
        'consignment_return' => 'Devolución a consignante',
        'adjustment' => 'Ajuste',
        'settlement' => 'Liquidación pagada',
    ];

    private ?bool $saleItemsHasDiscount = null;

    /** Ingreso de la línea consignada (con IVA, neto de descuento) prorrateado a la qty vigente de la deuda. */
    private function debtRevenueSql(string $d = 'd', string $si = 'si', string $p = 'pp'): string
    {
        if ($this->saleItemsHasDiscount === null) {
            try {
                $this->saleItemsHasDiscount = (bool)$this->db->query("SHOW COLUMNS FROM sale_items LIKE 'discount_amount'")->fetch();
            } catch (\Throwable $e) {
                $this->saleItemsHasDiscount = false;
            }
        }
        $lineNet = $this->saleItemsHasDiscount
            ? "({$si}.subtotal - COALESCE({$si}.discount_amount, 0))"
            : "{$si}.subtotal";
        return "CASE WHEN {$si}.id IS NOT NULL AND {$si}.quantity > 0
                     THEN {$lineNet} * {$d}.qty / {$si}.quantity
                     ELSE {$d}.qty * COALESCE({$p}.sale_price, 0) END";
    }

    /**
     * Normaliza filtros del catálogo consignado.
     *
     * @return array{consignor_id:int,supplier_id:int,q:string,status:string,stock_state:string,sort:string}
     */
    public static function normalizeProductFilters(array $raw): array
    {
        $status = (string)($raw['status'] ?? 'active');
        if (!in_array($status, ['active', 'inactive', 'all'], true)) {
            $status = 'active';
        }
        $stockState = (string)($raw['stock_state'] ?? '');
        if (!isset(self::STOCK_STATE_LABELS[$stockState])) {
            $stockState = '';
        }
        $sort = (string)($raw['sort'] ?? 'name');
        if (!isset(self::SORT_LABELS[$sort])) {
            $sort = 'name';
        }
        return [
            'consignor_id' => max(0, (int)($raw['consignor_id'] ?? 0)),
            'supplier_id' => max(0, (int)($raw['supplier_id'] ?? 0)),
            'q' => trim((string)($raw['q'] ?? '')),
            'status' => $status,
            'stock_state' => $stockState,
            'sort' => $sort,
        ];
    }

    /**
     * Catálogo consignado con métricas de venta acumuladas (vendido, ingreso, costo pactado, utilidad, por liquidar).
     */
    public function listProductsDetailed(array $filters, int $limit = 1000): array
    {
        $f = self::normalizeProductFilters($filters);
        $where = ["p.ownership = 'consignment'", "p.product_type = 'product'"];
        $params = [];
        if ($f['status'] !== 'all') {
            $where[] = 'p.status = ?';
            $params[] = $f['status'];
        }
        if ($f['supplier_id'] > 0) {
            $where[] = 'c.supplier_id = ?';
            $params[] = $f['supplier_id'];
        } elseif ($f['consignor_id'] > 0) {
            $where[] = 'p.consignor_id = ?';
            $params[] = $f['consignor_id'];
        }
        if ($f['q'] !== '') {
            $search = \SoftNova\Core\multi_term_like_clause($f['q'], [
                'p.name',
                'p.code',
                "COALESCE(p.barcode,'')",
                "COALESCE(c.name,'')",
            ]);
            $where[] = $search['sql'];
            foreach ($search['params'] as $sp) {
                $params[] = $sp;
            }
        }
        switch ($f['stock_state']) {
            case 'with_stock':
                $where[] = 'p.stock > 0';
                break;
            case 'no_stock':
                $where[] = 'p.stock <= 0';
                break;
            case 'pending':
                $where[] = 'COALESCE(ds.units_pending, 0) > 0';
                break;
            case 'sold':
                $where[] = 'COALESCE(ds.units_sold, 0) > 0';
                break;
            case 'unsold':
                $where[] = 'COALESCE(ds.units_sold, 0) = 0';
                break;
        }
        $order = match ($f['sort']) {
            'consignor' => 'c.name ASC, p.name ASC',
            'stock_desc' => 'p.stock DESC, p.name ASC',
            'sold_desc' => 'units_sold DESC, p.name ASC',
            'profit_desc' => '(COALESCE(ds.revenue,0) - COALESCE(ds.cost_sold,0)) DESC, p.name ASC',
            'margin_desc' => 'CASE WHEN COALESCE(ds.revenue,0) > 0 THEN (ds.revenue - ds.cost_sold) / ds.revenue ELSE -1 END DESC, p.name ASC',
            'pending_desc' => 'amount_pending DESC, p.name ASC',
            default => 'p.name ASC',
        };
        $rev = $this->debtRevenueSql();
        $limit = max(1, min(5000, $limit));
        $sql = "SELECT p.*, c.name AS consignor_name, c.supplier_id AS consignor_supplier_id,
                       COALESCE(ds.units_sold, 0) AS units_sold,
                       COALESCE(ds.revenue, 0) AS revenue,
                       COALESCE(ds.cost_sold, 0) AS cost_sold,
                       COALESCE(ds.units_pending, 0) AS units_pending,
                       COALESCE(ds.amount_pending, 0) AS amount_pending,
                       COALESCE(ds.units_settled, 0) AS units_settled,
                       ds.last_sale_date
                FROM products p
                LEFT JOIN consignors c ON c.id = p.consignor_id
                LEFT JOIN (
                    SELECT d.product_id,
                           SUM(d.qty) AS units_sold,
                           SUM({$rev}) AS revenue,
                           SUM(d.qty * d.unit_cost) AS cost_sold,
                           SUM(CASE WHEN d.status IN ('open','partial') THEN d.qty - d.qty_settled ELSE 0 END) AS units_pending,
                           SUM(CASE WHEN d.status IN ('open','partial') THEN (d.qty - d.qty_settled) * d.unit_cost ELSE 0 END) AS amount_pending,
                           SUM(d.qty_settled) AS units_settled,
                           MAX(s.sale_date) AS last_sale_date
                    FROM consignment_sale_debts d
                    LEFT JOIN sale_items si ON si.id = d.sale_item_id
                    LEFT JOIN sales s ON s.id = d.sale_id
                    LEFT JOIN products pp ON pp.id = d.product_id
                    WHERE d.status <> 'void' AND d.qty > 0 AND COALESCE(s.status, '') <> 'cancelled'
                    GROUP BY d.product_id
                ) ds ON ds.product_id = p.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$order}
                LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as &$r) {
            $r = self::withMarginFields($r);
        }
        unset($r);
        return $rows;
    }

    /** Agrega utilidad, margen realizado y margen unitario (precio vs costo pactado). */
    private static function withMarginFields(array $r): array
    {
        $revenue = round((float)($r['revenue'] ?? 0), 2);
        $cost = round((float)($r['cost_sold'] ?? 0), 2);
        $r['revenue'] = $revenue;
        $r['cost_sold'] = $cost;
        $r['amount_pending'] = round((float)($r['amount_pending'] ?? 0), 2);
        $r['profit'] = round($revenue - $cost, 2);
        $r['margin_pct'] = $revenue > 0.009 ? round(($revenue - $cost) / $revenue * 100, 1) : null;
        if (array_key_exists('sale_price', $r)) {
            $price = (float)($r['sale_price'] ?? 0);
            $unitCost = (float)($r['purchase_price'] ?? 0);
            $r['unit_margin'] = round($price - $unitCost, 2);
            $r['unit_margin_pct'] = $price > 0.009 ? round(($price - $unitCost) / $price * 100, 1) : null;
            $r['stock_value_cost'] = round(max(0, (int)($r['stock'] ?? 0)) * $unitCost, 2);
            $r['stock_value_sale'] = round(max(0, (int)($r['stock'] ?? 0)) * $price, 2);
        }
        return $r;
    }

    /**
     * Agrupa productos detallados por consignante con subtotales y datos de liquidaciones pagadas.
     *
     * @return list<array<string,mixed>>
     */
    public function groupByConsignor(array $products): array
    {
        $settled = [];
        try {
            foreach ($this->db->query(
                'SELECT consignor_id, COUNT(*) AS n, COALESCE(SUM(amount),0) AS amount, MAX(settlement_date) AS last_date
                 FROM consignment_settlements GROUP BY consignor_id'
            )->fetchAll() ?: [] as $s) {
                $settled[(int)$s['consignor_id']] = $s;
            }
        } catch (\Throwable $e) {
            // tabla vacía o inexistente en installs viejas
        }

        $groups = [];
        foreach ($products as $p) {
            $cid = (int)($p['consignor_id'] ?? 0);
            if (!isset($groups[$cid])) {
                $groups[$cid] = [
                    'consignor_id' => $cid,
                    'consignor_name' => (string)($p['consignor_name'] ?? 'Sin consignante'),
                    'products' => [],
                    'product_count' => 0,
                    'stock' => 0,
                    'stock_value_cost' => 0.0,
                    'stock_value_sale' => 0.0,
                    'units_sold' => 0,
                    'revenue' => 0.0,
                    'cost_sold' => 0.0,
                    'units_pending' => 0,
                    'amount_pending' => 0.0,
                    'settled_count' => (int)($settled[$cid]['n'] ?? 0),
                    'settled_amount' => round((float)($settled[$cid]['amount'] ?? 0), 2),
                    'last_settlement' => $settled[$cid]['last_date'] ?? null,
                ];
            }
            $g = &$groups[$cid];
            $g['products'][] = $p;
            $g['product_count']++;
            $g['stock'] += max(0, (int)($p['stock'] ?? 0));
            $g['stock_value_cost'] += (float)($p['stock_value_cost'] ?? 0);
            $g['stock_value_sale'] += (float)($p['stock_value_sale'] ?? 0);
            $g['units_sold'] += (int)($p['units_sold'] ?? 0);
            $g['revenue'] += (float)($p['revenue'] ?? 0);
            $g['cost_sold'] += (float)($p['cost_sold'] ?? 0);
            $g['units_pending'] += (int)($p['units_pending'] ?? 0);
            $g['amount_pending'] += (float)($p['amount_pending'] ?? 0);
            unset($g);
        }
        foreach ($groups as &$g) {
            $g = self::withMarginFields($g);
            $g['stock_value_cost'] = round($g['stock_value_cost'], 2);
            $g['stock_value_sale'] = round($g['stock_value_sale'], 2);
        }
        unset($g);
        usort($groups, static fn($a, $b) => strcasecmp($a['consignor_name'], $b['consignor_name']));
        return $groups;
    }

    /**
     * Margen de venta de productos consignados en un rango (ingreso vs costo pactado), por producto.
     *
     * @return array{rows:list<array<string,mixed>>,by_consignor:list<array<string,mixed>>,totals:array<string,mixed>}
     */
    public function marginReport(string $from, string $to, int $consignorId = 0, string $q = ''): array
    {
        $where = [
            "d.status <> 'void'",
            'd.qty > 0',
            "COALESCE(s.status, '') <> 'cancelled'",
            's.sale_date BETWEEN ? AND ?',
        ];
        $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
        if ($consignorId > 0) {
            $where[] = 'd.consignor_id = ?';
            $params[] = $consignorId;
        }
        if ($q !== '') {
            $search = \SoftNova\Core\multi_term_like_clause($q, [
                'pp.name',
                'pp.code',
                "COALESCE(pp.barcode,'')",
                "COALESCE(s.invoice_number,'')",
            ]);
            $where[] = $search['sql'];
            foreach ($search['params'] as $sp) {
                $params[] = $sp;
            }
        }
        $rev = $this->debtRevenueSql();
        $stmt = $this->db->prepare(
            "SELECT d.product_id, pp.code AS product_code, pp.name AS product_name,
                    d.consignor_id, c.name AS consignor_name,
                    SUM(d.qty) AS units_sold,
                    SUM({$rev}) AS revenue,
                    SUM(d.qty * d.unit_cost) AS cost_sold,
                    COUNT(DISTINCT d.sale_id) AS sales_count,
                    SUM(CASE WHEN d.status IN ('open','partial') THEN (d.qty - d.qty_settled) * d.unit_cost ELSE 0 END) AS amount_pending
             FROM consignment_sale_debts d
             JOIN sales s ON s.id = d.sale_id
             LEFT JOIN sale_items si ON si.id = d.sale_item_id
             LEFT JOIN products pp ON pp.id = d.product_id
             LEFT JOIN consignors c ON c.id = d.consignor_id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY d.product_id, pp.code, pp.name, d.consignor_id, c.name
             ORDER BY revenue DESC
             LIMIT 2000"
        );
        $stmt->execute($params);
        $rows = [];
        $byConsignor = [];
        $totals = ['units_sold' => 0, 'revenue' => 0.0, 'cost_sold' => 0.0, 'amount_pending' => 0.0, 'sales_count' => 0];
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $r = self::withMarginFields($r);
            $rows[] = $r;
            $cid = (int)$r['consignor_id'];
            $byConsignor[$cid] ??= [
                'consignor_id' => $cid,
                'consignor_name' => (string)($r['consignor_name'] ?? 'Sin consignante'),
                'product_count' => 0,
                'units_sold' => 0,
                'revenue' => 0.0,
                'cost_sold' => 0.0,
                'amount_pending' => 0.0,
            ];
            $byConsignor[$cid]['product_count']++;
            foreach (['units_sold', 'revenue', 'cost_sold', 'amount_pending'] as $k) {
                $byConsignor[$cid][$k] += $r[$k];
                $totals[$k] += $r[$k];
            }
        }
        $byConsignor = array_map(static fn($g) => self::withMarginFields($g), array_values($byConsignor));
        usort($byConsignor, static fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        try {
            $cnt = $this->db->prepare(
                "SELECT COUNT(DISTINCT d.sale_id)
                 FROM consignment_sale_debts d
                 JOIN sales s ON s.id = d.sale_id
                 LEFT JOIN products pp ON pp.id = d.product_id
                 WHERE " . implode(' AND ', $where)
            );
            $cnt->execute($params);
            $totals['sales_count'] = (int)$cnt->fetchColumn();
        } catch (\Throwable $e) {
            $totals['sales_count'] = 0;
        }

        return ['rows' => $rows, 'by_consignor' => $byConsignor, 'totals' => self::withMarginFields($totals)];
    }

    /**
     * Trazabilidad de mercancía consignada: movimientos de stock + liquidaciones pagadas, más recientes primero.
     *
     * @param array{from?:string,to?:string,consignor_id?:int,product_id?:int,type?:string,q?:string} $filters
     * @return array{rows:list<array<string,mixed>>,truncated:bool}
     */
    public function traceability(array $filters, int $limit = 1000): array
    {
        $from = (string)($filters['from'] ?? '');
        $to = (string)($filters['to'] ?? '');
        $consignorId = (int)($filters['consignor_id'] ?? 0);
        $productId = (int)($filters['product_id'] ?? 0);
        $type = (string)($filters['type'] ?? '');
        $q = trim((string)($filters['q'] ?? ''));
        $limit = max(1, min(5000, $limit));
        $validDate = static fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

        $rows = [];
        if ($type !== 'settlement') {
            $dateExpr = "COALESCE(NULLIF(sm.movement_date, '0000-00-00'), DATE(sm.created_at))";
            $where = ["p.ownership = 'consignment'"];
            $params = [];
            if ($validDate($from)) {
                $where[] = "{$dateExpr} >= ?";
                $params[] = $from;
            }
            if ($validDate($to)) {
                $where[] = "{$dateExpr} <= ?";
                $params[] = $to;
            }
            if ($consignorId > 0) {
                $where[] = 'p.consignor_id = ?';
                $params[] = $consignorId;
            }
            if ($productId > 0) {
                $where[] = 'sm.product_id = ?';
                $params[] = $productId;
            }
            if ($type === 'adjustment') {
                $where[] = "(sm.reference_type = 'adjustment' OR sm.type = 'adjustment')";
            } elseif ($type !== '' && isset(self::TRACE_TYPE_LABELS[$type])) {
                $where[] = 'sm.reference_type = ?';
                $params[] = $type;
            }
            if ($q !== '') {
                $search = \SoftNova\Core\multi_term_like_clause($q, [
                    'p.name',
                    'p.code',
                    "COALESCE(p.barcode,'')",
                    "COALESCE(sm.notes,'')",
                    "COALESCE(s.invoice_number,'')",
                    "COALESCE(r.receipt_number,'')",
                ]);
                $where[] = $search['sql'];
                foreach ($search['params'] as $sp) {
                    $params[] = $sp;
                }
            }
            $stmt = $this->db->prepare(
                "SELECT sm.id, {$dateExpr} AS event_date, sm.created_at, sm.type AS movement_type,
                        sm.reference_type, sm.reference_id, sm.quantity, sm.unit_cost, sm.stock_after, sm.notes,
                        p.id AS product_id, p.code AS product_code, p.name AS product_name,
                        c.id AS consignor_id, c.name AS consignor_name,
                        s.invoice_number, r.receipt_number, u.name AS user_name
                 FROM stock_movements sm
                 JOIN products p ON p.id = sm.product_id
                 LEFT JOIN consignors c ON c.id = p.consignor_id
                 LEFT JOIN sales s ON s.id = sm.reference_id AND sm.reference_type IN ('sale', 'return')
                 LEFT JOIN consignment_receipts r ON r.id = sm.reference_id AND sm.reference_type = 'consignment_in'
                 LEFT JOIN users u ON u.id = sm.user_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY {$dateExpr} DESC, sm.created_at DESC, sm.id DESC
                 LIMIT " . ($limit + 1)
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll() ?: [] as $m) {
                $ref = (string)($m['reference_type'] ?? '');
                $qty = abs((int)$m['quantity']);
                $signed = match ((string)$m['movement_type']) {
                    'out' => -$qty,
                    'in' => $qty,
                    default => (int)$m['quantity'],
                };
                $kind = $ref === 'adjustment' || $m['movement_type'] === 'adjustment' ? 'adjustment' : $ref;
                $doc = match ($kind) {
                    'consignment_in' => (string)($m['receipt_number'] ?? ''),
                    'sale', 'return' => (string)($m['invoice_number'] ?? ('#' . (int)$m['reference_id'])),
                    'sale_credit_note' => 'NC #' . (int)$m['reference_id'],
                    default => '',
                };
                $unitCost = (float)($m['unit_cost'] ?? 0);
                $rows[] = [
                    'kind' => $kind,
                    'kind_label' => self::TRACE_TYPE_LABELS[$kind] ?? ($ref !== '' ? $ref : 'Movimiento'),
                    'event_date' => (string)$m['event_date'],
                    'created_at' => (string)($m['created_at'] ?? ''),
                    'product_id' => (int)$m['product_id'],
                    'product_code' => (string)($m['product_code'] ?? ''),
                    'product_name' => (string)($m['product_name'] ?? ''),
                    'consignor_id' => (int)($m['consignor_id'] ?? 0),
                    'consignor_name' => (string)($m['consignor_name'] ?? ''),
                    'document' => $doc,
                    'qty' => $signed,
                    'unit_cost' => $unitCost,
                    'amount' => round(abs($signed) * $unitCost, 2),
                    'stock_after' => $m['stock_after'] !== null ? (int)$m['stock_after'] : null,
                    'user_name' => (string)($m['user_name'] ?? ''),
                    'notes' => (string)($m['notes'] ?? ''),
                ];
            }
        }

        if ($type === '' || $type === 'settlement') {
            $where = ['1=1'];
            $params = [];
            if ($validDate($from)) {
                $where[] = 'cs.settlement_date >= ?';
                $params[] = $from;
            }
            if ($validDate($to)) {
                $where[] = 'cs.settlement_date <= ?';
                $params[] = $to;
            }
            if ($consignorId > 0) {
                $where[] = 'cs.consignor_id = ?';
                $params[] = $consignorId;
            }
            if ($productId > 0) {
                $where[] = 'd.product_id = ?';
                $params[] = $productId;
            }
            if ($q !== '') {
                $search = \SoftNova\Core\multi_term_like_clause($q, [
                    'p.name',
                    'p.code',
                    "COALESCE(p.barcode,'')",
                    "COALESCE(cs.notes,'')",
                    "COALESCE(s.invoice_number,'')",
                ]);
                $where[] = $search['sql'];
                foreach ($search['params'] as $sp) {
                    $params[] = $sp;
                }
            }
            try {
                $stmt = $this->db->prepare(
                    "SELECT cs.id AS settlement_id, cs.settlement_date, cs.created_at, cs.payment_method, cs.notes,
                            csi.qty, csi.amount, d.unit_cost,
                            p.id AS product_id, p.code AS product_code, p.name AS product_name,
                            c.id AS consignor_id, c.name AS consignor_name,
                            s.invoice_number, u.name AS user_name
                     FROM consignment_settlement_items csi
                     JOIN consignment_settlements cs ON cs.id = csi.settlement_id
                     JOIN consignment_sale_debts d ON d.id = csi.debt_id
                     LEFT JOIN products p ON p.id = d.product_id
                     LEFT JOIN consignors c ON c.id = cs.consignor_id
                     LEFT JOIN sales s ON s.id = d.sale_id
                     LEFT JOIN users u ON u.id = cs.user_id
                     WHERE " . implode(' AND ', $where) . "
                     ORDER BY cs.settlement_date DESC, cs.id DESC
                     LIMIT " . ($limit + 1)
                );
                $stmt->execute($params);
                foreach ($stmt->fetchAll() ?: [] as $s) {
                    $rows[] = [
                        'kind' => 'settlement',
                        'kind_label' => self::TRACE_TYPE_LABELS['settlement'],
                        'event_date' => (string)$s['settlement_date'],
                        'created_at' => (string)($s['created_at'] ?? ''),
                        'product_id' => (int)($s['product_id'] ?? 0),
                        'product_code' => (string)($s['product_code'] ?? ''),
                        'product_name' => (string)($s['product_name'] ?? ''),
                        'consignor_id' => (int)($s['consignor_id'] ?? 0),
                        'consignor_name' => (string)($s['consignor_name'] ?? ''),
                        'document' => 'Liq. #' . (int)$s['settlement_id']
                            . (!empty($s['invoice_number']) ? ' · ' . $s['invoice_number'] : ''),
                        'qty' => (int)$s['qty'],
                        'unit_cost' => (float)($s['unit_cost'] ?? 0),
                        'amount' => round((float)($s['amount'] ?? 0), 2),
                        'stock_after' => null,
                        'user_name' => (string)($s['user_name'] ?? ''),
                        'notes' => trim(PaymentMethodCatalog::label((string)($s['payment_method'] ?? ''))
                            . (!empty($s['notes']) ? ' · ' . $s['notes'] : '')),
                    ];
                }
            } catch (\Throwable $e) {
                error_log('ConsignmentService::traceability settlements: ' . $e->getMessage());
            }
        }

        usort($rows, static function ($a, $b) {
            return [$b['event_date'], $b['created_at']] <=> [$a['event_date'], $a['created_at']];
        });
        $truncated = count($rows) > $limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $limit);
        }
        return ['rows' => $rows, 'truncated' => $truncated];
    }
}
