-- =============================================================================
-- Fix: códigos de barras / SKU largos se cortaban o se alteraban al importar
-- Ejecutar en phpMyAdmin sobre la BD del tenant (ej. heracons_seri)
-- Fecha: 2026-07-31
-- =============================================================================
-- Causa: products.code era VARCHAR(50). Además Excel convierte EAN/SKU largos
-- a notación científica (6.95287E+12) y al reimportar se pierde precisión.
-- =============================================================================

ALTER TABLE `products`
  MODIFY COLUMN `code` VARCHAR(255) DEFAULT NULL;

-- Opcional: localizar productos ya corruptos por Excel (revisión manual)
-- SELECT id, code, name FROM products
-- WHERE code REGEXP '^[0-9]+(\\.[0-9]+)?[eE][+-]?[0-9]+$'
--    OR code REGEXP '^[0-9]+000{3,}$';
