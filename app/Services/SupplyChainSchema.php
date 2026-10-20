<?php

namespace SoftNova\Services;

/**
 * Esquema de cadena de suministro: lotes con vencimiento, proveedores por
 * producto, carga de facturas de compra y pagos a proveedor.
 *
 * Idempotente por conexión PDO, igual que TenantOpsSchema.
 */
class SupplyChainSchema
{
    public static function ensure(\PDO $db): void
    {
        static $done = [];
        $oid = spl_object_id($db);
        if (isset($done[$oid])) {
            return;
        }
        $done[$oid] = true;

        // Las columnas nuevas cuelgan de purchase_orders, que crea PurchasingService.
        try {
            new PurchasingService($db);
        } catch (\Throwable $e) {
            // silencioso: se reintenta en la siguiente petición
        }

        self::ensureLots($db);
        self::ensureProductSuppliers($db);
        self::ensureInvoiceImports($db);
        self::ensureInvoiceLifecycleEvents($db);
        self::ensureSupplierPayments($db);
        self::ensurePurchaseCreditNotes($db);
        self::ensureExtraColumns($db);
        ProductCostHistory::ensure($db);
    }

    /**
     * Bitácora de movimientos internos del borrador/factura
     * (carga, revisión, ediciones, confirmación, descarte).
     */
    private static function ensureInvoiceLifecycleEvents(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS purchase_invoice_lifecycle_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                import_id INT UNSIGNED NULL,
                purchase_order_id INT UNSIGNED NULL,
                event_type VARCHAR(40) NOT NULL,
                label VARCHAR(160) NOT NULL,
                detail VARCHAR(500) NULL,
                actor_user_id INT UNSIGNED NULL,
                meta_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_pile_import (import_id),
                KEY idx_pile_order (purchase_order_id),
                KEY idx_pile_created (created_at),
                KEY idx_pile_type (event_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private static function ensurePurchaseCreditNotes(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS purchase_credit_notes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                credit_number VARCHAR(40) NOT NULL UNIQUE,
                purchase_order_id INT UNSIGNED NOT NULL,
                supplier_id INT UNSIGNED NULL,
                credit_date DATE NOT NULL,
                reason VARCHAR(255) NULL,
                external_number VARCHAR(80) NULL,
                affects_stock TINYINT(1) NOT NULL DEFAULT 1,
                subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                tax DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                accounting_entry_id BIGINT UNSIGNED NULL,
                status ENUM('posted','cancelled') NOT NULL DEFAULT 'posted',
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_pcn_order (purchase_order_id),
                KEY idx_pcn_supplier (supplier_id),
                KEY idx_pcn_date (credit_date),
                KEY idx_pcn_entry (accounting_entry_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS purchase_credit_note_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                credit_note_id INT UNSIGNED NOT NULL,
                purchase_order_item_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NULL,
                product_name VARCHAR(255) NOT NULL,
                quantity INT NOT NULL DEFAULT 0,
                unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                line_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_pcni_note (credit_note_id),
                KEY idx_pcni_poi (purchase_order_item_id),
                KEY idx_pcni_product (product_id),
                CONSTRAINT fk_pcni_note FOREIGN KEY (credit_note_id)
                    REFERENCES purchase_credit_notes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private static function ensureLots(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS product_lots (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id INT UNSIGNED NOT NULL,
                lot_code VARCHAR(60) NOT NULL,
                expiry_date DATE NULL,
                quantity INT NOT NULL DEFAULT 0,
                initial_quantity INT NOT NULL DEFAULT 0,
                unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                supplier_id INT UNSIGNED NULL,
                purchase_order_id INT UNSIGNED NULL,
                received_date DATE NULL,
                status ENUM('active','depleted','expired','blocked') NOT NULL DEFAULT 'active',
                notes VARCHAR(255) NULL,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_product_lot (product_id, lot_code),
                KEY idx_lot_fefo (product_id, status, expiry_date),
                KEY idx_lot_expiry (expiry_date),
                KEY idx_lot_supplier (supplier_id),
                KEY idx_lot_order (purchase_order_id),
                CONSTRAINT fk_lot_product FOREIGN KEY (product_id)
                    REFERENCES products(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Traza FEFO: de qué lote salió (o entró) cada movimiento de kardex.
        $db->exec(
            "CREATE TABLE IF NOT EXISTS stock_movement_lots (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                movement_id INT UNSIGNED NOT NULL,
                lot_id INT UNSIGNED NOT NULL,
                lot_code VARCHAR(60) NULL,
                expiry_date DATE NULL,
                       quantity INT NOT NULL DEFAULT 0,
                       unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                       restored_at DATETIME NULL,
                       created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                       KEY idx_sml_movement (movement_id),
                KEY idx_sml_lot (lot_id),
                CONSTRAINT fk_sml_lot FOREIGN KEY (lot_id)
                    REFERENCES product_lots(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private static function ensureProductSuppliers(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS product_suppliers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id INT UNSIGNED NOT NULL,
                supplier_id INT UNSIGNED NOT NULL,
                supplier_sku VARCHAR(120) NULL,
                supplier_product_name VARCHAR(255) NULL,
                last_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                last_purchase_date DATE NULL,
                lead_time_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                min_order_qty INT NOT NULL DEFAULT 1,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                notes VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_product_supplier (product_id, supplier_id),
                KEY idx_ps_supplier (supplier_id),
                KEY idx_ps_sku (supplier_sku),
                KEY idx_ps_primary (product_id, is_primary),
                CONSTRAINT fk_ps_product FOREIGN KEY (product_id)
                    REFERENCES products(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private static function ensureInvoiceImports(\PDO $db): void
    {
        // Borrador de factura de proveedor: se revisa en el modal antes de
        // convertirla en orden de compra y tocar inventario.
        $db->exec(
            "CREATE TABLE IF NOT EXISTS purchase_invoice_imports (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT UNSIGNED NULL,
                invoice_number VARCHAR(80) NULL,
                invoice_date DATE NULL,
                due_date DATE NULL,
                warehouse_date DATE NULL,
                source ENUM('manual','csv','text','ai','ai_vision') NOT NULL DEFAULT 'manual',
                file_path VARCHAR(255) NULL,
                file_mime VARCHAR(100) NULL,
                original_name VARCHAR(255) NULL,
                raw_text MEDIUMTEXT NULL,
                parse_notes VARCHAR(255) NULL,
                subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                tax_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                payment_mode VARCHAR(20) NOT NULL DEFAULT 'payable',
                status ENUM('pending','processing','confirmed','discarded') NOT NULL DEFAULT 'pending',
                purchase_order_id INT UNSIGNED NULL,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                confirmed_at DATETIME NULL,
                KEY idx_pii_status (status),
                KEY idx_pii_supplier (supplier_id),
                KEY idx_pii_order (purchase_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        try {
            $db->exec(
                "ALTER TABLE purchase_invoice_imports
                 MODIFY COLUMN status ENUM('pending','processing','confirmed','discarded') NOT NULL DEFAULT 'pending'"
            );
        } catch (\Throwable $e) {
            // ya migrado
        }
        try {
            $db->exec(
                "ALTER TABLE purchase_invoice_imports
                 MODIFY COLUMN source ENUM('manual','csv','text','ai','ai_vision') NOT NULL DEFAULT 'manual'"
            );
        } catch (\Throwable $e) {
            // ya migrado
        }
        try {
            $col = $db->query("SHOW COLUMNS FROM purchase_invoice_imports LIKE 'early_payment_discount_pct'")->fetch();
            if (!$col) {
                $db->exec(
                    "ALTER TABLE purchase_invoice_imports
                     ADD COLUMN early_payment_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00
                     AFTER payment_mode"
                );
            }
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            $col = $db->query("SHOW COLUMNS FROM purchase_invoice_imports LIKE 'commercial_discount_pct'")->fetch();
            if (!$col) {
                $db->exec(
                    "ALTER TABLE purchase_invoice_imports
                     ADD COLUMN commercial_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00
                     AFTER early_payment_discount_pct"
                );
            }
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            $col = $db->query("SHOW COLUMNS FROM purchase_invoice_imports LIKE 'commercial_discount_amount'")->fetch();
            if (!$col) {
                $db->exec(
                    "ALTER TABLE purchase_invoice_imports
                     ADD COLUMN commercial_discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00
                     AFTER commercial_discount_pct"
                );
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $db->exec(
            "CREATE TABLE IF NOT EXISTS purchase_invoice_import_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                import_id INT UNSIGNED NOT NULL,
                line_no SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                raw_line VARCHAR(500) NULL,
                supplier_sku VARCHAR(120) NULL,
                description VARCHAR(255) NOT NULL DEFAULT '',
                quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
                unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                line_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                matched_product_id INT UNSIGNED NULL,
                match_type VARCHAR(20) NOT NULL DEFAULT 'none',
                row_action VARCHAR(20) NOT NULL DEFAULT 'add_stock',
                lot_code VARCHAR(60) NULL,
                expiry_date DATE NULL,
                sale_price DECIMAL(12,2) NULL,
                confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_piii_import (import_id),
                KEY idx_piii_product (matched_product_id),
                CONSTRAINT fk_piii_import FOREIGN KEY (import_id)
                    REFERENCES purchase_invoice_imports(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private static function ensureSupplierPayments(\PDO $db): void
    {
        // Abonos a proveedor: liquidan la cuenta por pagar (220505).
        $db->exec(
            "CREATE TABLE IF NOT EXISTS supplier_payments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT UNSIGNED NOT NULL,
                purchase_order_id INT UNSIGNED NULL,
                payment_date DATE NOT NULL,
                amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                payment_method VARCHAR(50) NOT NULL DEFAULT 'cash',
                reference VARCHAR(120) NULL,
                notes VARCHAR(255) NULL,
                accounting_entry_id BIGINT UNSIGNED NULL,
                cash_movement_id INT UNSIGNED NULL,
                status ENUM('applied','cancelled') NOT NULL DEFAULT 'applied',
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sp_supplier (supplier_id),
                KEY idx_sp_order (purchase_order_id),
                KEY idx_sp_date (payment_date),
                KEY idx_sp_entry (accounting_entry_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private static function ensureExtraColumns(\PDO $db): void
    {
        foreach ([
            // Vencimientos y reposición por producto
            ['products', 'track_lots', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER min_stock'],
            ['products', 'expiry_alert_days', 'SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER track_lots'],
            ['products', 'next_expiry_date', 'DATE NULL AFTER expiry_alert_days'],
            ['products', 'max_stock', 'INT NULL AFTER next_expiry_date'],
            ['products', 'location', "VARCHAR(120) NULL AFTER max_stock"],
            ['products', 'barcode', "VARCHAR(64) NULL AFTER code"],

            // Estado de pago y envío de la orden de compra
            ['purchase_orders', 'paid_total', 'DECIMAL(14,2) NOT NULL DEFAULT 0.00'],
            ['purchase_orders', 'payment_status', "VARCHAR(20) NOT NULL DEFAULT 'pending'"],
            ['purchase_orders', 'credit_total', 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER paid_total'],
            ['purchase_orders', 'origin', "VARCHAR(20) NOT NULL DEFAULT 'manual'"],
            ['purchase_orders', 'invoice_import_id', 'INT UNSIGNED NULL'],
            ['purchase_orders', 'sent_at', 'DATETIME NULL'],
            ['purchase_orders', 'sent_via', 'VARCHAR(20) NULL'],
            ['purchase_orders', 'sent_to', 'VARCHAR(180) NULL'],
            ['purchase_orders', 'duplicated_from_id', 'INT UNSIGNED NULL'],
            ['purchase_order_items', 'lot_code', 'VARCHAR(60) NULL'],
            ['purchase_order_items', 'expiry_date', 'DATE NULL'],

            // Puente contable de caja
            ['cash_sessions', 'expected_amount', 'DECIMAL(12,2) NULL AFTER closing_amount'],
            ['cash_sessions', 'difference_amount', 'DECIMAL(12,2) NULL AFTER expected_amount'],
            ['cash_sessions', 'opening_entry_id', 'BIGINT UNSIGNED NULL'],
            ['cash_sessions', 'closing_entry_id', 'BIGINT UNSIGNED NULL'],
            ['cash_movements', 'accounting_entry_id', 'BIGINT UNSIGNED NULL'],
            ['stock_movement_lots', 'restored_at', 'DATETIME NULL'],

            // EAN/código de barras en líneas de factura importada (distinto del SKU proveedor)
            ['purchase_invoice_import_items', 'barcode', 'VARCHAR(64) NULL AFTER supplier_sku'],

            // Casar factura importada con una OC de pedido existente
            ['purchase_invoice_imports', 'linked_order_id', 'INT UNSIGNED NULL AFTER purchase_order_id'],

            // Fecha real de ingreso a bodega (puede diferir de la fecha de factura)
            ['purchase_invoice_imports', 'warehouse_date', 'DATE NULL AFTER due_date'],

            // Tipo de documento proveedor: electrónica / normal / remisión (define IVA de líneas e inventario)
            ['purchase_invoice_imports', 'document_kind', "VARCHAR(20) NULL AFTER payment_mode"],
            // Solo F. Normal: 1 = productos con IVA %, 0 = exentos, 2 = IVA monto personalizado (tax_override_amount)
            ['purchase_invoice_imports', 'apply_product_vat', 'TINYINT(1) NULL AFTER document_kind'],
            // F. Electrónica: 1 = el Excel/PDF ya trae precios con IVA (no volver a sumar)
            ['purchase_invoice_imports', 'vat_already_included', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER apply_product_vat'],
            // F. Normal + apply_product_vat=2: monto exacto de IVA de la factura (asiento 240802)
            ['purchase_invoice_imports', 'tax_override_amount', 'DECIMAL(14,2) NULL AFTER tax_total'],

            // Snapshot EAN en líneas de OC (trazabilidad aunque el producto cambie después)
            ['purchase_order_items', 'barcode', 'VARCHAR(64) NULL AFTER product_name'],
            ['purchase_order_items', 'product_code', 'VARCHAR(255) NULL AFTER barcode'],

            // Costo de inventario por línea (kardex + promedio del producto); NULL = igual al costo de factura.
            ['purchase_invoice_import_items', 'inventory_cost', 'DECIMAL(12,2) NULL AFTER unit_cost'],
            ['purchase_order_items', 'inventory_cost', 'DECIMAL(12,2) NULL AFTER unit_cost'],

            // Descarte de borrador de factura (trazabilidad de revisión)
            ['purchase_invoice_imports', 'discarded_at', 'DATETIME NULL AFTER confirmed_at'],
            ['purchase_invoice_imports', 'discarded_by', 'INT UNSIGNED NULL AFTER discarded_at'],

            // Anulación post-recepción: columna dedicada (no depender solo de notes VARCHAR(255))
            ['purchase_orders', 'voided_at', 'DATETIME NULL'],
            ['purchase_orders', 'void_reason', 'VARCHAR(255) NULL'],

            // Nota/observación del operador al confirmar factura (visible en Detalle y Timeline)
            ['purchase_invoice_imports', 'review_notes', 'TEXT NULL'],
            ['purchase_orders', 'review_notes', 'TEXT NULL'],

            // Comprobante fotográfico / PDF del abono a proveedor (consignación, transferencia, etc.)
            ['supplier_payments', 'receipt_path', 'VARCHAR(255) NULL AFTER notes'],
            ['supplier_payments', 'receipt_mime', 'VARCHAR(80) NULL AFTER receipt_path'],
            ['supplier_payments', 'receipt_original_name', 'VARCHAR(255) NULL AFTER receipt_mime'],
            // Efectivo: 1 = sale del cajón POS; 0 = otro origen (no descuenta caja)
            ['supplier_payments', 'cash_from_drawer', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER payment_method'],
            ['supplier_payments', 'cash_source_note', 'VARCHAR(255) NULL AFTER cash_from_drawer'],
            // Valor neto de la factura física usado como base del abono + dcto. pronto pago aplicado al pagar
            ['supplier_payments', 'invoice_value', 'DECIMAL(14,2) NULL AFTER cash_source_note'],
            ['supplier_payments', 'discount_pct', 'DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER invoice_value'],
            ['supplier_payments', 'discount_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER discount_pct'],
            // Base efectiva de la CxP cuando la factura del proveedor difiere del total del sistema
            ['purchase_orders', 'supplier_invoice_value', 'DECIMAL(14,2) NULL AFTER total'],
        ] as [$table, $column, $definition]) {
            self::addColumnIfMissing($db, $table, $column, $definition);
        }

        // Backfill: anuladas antiguas que solo tenían el tag en notes.
        try {
            $db->exec(
                "UPDATE purchase_orders
                 SET voided_at = COALESCE(updated_at, created_at, NOW())
                 WHERE status = 'cancelled'
                   AND voided_at IS NULL
                   AND (
                        COALESCE(notes,'') LIKE '%ANULADA POST-RECEPCIÓN%'
                        OR COALESCE(notes,'') LIKE '%[[NC_FULL%'
                   )"
            );
        } catch (\Throwable $e) {
            // columna aún no existe o sin privilegios
        }

        self::ensureReplenishmentDraftTable($db);

        self::addIndexIfMissing($db, 'purchase_orders', 'idx_po_payment_status', '(payment_status)');
        self::addIndexIfMissing($db, 'purchase_orders', 'idx_po_duplicated_from', '(duplicated_from_id)');
        self::addIndexIfMissing($db, 'products', 'idx_products_next_expiry', '(next_expiry_date)');
        self::addIndexIfMissing($db, 'products', 'idx_products_barcode', '(barcode)');
        self::addIndexIfMissing($db, 'cash_movements', 'idx_cm_accounting', '(accounting_entry_id)');
    }

    private static function ensureReplenishmentDraftTable(\PDO $db): void
    {
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS replenishment_draft_items (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    product_id INT UNSIGNED NOT NULL,
                    quantity INT NOT NULL DEFAULT 1,
                    unit_cost DECIMAL(12,2) NULL,
                    added_by INT UNSIGNED NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_rd_product (product_id),
                    KEY idx_rd_updated (updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            // silencioso
        }
    }

    private static function addColumnIfMissing(\PDO $db, string $table, string $column, string $definition): void
    {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($column));
            if ($stmt && $stmt->fetch()) {
                return;
            }
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (\Throwable $e) {
            // La tabla puede no existir todavía en instalaciones parciales.
        }
    }

    private static function addIndexIfMissing(\PDO $db, string $table, string $name, string $columns): void
    {
        try {
            $stmt = $db->query("SHOW INDEX FROM `{$table}` WHERE Key_name = " . $db->quote($name));
            if ($stmt && $stmt->fetch()) {
                return;
            }
            $db->exec("ALTER TABLE `{$table}` ADD INDEX `{$name}` {$columns}");
        } catch (\Throwable $e) {
            // silencioso
        }
    }
}
