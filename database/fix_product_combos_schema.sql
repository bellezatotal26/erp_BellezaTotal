-- Combos / kits de venta (plantillas reutilizables en Caja)
-- Idempotente: CREATE IF NOT EXISTS

CREATE TABLE IF NOT EXISTS product_combos (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_combo_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    combo_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_pci_combo (combo_id),
    INDEX idx_pci_product (product_id),
    UNIQUE KEY uq_pci_combo_product (combo_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
