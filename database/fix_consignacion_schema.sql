-- Productos por consignación (mercancía de terceros no-proveedores).
-- Stock físico separado del inventario propio (ownership = consignment).
-- No capitaliza 143501; la obligación nace al vender y se liquida después.
-- Documento de consignante: unicidad en aplicación (saveConsignor), no UNIQUE BD
-- (permite document_number NULL sin colisiones).

CREATE TABLE IF NOT EXISTS consignors (
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
    INDEX idx_consignor_name (name),
    INDEX idx_consignor_doc (document_type, document_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- products.ownership / products.consignor_id se agregan en runtime (ConsignmentService::ensureSchema)

CREATE TABLE IF NOT EXISTS consignment_receipts (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consignment_receipt_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    stock_movement_id INT UNSIGNED NULL,
    notes VARCHAR(255) NULL,
    INDEX idx_cri_receipt (receipt_id),
    INDEX idx_cri_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consignment_sale_debts (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consignment_settlements (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consignment_settlement_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    settlement_id BIGINT UNSIGNED NOT NULL,
    debt_id BIGINT UNSIGNED NOT NULL,
    qty INT NOT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    INDEX idx_csi_settlement (settlement_id),
    INDEX idx_csi_debt (debt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
