<?php

namespace SoftNova\Services;

/**
 * Asegura tablas/columnas operativas: compras, trazabilidad, canales e-commerce.
 * Idempotente por conexión PDO.
 */
class TenantOpsSchema
{
    public static function ensure(\PDO $db): void
    {
        static $done = [];
        $oid = spl_object_id($db);
        if (isset($done[$oid])) {
            return;
        }
        $done[$oid] = true;

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
        ] as [$table, $col, $def]) {
            self::addColumnIfMissing($db, $table, $col, $def);
        }
    }

    private static function addColumnIfMissing(\PDO $db, string $table, string $column, string $definition): void
    {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($column));
            if ($stmt && $stmt->fetch()) {
                return;
            }
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (\Throwable $e) {
            // silencioso
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
