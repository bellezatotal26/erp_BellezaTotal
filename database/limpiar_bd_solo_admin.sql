-- ============================================================
-- Seri ERP — Limpiar BD (solo queda el usuario admin)
-- Ejecutar en phpMyAdmin sobre la base de datos del sistema.
-- ============================================================
-- ADVERTENCIA: borra TODOS los datos (ventas, inventario, compras, etc.).
-- Conserva:
--   - Catálogo contable (accounting_accounts / accounting_settings)
--   - Preferencias (settings)
--   - Un único usuario con rol admin
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Procedimiento auxiliar: TRUNCATE solo si la tabla existe
DROP PROCEDURE IF EXISTS `seri_truncate_if_exists`;
DELIMITER $$
CREATE PROCEDURE `seri_truncate_if_exists`(IN p_table VARCHAR(64))
BEGIN
  IF EXISTS (
    SELECT 1
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
  ) THEN
    SET @sql = CONCAT('TRUNCATE TABLE `', p_table, '`');
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

-- ---- Ventas / caja / CxC ----
CALL seri_truncate_if_exists('sale_items');
CALL seri_truncate_if_exists('sale_payments');
CALL seri_truncate_if_exists('sales');
CALL seri_truncate_if_exists('sale_credit_note_items');
CALL seri_truncate_if_exists('sale_credit_notes');
CALL seri_truncate_if_exists('receivables');
CALL seri_truncate_if_exists('cash_movements');
CALL seri_truncate_if_exists('cash_sessions');
CALL seri_truncate_if_exists('quote_items');
CALL seri_truncate_if_exists('quotes');
CALL seri_truncate_if_exists('sales_commissions');
CALL seri_truncate_if_exists('user_commission_rates');

-- ---- Inventario ----
CALL seri_truncate_if_exists('stock_movement_lots');
CALL seri_truncate_if_exists('stock_movements');
CALL seri_truncate_if_exists('product_lots');
CALL seri_truncate_if_exists('product_suppliers');
CALL seri_truncate_if_exists('product_stock_debts');
CALL seri_truncate_if_exists('product_offer_log');
CALL seri_truncate_if_exists('products');
CALL seri_truncate_if_exists('categories');
CALL seri_truncate_if_exists('notification_dismissals');

-- ---- Clientes / proveedores / gastos ----
CALL seri_truncate_if_exists('customers');
CALL seri_truncate_if_exists('suppliers');
CALL seri_truncate_if_exists('expenses');
CALL seri_truncate_if_exists('expense_categories');

-- ---- Compras ----
CALL seri_truncate_if_exists('purchase_credit_note_items');
CALL seri_truncate_if_exists('purchase_credit_notes');
CALL seri_truncate_if_exists('supplier_payments');
CALL seri_truncate_if_exists('purchase_order_items');
CALL seri_truncate_if_exists('purchase_orders');
CALL seri_truncate_if_exists('purchase_invoice_import_items');
CALL seri_truncate_if_exists('purchase_invoice_imports');
CALL seri_truncate_if_exists('replenishment_draft_items');
CALL seri_truncate_if_exists('purchase_items');
CALL seri_truncate_if_exists('purchases');

-- ---- Nómina ----
CALL seri_truncate_if_exists('payroll_items');
CALL seri_truncate_if_exists('payroll_runs');
CALL seri_truncate_if_exists('employees');

-- ---- Contabilidad (movimientos; se conserva el PUC) ----
CALL seri_truncate_if_exists('accounting_lines');
CALL seri_truncate_if_exists('accounting_entries');
CALL seri_truncate_if_exists('accounting_periods');

-- ---- IA / jobs / auditoría / extras ----
CALL seri_truncate_if_exists('ai_messages');
CALL seri_truncate_if_exists('ai_conversations');
CALL seri_truncate_if_exists('jobs');
CALL seri_truncate_if_exists('audit_logs');
CALL seri_truncate_if_exists('tenant_notifications');
CALL seri_truncate_if_exists('custom_select_options');

DROP PROCEDURE IF EXISTS `seri_truncate_if_exists`;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Dejar solo un usuario admin
-- ============================================================

-- Actualiza el admin principal Y fuerza contraseña temporal: Admin123!
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

-- Si no existía admin, crea uno (contraseña temporal: Admin123!)
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

-- Elimina el resto de usuarios
DELETE FROM `users`
WHERE `email` <> 'admin@bellezatotal.co';

-- Verificación final
SELECT `id`, `name`, `email`, `role`, `status` FROM `users`;
