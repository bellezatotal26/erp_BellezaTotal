<?php

namespace SoftNova\Services;

/**
 * Asegura tablas/columnas operativas: compras, trazabilidad, canales e-commerce.
 * Idempotente por conexión PDO.
 */
class TenantOpsSchema
{
    /** @var \WeakMap<\PDO, bool>|null Evita reentradas en el mismo PDO sin romper FPM al reciclar IDs. */
    private static ?\WeakMap $done = null;

    public static function ensure(\PDO $db): void
    {
        self::$done ??= new \WeakMap();
        if (isset(self::$done[$db])) {
            // No re-ejecutar CREATE TABLE (DDL = COMMIT implícito en MySQL mid-TX).
            return;
        }
        self::$done[$db] = true;

        $db->exec(
            "CREATE TABLE IF NOT EXISTS purchases (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                purchase_number VARCHAR(50) NOT NULL,
                supplier_id INT UNSIGNED NULL,
                user_id INT UNSIGNED NULL,
                purchase_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                payment_method VARCHAR(50) DEFAULT 'cash',
                payment_status ENUM('paid','pending','partial') DEFAULT 'paid',
                notes TEXT NULL,
                status ENUM('completed','cancelled') DEFAULT 'completed',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_purchase_number (purchase_number),
                KEY idx_supplier (supplier_id),
                KEY idx_date (purchase_date),
                KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS purchase_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                purchase_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NULL,
                product_name VARCHAR(255) NOT NULL,
                quantity INT NOT NULL DEFAULT 1,
                unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_purchase (purchase_id),
                KEY idx_product (product_id),
                CONSTRAINT fk_purchase_items_purchase FOREIGN KEY (purchase_id)
                    REFERENCES purchases(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::addColumnIfMissing($db, 'stock_movements', 'movement_date',
            'DATETIME NULL DEFAULT NULL AFTER created_at');
        // Rellenar movement_date con created_at donde falte
        try {
            $db->exec('UPDATE stock_movements SET movement_date = created_at WHERE movement_date IS NULL');
        } catch (\Throwable $e) {
            // ignore
        }

        self::addColumnIfMissing($db, 'products', 'source_channel',
            "VARCHAR(40) NOT NULL DEFAULT 'manual' AFTER status");
        self::addColumnIfMissing($db, 'products', 'external_source',
            'VARCHAR(40) NULL AFTER source_channel');
        self::addColumnIfMissing($db, 'products', 'external_id',
            'VARCHAR(120) NULL AFTER external_source');
        self::addColumnIfMissing($db, 'sale_items', 'unit_cost',
            'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unit_price');

        // IVA por producto + snapshot en líneas de venta/cotización
        self::addColumnIfMissing($db, 'products', 'tax_rate',
            'DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER sale_price');
        self::addColumnIfMissing($db, 'sale_items', 'tax_rate',
            'DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER subtotal');
        self::addColumnIfMissing($db, 'sale_items', 'tax_amount',
            'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER tax_rate');
        self::addColumnIfMissing($db, 'sale_items', 'discount_amount',
            'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER tax_amount');
        self::addColumnIfMissing($db, 'sale_items', 'taxable',
            'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER discount_amount');
        self::addColumnIfMissing($db, 'quote_items', 'tax_rate',
            'DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER subtotal');
        self::addColumnIfMissing($db, 'quote_items', 'tax_amount',
            'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER tax_rate');

        // Unificar payment_method a VARCHAR (evita ENUM frágil en hosting restringido)
        self::paymentMethodToVarchar($db, 'sales');
        self::paymentMethodToVarchar($db, 'expenses');

        self::ensurePayrollSchema($db);
    }

    private static function ensurePayrollSchema(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS employees (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                document_type VARCHAR(20) NOT NULL DEFAULT 'CC',
                document_number VARCHAR(40) NOT NULL,
                first_name VARCHAR(120) NOT NULL,
                last_name VARCHAR(120) NOT NULL DEFAULT '',
                email VARCHAR(180) NULL,
                phone VARCHAR(40) NULL,
                position_title VARCHAR(120) NULL,
                hire_date DATE NULL,
                salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                contract_type VARCHAR(40) NOT NULL DEFAULT 'indefinido',
                payment_method VARCHAR(50) NOT NULL DEFAULT 'transfer',
                bank_account VARCHAR(80) NULL,
                has_transport_aid TINYINT(1) NOT NULL DEFAULT 1,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_employee_doc (document_type, document_number),
                KEY idx_employee_status (status),
                KEY idx_employee_name (last_name, first_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Migración: instalaciones viejas pueden tener `employees` incompleta.
        foreach ([
            ['employees', 'document_type', "VARCHAR(20) NOT NULL DEFAULT 'CC'"],
            ['employees', 'document_number', "VARCHAR(40) NOT NULL DEFAULT ''"],
            ['employees', 'first_name', "VARCHAR(120) NOT NULL DEFAULT ''"],
            ['employees', 'last_name', "VARCHAR(120) NOT NULL DEFAULT ''"],
            ['employees', 'email', 'VARCHAR(180) NULL'],
            ['employees', 'phone', 'VARCHAR(40) NULL'],
            ['employees', 'position_title', 'VARCHAR(120) NULL'],
            ['employees', 'hire_date', 'DATE NULL'],
            ['employees', 'salary', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00'],
            ['employees', 'contract_type', "VARCHAR(40) NOT NULL DEFAULT 'indefinido'"],
            ['employees', 'payment_method', "VARCHAR(50) NOT NULL DEFAULT 'transfer'"],
            ['employees', 'bank_account', 'VARCHAR(80) NULL'],
            ['employees', 'has_transport_aid', 'TINYINT(1) NOT NULL DEFAULT 1'],
            ['employees', 'status', "ENUM('active','inactive') NOT NULL DEFAULT 'active'"],
            ['employees', 'notes', 'TEXT NULL'],
        ] as [$table, $col, $def]) {
            self::addColumnIfMissing($db, $table, $col, $def);
        }

        // Verificación explícita: sin last_name el módulo de nómina falla al ordenar.
        try {
            $hasLast = $db->query("SHOW COLUMNS FROM `employees` LIKE 'last_name'")->fetch();
            if (!$hasLast) {
                $db->exec("ALTER TABLE `employees` ADD COLUMN `last_name` VARCHAR(120) NOT NULL DEFAULT ''");
            }
            $hasFirst = $db->query("SHOW COLUMNS FROM `employees` LIKE 'first_name'")->fetch();
            if (!$hasFirst) {
                $db->exec("ALTER TABLE `employees` ADD COLUMN `first_name` VARCHAR(120) NOT NULL DEFAULT ''");
            }
        } catch (\Throwable $e) {
            error_log('Payroll schema employees names: ' . $e->getMessage());
        }

        // Legacy: si existe `name` y first_name está vacío, copiar.
        try {
            $hasName = $db->query("SHOW COLUMNS FROM `employees` LIKE 'name'")->fetch();
            if ($hasName) {
                $db->exec(
                    "UPDATE employees
                     SET first_name = TRIM(SUBSTRING_INDEX(name, ' ', 1)),
                         last_name = TRIM(SUBSTRING(name, LENGTH(SUBSTRING_INDEX(name, ' ', 1)) + 1))
                     WHERE (first_name IS NULL OR first_name = '') AND name IS NOT NULL AND name <> ''"
                );
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $db->exec(
            "CREATE TABLE IF NOT EXISTS payroll_runs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                run_number VARCHAR(50) NOT NULL,
                period_year SMALLINT UNSIGNED NOT NULL,
                period_month TINYINT UNSIGNED NOT NULL,
                period_label VARCHAR(40) NOT NULL DEFAULT '',
                pay_date DATE NOT NULL,
                days_worked TINYINT UNSIGNED NOT NULL DEFAULT 30,
                gross_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                deductions_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                net_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                employer_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                payment_method VARCHAR(50) NOT NULL DEFAULT 'transfer',
                status ENUM('draft','posted','paid','cancelled') NOT NULL DEFAULT 'draft',
                notes TEXT NULL,
                expense_id INT UNSIGNED NULL,
                user_id INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_payroll_number (run_number),
                UNIQUE KEY uq_payroll_period (period_year, period_month),
                KEY idx_payroll_status (status),
                KEY idx_payroll_pay_date (pay_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS payroll_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                payroll_run_id INT UNSIGNED NOT NULL,
                employee_id INT UNSIGNED NOT NULL,
                employee_name VARCHAR(255) NOT NULL,
                salary_base DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                days_worked TINYINT UNSIGNED NOT NULL DEFAULT 30,
                transport_aid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                other_earnings DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                health_employee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                pension_employee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                other_deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                health_employer DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                pension_employer DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                gross_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                net_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_payroll_item_run (payroll_run_id),
                KEY idx_payroll_item_employee (employee_id),
                CONSTRAINT fk_payroll_items_run FOREIGN KEY (payroll_run_id)
                    REFERENCES payroll_runs(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Extensiones: primas, cesantías, incapacidad, parafiscales / ARL
        foreach ([
            ['payroll_runs', 'include_prima', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER notes'],
            ['payroll_runs', 'include_cesantias', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER include_prima'],
            ['payroll_runs', 'include_parafiscales', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER include_cesantias'],
            ['payroll_runs', 'prima_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER employer_total'],
            ['payroll_runs', 'cesantias_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER prima_total'],
            ['payroll_runs', 'incapacity_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER cesantias_total'],
            ['payroll_runs', 'parafiscal_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER incapacity_total'],
            ['payroll_items', 'prima', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER transport_aid'],
            ['payroll_items', 'cesantias', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER prima'],
            ['payroll_items', 'cesantias_interest', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER cesantias'],
            ['payroll_items', 'incapacity_days', 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER days_worked'],
            ['payroll_items', 'incapacity_pay', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER incapacity_days'],
            ['payroll_items', 'incapacity_type', "VARCHAR(30) NOT NULL DEFAULT '' AFTER incapacity_pay"],
            ['payroll_items', 'arl_employer', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pension_employer'],
            ['payroll_items', 'caja_employer', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER arl_employer'],
            ['payroll_items', 'sena_employer', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER caja_employer'],
            ['payroll_items', 'icbf_employer', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER sena_employer'],
            ['payroll_runs', 'overtime_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER incapacity_total'],
            ['payroll_items', 'overtime_day_hours', 'DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER other_earnings'],
            ['payroll_items', 'overtime_day_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER overtime_day_hours'],
            ['payroll_items', 'overtime_night_hours', 'DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER overtime_day_amount'],
            ['payroll_items', 'overtime_night_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER overtime_night_hours'],
            ['payroll_items', 'dominical_hours', 'DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER overtime_night_amount'],
            ['payroll_items', 'dominical_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER dominical_hours'],
            ['payroll_items', 'night_surcharge_hours', 'DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER dominical_amount'],
            ['payroll_items', 'night_surcharge_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER night_surcharge_hours'],
            ['payroll_items', 'overtime_dom_day_hours', 'DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER night_surcharge_amount'],
            ['payroll_items', 'overtime_dom_day_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER overtime_dom_day_hours'],
            ['payroll_items', 'overtime_dom_night_hours', 'DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER overtime_dom_day_amount'],
            ['payroll_items', 'overtime_dom_night_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER overtime_dom_night_hours'],
        ] as [$table, $col, $def]) {
            self::addColumnIfMissing($db, $table, $col, $def);
        }

        // Horarios semanales (días en JSON: 1=lunes … 7=domingo)
        $db->exec(
            "CREATE TABLE IF NOT EXISTS payroll_schedules (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                color VARCHAR(7) NOT NULL DEFAULT '#6366f1',
                days_json TEXT NOT NULL,
                notes VARCHAR(255) NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_schedule_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::addColumnIfMissing($db, 'employees', 'schedule_id', 'INT UNSIGNED NULL AFTER status');
        // Comisión fija mensual (no salarial): suma al neto, no es base de aportes.
        self::addColumnIfMissing($db, 'employees', 'fixed_commission', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER salary');
        self::addColumnIfMissing($db, 'payroll_items', 'commission_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER transport_aid');
        self::addColumnIfMissing($db, 'payroll_runs', 'commission_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER overtime_total');
        // Provisión de vacaciones (no suma al neto; se contabiliza en 510539 / 261015).
        self::addColumnIfMissing($db, 'payroll_items', 'vacation_provision', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER cesantias_interest');
        self::addColumnIfMissing($db, 'payroll_runs', 'vacation_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER cesantias_total');
        self::addColumnIfMissing($db, 'payroll_runs', 'include_vacaciones', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER include_cesantias');
    }

    private static function addColumnIfMissing(\PDO $db, string $table, string $column, string $definition): void
    {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($column));
            if ($stmt && $stmt->fetch()) {
                return;
            }
            try {
                $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            } catch (\Throwable $e) {
                // Si falla por AFTER/columna ancla, reintentar sin posición.
                $bare = preg_replace('/\s+AFTER\s+`.+?`/i', '', $definition) ?? $definition;
                $bare = preg_replace('/\s+AFTER\s+\w+/i', '', $bare) ?? $bare;
                $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$bare}");
            }
        } catch (\Throwable $e) {
            error_log("TenantOpsSchema ADD {$table}.{$column}: " . $e->getMessage());
        }
    }

    private static function paymentMethodToVarchar(\PDO $db, string $table): void
    {
        try {
            $col = $db->query("SHOW COLUMNS FROM `{$table}` LIKE 'payment_method'")->fetch();
            if (!$col) {
                return;
            }
            $type = strtolower((string)($col['Type'] ?? ''));
            if (str_starts_with($type, 'varchar')) {
                return;
            }
            $db->exec(
                "ALTER TABLE `{$table}` MODIFY COLUMN payment_method VARCHAR(50) DEFAULT 'cash'"
            );
        } catch (\Throwable $e) {
            // Fallback: ampliar ENUM si no se puede convertir a VARCHAR
            try {
                $db->exec(
                    "ALTER TABLE `{$table}` MODIFY COLUMN payment_method
                     ENUM('cash','card','debit_card','credit_card','dataphone','payment_link','transfer','credit','other')
                     DEFAULT 'cash'"
                );
            } catch (\Throwable $e2) {
                // silencioso
            }
        }
    }
}
