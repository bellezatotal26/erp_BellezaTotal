-- =============================================================================
-- Alias / copia amigable del wipe operativo (phpMyAdmin, SIN DELIMITER)
-- Preferido: database/wipe_operational_bellezat.sql
-- =============================================================================
-- Este archivo incluye además: forzar email admin@bellezatotal.co
-- y contraseña temporal Admin123! (cámbiela al entrar).
-- Si solo quiere vaciar datos SIN tocar login, use wipe_operational_bellezat.sql
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

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

TRUNCATE TABLE `stock_movement_lots`;
TRUNCATE TABLE `stock_movements`;
TRUNCATE TABLE `product_lots`;
TRUNCATE TABLE `product_suppliers`;
TRUNCATE TABLE `product_stock_debts`;
TRUNCATE TABLE `product_offer_log`;
TRUNCATE TABLE `products`;
TRUNCATE TABLE `categories`;
TRUNCATE TABLE `notification_dismissals`;

TRUNCATE TABLE `customers`;
TRUNCATE TABLE `suppliers`;
TRUNCATE TABLE `expenses`;
TRUNCATE TABLE `expense_categories`;
TRUNCATE TABLE `expense_subgroups`;

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

TRUNCATE TABLE `consignment_settlement_items`;
TRUNCATE TABLE `consignment_settlements`;
TRUNCATE TABLE `consignment_sale_debts`;
TRUNCATE TABLE `consignment_receipt_items`;
TRUNCATE TABLE `consignment_receipts`;
TRUNCATE TABLE `consignors`;

TRUNCATE TABLE `payroll_items`;
TRUNCATE TABLE `payroll_runs`;
TRUNCATE TABLE `employees`;

TRUNCATE TABLE `accounting_lines`;
TRUNCATE TABLE `accounting_entries`;
TRUNCATE TABLE `accounting_periods`;

TRUNCATE TABLE `ai_messages`;
TRUNCATE TABLE `ai_conversations`;
TRUNCATE TABLE `jobs`;
TRUNCATE TABLE `audit_logs`;
TRUNCATE TABLE `tenant_notifications`;
TRUNCATE TABLE `custom_select_options`;
TRUNCATE TABLE `integration_settings`;
TRUNCATE TABLE `ecommerce_settings`;

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

UPDATE `users`
SET
  `email` = 'admin@bellezatotal.co',
  `name` = COALESCE(NULLIF(`name`, ''), 'Administrador'),
  `password` = '$2y$10$4SaKTOic3dqjlCECiawL9u/68HsTaR1LFqAH3bUolDhG.rI4N8lyq',
  `role` = 'admin',
  `status` = 'active',
  `updated_at` = NOW()
WHERE `id` = (
  SELECT `id` FROM (
    SELECT MIN(`id`) AS `id` FROM `users` WHERE `role` = 'admin'
  ) AS t
);

INSERT INTO `users` (`name`, `email`, `password`, `role`, `status`)
SELECT
  'Administrador',
  'admin@bellezatotal.co',
  '$2y$10$4SaKTOic3dqjlCECiawL9u/68HsTaR1LFqAH3bUolDhG.rI4N8lyq',
  'admin',
  'active'
WHERE NOT EXISTS (
  SELECT 1 FROM `users` WHERE `email` = 'admin@bellezatotal.co'
);

DELETE FROM `users`
WHERE `email` <> 'admin@bellezatotal.co';

UPDATE `settings` SET `setting_value` = '1' WHERE `setting_key` = 'barcode_next';
UPDATE `settings` SET `setting_value` = '1' WHERE `setting_key` = 'sku_next';
UPDATE `settings` SET `setting_value` = '1' WHERE `setting_key` = 'invoice_next';
UPDATE `settings` SET `setting_value` = '1' WHERE `setting_key` = 'quote_next';
UPDATE `settings` SET `setting_value` = '1' WHERE `setting_key` = 'purchase_order_next';
UPDATE `settings` SET `setting_value` = '1' WHERE `setting_key` = 'sale_next';

SELECT `id`, `name`, `email`, `role`, `status` FROM `users`;
