-- IVA / trazabilidad — ejecutar en phpMyAdmin (Belleza Total / Seri ERP)
-- Tabla settings usa: setting_key, setting_value (NO "key" / "value")
-- Ejecute bloque por bloque. Si "Duplicate column", ignore ese ALTER.

-- ========== PASO 1: columnas (si no las creó antes) ==========
-- ALTER TABLE `products`
--   ADD COLUMN `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `sale_price`;
-- ALTER TABLE `sale_items`
--   ADD COLUMN `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `subtotal`;
-- ALTER TABLE `sale_items`
--   ADD COLUMN `tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `tax_rate`;
-- ALTER TABLE `sale_items`
--   ADD COLUMN `discount_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `tax_amount`;
-- ALTER TABLE `sale_items`
--   ADD COLUMN `taxable` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `discount_amount`;

-- ========== PASO 2: productos sin tasa ← IVA de Configuración ==========
UPDATE `products` p
SET p.`tax_rate` = (
    SELECT CAST(s.`setting_value` AS DECIMAL(5,2))
    FROM `settings` s
    WHERE s.`setting_key` = 'tax_rate'
    LIMIT 1
)
WHERE (p.`tax_rate` = 0 OR p.`tax_rate` IS NULL)
  AND EXISTS (
    SELECT 1 FROM `settings`
    WHERE `setting_key` = 'tax_rate'
      AND CAST(`setting_value` AS DECIMAL(5,2)) > 0
  );

INSERT INTO `settings` (`setting_key`, `setting_value`)
SELECT 'product_tax_rate_backfill_v1', NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM `settings` WHERE `setting_key` = 'product_tax_rate_backfill_v1'
);

-- ========== PASO 3: ventas antiguas sin IVA en líneas ==========
UPDATE `sale_items` si
INNER JOIN `sales` s ON s.`id` = si.`sale_id`
LEFT JOIN `products` p ON p.`id` = si.`product_id`
SET
  si.`discount_amount` = ROUND(
    IF(s.`subtotal` > 0, si.`subtotal` * (s.`discount` / s.`subtotal`), 0),
    2
  ),
  si.`taxable` = ROUND(
    GREATEST(
      si.`subtotal` - IF(s.`subtotal` > 0, si.`subtotal` * (s.`discount` / s.`subtotal`), 0),
      0
    ),
    2
  ),
  si.`tax_rate` = COALESCE(
    NULLIF(p.`tax_rate`, 0),
    (SELECT CAST(`setting_value` AS DECIMAL(5,2)) FROM `settings` WHERE `setting_key` = 'tax_rate' LIMIT 1),
    0
  ),
  si.`tax_amount` = ROUND(
    IF(
      (s.`subtotal` - s.`discount`) > 0,
      s.`tax` * (
        GREATEST(
          si.`subtotal` - IF(s.`subtotal` > 0, si.`subtotal` * (s.`discount` / s.`subtotal`), 0),
          0
        ) / (s.`subtotal` - s.`discount`)
      ),
      0
    ),
    2
  )
WHERE COALESCE(s.`tax`, 0) > 0
  AND COALESCE(s.`status`, '') <> 'cancelled'
  AND COALESCE(si.`tax_amount`, 0) <= 0;

INSERT INTO `settings` (`setting_key`, `setting_value`)
SELECT 'sale_items_tax_backfill_v1', NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM `settings` WHERE `setting_key` = 'sale_items_tax_backfill_v1'
);

-- ========== Verificación ==========
-- SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('tax_rate','product_tax_rate_backfill_v1','sale_items_tax_backfill_v1');
-- SELECT COUNT(*) AS lineas_con_iva FROM sale_items WHERE COALESCE(tax_amount, 0) > 0;
