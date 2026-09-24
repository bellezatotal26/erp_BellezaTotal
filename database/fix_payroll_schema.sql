-- Migración nómina: esquema legacy → PayrollService actual
-- Preferible: php database/migrate.php (paso 7, idempotente)
--
-- Cambios principales:
--   payroll_runs: total_* → gross_total/deductions_total/net_total, run_number, period_label,
--                 days_worked, employer_total, payment_method, user_id, expense_id, status + posted
--   payroll_items: base_salary → salary_base + columnas de aportes/cotización
--   employees: salary desde base_salary si aplica

-- Este archivo es referencia; la lógica condicional vive en migrate.php (migrateStepPayrollSchema).
