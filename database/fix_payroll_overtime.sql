-- Nómina: horas extras (HED/HEN) y recargo dominical/festivo
-- Ejecutar en phpMyAdmin sobre la BD del tenant (bellezat_erp, etc.)
-- Idempotente: omita ALTER si la columna ya existe (#1060 Duplicate column)

-- Paso 1: columnas en payroll_runs
ALTER TABLE payroll_runs
    ADD COLUMN overtime_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER incapacity_total;

-- Paso 2: columnas en payroll_items
ALTER TABLE payroll_items
    ADD COLUMN overtime_day_hours DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER other_earnings,
    ADD COLUMN overtime_day_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER overtime_day_hours,
    ADD COLUMN overtime_night_hours DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER overtime_day_amount,
    ADD COLUMN overtime_night_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER overtime_night_hours,
    ADD COLUMN dominical_hours DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER overtime_night_amount,
    ADD COLUMN dominical_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER dominical_hours;

-- Paso 3: parámetros de recargo (accounting_settings)
INSERT IGNORE INTO accounting_settings (setting_key, setting_value) VALUES
    ('payroll_monthly_hours', '240'),
    ('overtime_day_rate', '25'),
    ('overtime_night_rate', '75'),
    ('dominical_rate', '75');
