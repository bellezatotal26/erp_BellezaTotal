-- Nómina: horas extras / recargos Colombia (HED, HEN, RNO, Dom, HEDD, HEND)
-- Ejecutar en phpMyAdmin sobre la BD del tenant si TenantOpsSchema no corrió aún.
-- Idempotente: omita ALTER si la columna ya existe (#1060 Duplicate column)

ALTER TABLE payroll_runs
    ADD COLUMN overtime_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER incapacity_total;

ALTER TABLE payroll_items
    ADD COLUMN overtime_day_hours DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER other_earnings,
    ADD COLUMN overtime_day_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER overtime_day_hours,
    ADD COLUMN overtime_night_hours DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER overtime_day_amount,
    ADD COLUMN overtime_night_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER overtime_night_hours,
    ADD COLUMN dominical_hours DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER overtime_night_amount,
    ADD COLUMN dominical_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER dominical_hours,
    ADD COLUMN night_surcharge_hours DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER dominical_amount,
    ADD COLUMN night_surcharge_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER night_surcharge_hours,
    ADD COLUMN overtime_dom_day_hours DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER night_surcharge_amount,
    ADD COLUMN overtime_dom_day_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER overtime_dom_day_hours,
    ADD COLUMN overtime_dom_night_hours DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER overtime_dom_day_amount,
    ADD COLUMN overtime_dom_night_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER overtime_dom_night_hours;

INSERT IGNORE INTO accounting_settings (setting_key, setting_value) VALUES
    ('payroll_monthly_hours', '230'),
    ('overtime_day_rate', '25'),
    ('overtime_night_rate', '75'),
    ('night_surcharge_rate', '35'),
    ('dominical_rate', '75'),
    ('overtime_dom_day_rate', '100'),
    ('overtime_dom_night_rate', '150');
