-- Migración audit_logs: columnas entity_type / entity_id para TenantAuditService
-- Ejecutar en la BD del tenant (bellezat_erp) si los logs de auditoría fallan silenciosamente.

ALTER TABLE `audit_logs`
    ADD COLUMN IF NOT EXISTS `entity_type` VARCHAR(60) NULL AFTER `module`,
    ADD COLUMN IF NOT EXISTS `entity_id` INT UNSIGNED NULL AFTER `entity_type`;
