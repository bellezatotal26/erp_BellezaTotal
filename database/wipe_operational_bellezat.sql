-- =============================================================================
-- Seri ERP / Belleza Total — Limpiar BD operativa (phpMyAdmin, SIN DELIMITER)
-- Base: bellezat_erp
--
-- ELIMINA: ventas, caja, inventario, compras, clientes, proveedores, nómina,
--          consignación, gastos, asientos, auditoría, usuarios no-admin, etc.
-- CONSERVA:
--   - settings
--   - accounting_accounts + accounting_settings (PUC)
--   - un único usuario admin (conserva su contraseña actual)
--
-- USO:
--   1) Backup recomendado
--   2) phpMyAdmin → seleccione bellezat_erp → pestaña SQL
--   3) Preferible: ejecute desde "SET NAMES" hasta el final (Método B)
--   4) Si una tabla no existe y corta la ejecución: use Método A abajo
--
-- IRREVERSIBLE.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- MÉTODO A (si Método B falla): ejecute SOLO este SELECT, copie la columna
-- "sql_a_ejecutar" completa, péguela en SQL y ejecute. Luego el bloque USUARIOS.
-- -----------------------------------------------------------------------------
/*
SELECT CONCAT('TRUNCATE TABLE `', TABLE_NAME, '`;') AS sql_a_ejecutar
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_TYPE = 'BASE TABLE'
  AND TABLE_NAME NOT IN ('settings', 'accounting_accounts', 'accounting_settings', 'users')
ORDER BY TABLE_NAME;
*/

-- -----------------------------------------------------------------------------
-- MÉTODO B — TRUNCATE explícito (sin procedimientos / sin DELIMITER)
-- -----------------------------------------------------------------------------

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---- Ventas / caja / CxC ----
TRUNCATE TABLE `sale_items`;
TRUNCATE TABLE `sale_payments`;
TRUNCATE TABLE `sales`;
TRUNCATE TABLE `sale_credit_note_items`;
TRUNCATE TABLE `sale_credit_notes`;
TRUNCATE TABLE `receivables`;
TRUNCATE TABLE `cash_movements`;
TRUNCATE TABLE `cash_sessions`;
TRUNCATE TABLE `quote_items`;
TRUNCATE TABLE `quotes`;
TRUNCATE TABLE `sales_commissions`;
TRUNCATE TABLE `user_commission_rates`;

-- ---- Inventario ----
TRUNCATE TABLE `stock_movement_lots`;
TRUNCATE TABLE `stock_movements`;
TRUNCATE TABLE `product_lots`;
TRUNCATE TABLE `product_suppliers`;
TRUNCATE TABLE `product_stock_debts`;
TRUNCATE TABLE `product_offer_log`;
TRUNCATE TABLE `products`;
TRUNCATE TABLE `categories`;
TRUNCATE TABLE `notification_dismissals`;

-- ---- Clientes / proveedores / gastos ----
TRUNCATE TABLE `customers`;
TRUNCATE TABLE `suppliers`;
TRUNCATE TABLE `expenses`;
TRUNCATE TABLE `expense_categories`;
TRUNCATE TABLE `expense_subgroups`;

-- ---- Compras ----
TRUNCATE TABLE `purchase_credit_note_items`;
TRUNCATE TABLE `purchase_credit_notes`;
TRUNCATE TABLE `supplier_payments`;
TRUNCATE TABLE `purchase_order_items`;
TRUNCATE TABLE `purchase_orders`;
TRUNCATE TABLE `purchase_invoice_import_items`;
TRUNCATE TABLE `purchase_invoice_imports`;
TRUNCATE TABLE `replenishment_draft_items`;
TRUNCATE TABLE `purchase_items`;
TRUNCATE TABLE `purchases`;

-- ---- Consignación ----
TRUNCATE TABLE `consignment_settlement_items`;
TRUNCATE TABLE `consignment_settlements`;
TRUNCATE TABLE `consignment_sale_debts`;
TRUNCATE TABLE `consignment_receipt_items`;
TRUNCATE TABLE `consignment_receipts`;
TRUNCATE TABLE `consignors`;

-- ---- Nómina ----
TRUNCATE TABLE `payroll_items`;
TRUNCATE TABLE `payroll_runs`;
TRUNCATE TABLE `employees`;

-- ---- Contabilidad (movimientos; se conserva el PUC) ----
TRUNCATE TABLE `accounting_lines`;
TRUNCATE TABLE `accounting_entries`;
TRUNCATE TABLE `accounting_periods`;

-- ---- IA / jobs / auditoría / extras ----
TRUNCATE TABLE `ai_messages`;
TRUNCATE TABLE `ai_conversations`;
TRUNCATE TABLE `jobs`;
TRUNCATE TABLE `audit_logs`;
TRUNCATE TABLE `tenant_notifications`;
TRUNCATE TABLE `custom_select_options`;
TRUNCATE TABLE `integration_settings`;
TRUNCATE TABLE `ecommerce_settings`;

-- Tablas SaaS residuales (si existen en standalone, se vacían)
TRUNCATE TABLE `ticket_messages`;
TRUNCATE TABLE `tickets`;
TRUNCATE TABLE `license_payments`;
TRUNCATE TABLE `license_sales`;
TRUNCATE TABLE `tenant_users`;
TRUNCATE TABLE `tenants`;
TRUNCATE TABLE `subscription_plans`;
TRUNCATE TABLE `announcements`;
TRUNCATE TABLE `system_settings`;
TRUNCATE TABLE `super_admin_users`;

SET FOREIGN_KEY_CHECKS = 1;

-- ---- Usuarios: dejar un solo admin (sin cambiar contraseña) ----
DELETE FROM `users` WHERE COALESCE(`role`, '') <> 'admin';

DELETE FROM `users`
WHERE `role` = 'admin'
  AND `id` NOT IN (
    SELECT mid FROM (
      SELECT MIN(`id`) AS mid FROM `users` WHERE `role` = 'admin'
    ) AS keep_admin
  );

UPDATE `users`
SET `role` = 'admin',
    `status` = 'active'
WHERE `role` = 'admin';

-- Reiniciar secuencias en settings
UPDATE `settings` SET `setting_value` = '1' WHERE `setting_key` = 'barcode_next';
UPDATE `settings` SET `setting_value` = '1' WHERE `setting_key` = 'sku_next';
UPDATE `settings` SET `setting_value` = '1' WHERE `setting_key` = 'invoice_next';
UPDATE `settings` SET `setting_value` = '1' WHERE `setting_key` = 'quote_next';
UPDATE `settings` SET `setting_value` = '1' WHERE `setting_key` = 'purchase_order_next';
UPDATE `settings` SET `setting_value` = '1' WHERE `setting_key` = 'sale_next';

-- Verificación
SELECT 'users' AS tabla, COUNT(*) AS filas FROM `users`
UNION ALL SELECT 'settings', COUNT(*) FROM `settings`
UNION ALL SELECT 'accounting_accounts', COUNT(*) FROM `accounting_accounts`
UNION ALL SELECT 'products', COUNT(*) FROM `products`
UNION ALL SELECT 'sales', COUNT(*) FROM `sales`
UNION ALL SELECT 'purchase_orders', COUNT(*) FROM `purchase_orders`
UNION ALL SELECT 'cash_sessions', COUNT(*) FROM `cash_sessions`;

SELECT `id`, `name`, `email`, `role`, `status` FROM `users`;
