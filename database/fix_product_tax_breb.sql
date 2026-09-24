-- IVA por producto + snapshot fiscal en líneas de venta/cotización.
-- Preferir: php database/migrate.php (paso product_tax + backfill sale_items).
-- Si ejecuta a mano y la columna ya existe, ignore el error Duplicate column.

ALTER TABLE `products`
  ADD COLUMN `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `sale_price`;

ALTER TABLE `sale_items`
  ADD COLUMN `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `subtotal`;

ALTER TABLE `sale_items`
  ADD COLUMN `tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `tax_rate`;

ALTER TABLE `sale_items`
  ADD COLUMN `discount_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `tax_amount`;

ALTER TABLE `sale_items`
  ADD COLUMN `taxable` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `discount_amount`;

ALTER TABLE `quote_items`
  ADD COLUMN `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `subtotal`;

ALTER TABLE `quote_items`
  ADD COLUMN `tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `tax_rate`;
