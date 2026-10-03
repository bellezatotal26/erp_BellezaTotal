<?php
/**
 * Migración unificada — ERP standalone (un solo cliente, sin SaaS).
 *
 * Aplica en orden todos los parches de database/ + schema operativo (nómina, compras).
 * Idempotente: se puede ejecutar varias veces.
 *
 * Pasos: consignación · auditoría · productos · perf · ops schema · nómina payroll · IVA producto
 *
 * Uso (desde la raíz del proyecto):
 *   php database/migrate.php
 *
 * En cPanel / SSH del servidor:
 *   cd /home/bellezat/software.bellezatotal.co && php database/migrate.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// ---------------------------------------------------------------------------
// Conexión
// ---------------------------------------------------------------------------

function migrateLoadEnv(string $root): void
{
    $envFile = $root . '/.env';
    if (!is_file($envFile)) {
        return;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, "\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
}

function migrateResolveDbConfig(string $root): array
{
    migrateLoadEnv($root);

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_DATABASE') ?: getenv('DB_NAME') ?: '';
    $user = getenv('DB_USERNAME') ?: getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASSWORD') ?: getenv('DB_PASS') ?: '';

    if (is_file($root . '/config/database.php')) {
        $cfg = require $root . '/config/database.php';
        $def = null;
        if (isset($cfg['database']['default']) && is_array($cfg['database']['default'])) {
            $def = $cfg['database']['default'];
        } elseif (isset($cfg['default']) && is_array($cfg['default'])) {
            $def = $cfg['default'];
        } elseif (isset($cfg['host'])) {
            $def = $cfg;
        }
        if (is_array($def)) {
            $host = (string)($def['host'] ?? $host);
            $port = (string)($def['port'] ?? $port);
            $name = (string)($def['database'] ?? $name);
            $user = (string)($def['username'] ?? $user);
            $pass = (string)($def['password'] ?? $pass);
        }
    }

    return compact('host', 'port', 'name', 'user', 'pass');
}

function migrateConnect(string $root): PDO
{
    $c = migrateResolveDbConfig($root);
    extract($c);

    if ($name === '') {
        $msg = "Error: no se pudo determinar DB_DATABASE.\n";
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $msg);
        } else {
            echo $msg;
        }
        exit(1);
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Zona horaria de negocio (Bogotá por defecto; settings.timezone si existe).
    $tz = 'America/Bogota';
    try {
        if (is_file($root . '/vendor/autoload.php')) {
            require_once $root . '/vendor/autoload.php';
            if (class_exists(\SoftNova\Core\BusinessTimezone::class)) {
                $tz = \SoftNova\Core\BusinessTimezone::fromSettings($pdo);
                \SoftNova\Core\BusinessTimezone::applyPhp($tz);
                \SoftNova\Core\BusinessTimezone::applyToPdo($pdo, $tz);
            } else {
                date_default_timezone_set($tz);
                $offset = (new DateTime('now', new DateTimeZone($tz)))->format('P');
                $pdo->exec("SET time_zone = '{$offset}'");
            }
        } else {
            date_default_timezone_set($tz);
            $offset = (new DateTime('now', new DateTimeZone($tz)))->format('P');
            $pdo->exec("SET time_zone = '{$offset}'");
        }
    } catch (Throwable $e) {
        @date_default_timezone_set($tz);
    }

    echo "=== Seri ERP migrate ({$name} @ {$host}) tz={$tz} ===\n\n";

    return $pdo;
}

// ---------------------------------------------------------------------------
// Helpers idempotentes
// ---------------------------------------------------------------------------

function migrateColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?');
    $stmt->execute([$column]);
    return (bool)$stmt->fetch();
}

function migrateIndexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
         LIMIT 1'
    );
    $stmt->execute([$table, $index]);
    return (bool)$stmt->fetchColumn();
}

function migrateAddColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    if (migrateColumnExists($pdo, $table, $column)) {
        echo "  OK columna {$table}.{$column} (ya existe)\n";
        return;
    }
    try {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        echo "  CREADA columna {$table}.{$column}\n";
    } catch (Throwable $e) {
        $bare = preg_replace('/\s+AFTER\s+`.+?`/i', '', $definition) ?? $definition;
        $bare = preg_replace('/\s+AFTER\s+\w+/i', '', $bare) ?? $bare;
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$bare}");
        echo "  CREADA columna {$table}.{$column} (sin AFTER)\n";
    }
}

function migrateEnsureIndex(PDO $pdo, string $table, string $index, string $columns): void
{
    if (migrateIndexExists($pdo, $table, $index)) {
        echo "  OK índice {$table}.{$index}\n";
        return;
    }
    try {
        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$columns})");
        echo "  CREADO índice {$table}.{$index}\n";
    } catch (Throwable $e) {
        echo "  SKIP índice {$table}.{$index}: " . $e->getMessage() . "\n";
    }
}

function migrateRunSqlFile(PDO $pdo, string $path): void
{
    if (!is_file($path)) {
        echo "  SKIP archivo no encontrado: {$path}\n";
        return;
    }

    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        echo "  SKIP archivo vacío: " . basename($path) . "\n";
        return;
    }

    // Quitar comentarios de línea
    $lines = preg_split('/\R/', $sql) ?: [];
    $buffer = '';
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if ($trim === '' || str_starts_with($trim, '--')) {
            continue;
        }
        $buffer .= $line . "\n";
    }

    $statements = array_filter(
        array_map('trim', explode(';', $buffer)),
        static fn(string $s): bool => $s !== ''
    );

    foreach ($statements as $stmt) {
        try {
            $pdo->exec($stmt);
            $preview = preg_replace('/\s+/', ' ', substr($stmt, 0, 72)) ?? $stmt;
            echo "  OK " . $preview . "…\n";
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate') !== false || stripos($msg, 'already exists') !== false) {
                echo "  OK (ya aplicado) " . substr($stmt, 0, 48) . "…\n";
                continue;
            }
            echo "  ERROR: {$msg}\n";
            echo "    SQL: " . substr(preg_replace('/\s+/', ' ', $stmt) ?? $stmt, 0, 120) . "\n";
        }
    }
}

// ---------------------------------------------------------------------------
// Pasos
// ---------------------------------------------------------------------------

function migrateStepConsignmentColumns(PDO $pdo): void
{
    echo "[2/7] Consignación — columnas en products / sale_items\n";

    migrateAddColumn($pdo, 'products', 'ownership', "VARCHAR(20) NOT NULL DEFAULT 'owned' AFTER product_type");
    migrateAddColumn($pdo, 'products', 'consignor_id', 'INT UNSIGNED NULL AFTER ownership');
    migrateEnsureIndex($pdo, 'products', 'idx_products_ownership', '`ownership`');
    migrateEnsureIndex($pdo, 'products', 'idx_products_consignor', '`consignor_id`');

    migrateAddColumn($pdo, 'sale_items', 'is_consignment', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER unit_cost');
    migrateAddColumn($pdo, 'sale_items', 'consignor_id', 'INT UNSIGNED NULL AFTER is_consignment');

    migrateEnsureIndex($pdo, 'consignors', 'idx_consignor_doc', '`document_type`, `document_number`');
    migrateAddColumn($pdo, 'consignors', 'supplier_id', 'INT UNSIGNED NULL AFTER id');
    if (!migrateIndexExists($pdo, 'consignors', 'idx_consignor_supplier')) {
        try {
            $pdo->exec('ALTER TABLE `consignors` ADD UNIQUE INDEX `idx_consignor_supplier` (`supplier_id`)');
            echo "  CREADO índice único consignors.idx_consignor_supplier\n";
        } catch (Throwable $e) {
            echo "  SKIP índice consignors.idx_consignor_supplier: " . $e->getMessage() . "\n";
        }
    } else {
        echo "  OK índice consignors.idx_consignor_supplier\n";
    }
    echo "\n";
}

function migrateStepStandalonePerf(PDO $pdo): void
{
    echo "[5/7] Rendimiento + tablas auxiliares\n";

    migrateEnsureIndex($pdo, 'sales', 'idx_sale_date', '`sale_date`');
    migrateEnsureIndex($pdo, 'sales', 'idx_sales_date', '`sale_date`');
    migrateEnsureIndex($pdo, 'products', 'idx_code', '`code`');
    migrateEnsureIndex($pdo, 'cash_movements', 'idx_session', '`cash_session_id`');
    migrateEnsureIndex($pdo, 'cash_movements', 'idx_cash_session_id', '`cash_session_id`');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `jobs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `queue` VARCHAR(60) NOT NULL DEFAULT 'default',
            `job_type` VARCHAR(80) NOT NULL,
            `payload` LONGTEXT NULL,
            `status` ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `max_attempts` INT UNSIGNED NOT NULL DEFAULT 5,
            `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `reserved_at` DATETIME NULL,
            `finished_at` DATETIME NULL,
            `last_error` TEXT NULL,
            `created_by` INT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_jobs_status_avail` (`status`,`available_at`),
            KEY `idx_jobs_type` (`job_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  OK tabla jobs\n";

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `audit_logs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NULL,
            `user_name` VARCHAR(120) NULL,
            `action` VARCHAR(40) NOT NULL,
            `module` VARCHAR(60) NOT NULL,
            `entity_type` VARCHAR(60) NULL,
            `entity_id` INT UNSIGNED NULL,
            `description` TEXT NULL,
            `ip_address` VARCHAR(45) NULL,
            `user_agent` VARCHAR(255) NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_audit_module` (`module`),
            KEY `idx_audit_user` (`user_id`),
            KEY `idx_audit_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  OK tabla audit_logs\n";

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `tenant_notifications` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `notif_key` VARCHAR(80) NOT NULL,
            `type` VARCHAR(40) NOT NULL DEFAULT 'system',
            `title` VARCHAR(160) NOT NULL,
            `message` VARCHAR(500) NOT NULL,
            `url` VARCHAR(255) NULL,
            `urgent` TINYINT(1) NOT NULL DEFAULT 0,
            `meta` VARCHAR(120) NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_notif_key` (`notif_key`),
            KEY `idx_notif_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  OK tabla tenant_notifications\n\n";
}

function migrateTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function migrateRenameColumn(PDO $pdo, string $table, string $from, string $to, string $definition): void
{
    if (!migrateColumnExists($pdo, $table, $from)) {
        if (migrateColumnExists($pdo, $table, $to)) {
            echo "  OK {$table}.{$to} (ya existe)\n";
        }
        return;
    }
    if (migrateColumnExists($pdo, $table, $to)) {
        echo "  OK {$table}.{$to} (ya existe; legacy {$from} conservada)\n";
        return;
    }
    $pdo->exec("ALTER TABLE `{$table}` CHANGE COLUMN `{$from}` `{$to}` {$definition}");
    echo "  RENOMBRADA {$table}.{$from} → {$to}\n";
}

function migrateModifyEnum(PDO $pdo, string $table, string $column, string $enumSql): void
{
    if (!migrateColumnExists($pdo, $table, $column)) {
        return;
    }
    try {
        $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$enumSql}");
        echo "  OK {$table}.{$column} enum actualizado\n";
    } catch (Throwable $e) {
        echo "  SKIP {$table}.{$column}: " . $e->getMessage() . "\n";
    }
}

function migrateStepPayrollSchema(PDO $pdo): void
{
    echo "[7/7] Nómina — alinear payroll_runs / payroll_items\n";

    if (!migrateTableExists($pdo, 'payroll_runs')) {
        echo "  SKIP payroll_runs no existe\n\n";
        return;
    }

    migrateRenameColumn(
        $pdo,
        'payroll_runs',
        'total_gross',
        'gross_total',
        'DECIMAL(12,2) NOT NULL DEFAULT 0.00'
    );
    migrateRenameColumn(
        $pdo,
        'payroll_runs',
        'total_deductions',
        'deductions_total',
        'DECIMAL(12,2) NOT NULL DEFAULT 0.00'
    );
    migrateRenameColumn(
        $pdo,
        'payroll_runs',
        'total_net',
        'net_total',
        'DECIMAL(12,2) NOT NULL DEFAULT 0.00'
    );

    migrateAddColumn($pdo, 'payroll_runs', 'gross_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
    migrateAddColumn($pdo, 'payroll_runs', 'deductions_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
    migrateAddColumn($pdo, 'payroll_runs', 'net_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
    migrateAddColumn($pdo, 'payroll_runs', 'employer_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
    migrateAddColumn($pdo, 'payroll_runs', 'run_number', "VARCHAR(50) NOT NULL DEFAULT ''");
    migrateAddColumn($pdo, 'payroll_runs', 'period_label', "VARCHAR(40) NOT NULL DEFAULT ''");
    migrateAddColumn($pdo, 'payroll_runs', 'days_worked', 'TINYINT UNSIGNED NOT NULL DEFAULT 30');
    migrateAddColumn($pdo, 'payroll_runs', 'payment_method', "VARCHAR(50) NOT NULL DEFAULT 'transfer'");
    migrateAddColumn($pdo, 'payroll_runs', 'user_id', 'INT UNSIGNED NULL');
    migrateAddColumn($pdo, 'payroll_runs', 'expense_id', 'INT UNSIGNED NULL');
    migrateAddColumn($pdo, 'payroll_runs', 'updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    migrateAddColumn($pdo, 'payroll_runs', 'include_prima', 'TINYINT(1) NOT NULL DEFAULT 0');
    migrateAddColumn($pdo, 'payroll_runs', 'include_cesantias', 'TINYINT(1) NOT NULL DEFAULT 0');
    migrateAddColumn($pdo, 'payroll_runs', 'include_parafiscales', 'TINYINT(1) NOT NULL DEFAULT 1');
    migrateAddColumn($pdo, 'payroll_runs', 'prima_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
    migrateAddColumn($pdo, 'payroll_runs', 'cesantias_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
    migrateAddColumn($pdo, 'payroll_runs', 'incapacity_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
    migrateAddColumn($pdo, 'payroll_runs', 'overtime_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
    migrateAddColumn($pdo, 'payroll_runs', 'parafiscal_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');

    try {
        if (migrateColumnExists($pdo, 'payroll_runs', 'created_by') && migrateColumnExists($pdo, 'payroll_runs', 'user_id')) {
            $pdo->exec('UPDATE payroll_runs SET user_id = created_by WHERE user_id IS NULL AND created_by IS NOT NULL');
            echo "  OK payroll_runs.user_id ← created_by\n";
        }
        $pdo->exec(
            "UPDATE payroll_runs
             SET run_number = CONCAT('NOM-', period_year, LPAD(period_month, 2, '0'))
             WHERE run_number IS NULL OR run_number = ''"
        );
        $pdo->exec(
            "UPDATE payroll_runs
             SET period_label = CONCAT(LPAD(period_month, 2, '0'), '/', period_year)
             WHERE period_label IS NULL OR period_label = ''"
        );
        echo "  OK payroll_runs run_number / period_label rellenados\n";
    } catch (Throwable $e) {
        echo "  SKIP backfill payroll_runs: " . $e->getMessage() . "\n";
    }

    migrateModifyEnum(
        $pdo,
        'payroll_runs',
        'status',
        "ENUM('draft','posted','paid','cancelled') NOT NULL DEFAULT 'draft'"
    );

    migrateEnsureIndex($pdo, 'payroll_runs', 'uq_payroll_number', '`run_number`');
    migrateEnsureIndex($pdo, 'payroll_runs', 'idx_payroll_status', '`status`');
    migrateEnsureIndex($pdo, 'payroll_runs', 'idx_payroll_pay_date', '`pay_date`');

    if (migrateTableExists($pdo, 'payroll_items')) {
        migrateRenameColumn(
            $pdo,
            'payroll_items',
            'base_salary',
            'salary_base',
            'DECIMAL(12,2) NOT NULL DEFAULT 0.00'
        );
        migrateAddColumn($pdo, 'payroll_items', 'salary_base', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'employee_name', 'VARCHAR(255) NOT NULL DEFAULT \'\'');
        migrateAddColumn($pdo, 'payroll_items', 'days_worked', 'TINYINT UNSIGNED NOT NULL DEFAULT 30');
        migrateAddColumn($pdo, 'payroll_items', 'transport_aid', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'other_earnings', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'health_employee', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'pension_employee', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'other_deductions', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'health_employer', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'pension_employer', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'gross_pay', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'net_pay', 'DECIMAL(14,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'prima', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'cesantias', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'cesantias_interest', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'incapacity_days', 'TINYINT UNSIGNED NOT NULL DEFAULT 0');
        migrateAddColumn($pdo, 'payroll_items', 'incapacity_pay', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'incapacity_type', "VARCHAR(30) NOT NULL DEFAULT ''");
        migrateAddColumn($pdo, 'payroll_items', 'arl_employer', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'caja_employer', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'sena_employer', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'icbf_employer', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'overtime_day_hours', 'DECIMAL(8,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'overtime_day_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'overtime_night_hours', 'DECIMAL(8,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'overtime_night_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'dominical_hours', 'DECIMAL(8,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'dominical_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'night_surcharge_hours', 'DECIMAL(8,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'night_surcharge_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'overtime_dom_day_hours', 'DECIMAL(8,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'overtime_dom_day_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'overtime_dom_night_hours', 'DECIMAL(8,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'overtime_dom_night_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        migrateAddColumn($pdo, 'payroll_items', 'created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');

        try {
            if (migrateColumnExists($pdo, 'payroll_items', 'bonuses') && migrateColumnExists($pdo, 'payroll_items', 'gross_pay')) {
                $pdo->exec(
                    'UPDATE payroll_items
                     SET gross_pay = salary_base + COALESCE(bonuses, 0)
                     WHERE gross_pay = 0 AND salary_base > 0'
                );
            }
            if (migrateColumnExists($pdo, 'payroll_items', 'deductions') && migrateColumnExists($pdo, 'payroll_items', 'health_employee')) {
                $pdo->exec(
                    'UPDATE payroll_items
                     SET health_employee = deductions, net_pay = COALESCE(net_pay, 0)
                     WHERE health_employee = 0 AND deductions > 0'
                );
            }
            echo "  OK payroll_items legacy → columnas nuevas\n";
        } catch (Throwable $e) {
            echo "  SKIP backfill payroll_items: " . $e->getMessage() . "\n";
        }

        migrateEnsureIndex($pdo, 'payroll_items', 'idx_payroll_item_run', '`payroll_run_id`');
        migrateEnsureIndex($pdo, 'payroll_items', 'idx_payroll_item_employee', '`employee_id`');
    }

    if (migrateTableExists($pdo, 'employees')) {
        try {
            if (migrateColumnExists($pdo, 'employees', 'base_salary') && migrateColumnExists($pdo, 'employees', 'salary')) {
                $pdo->exec(
                    'UPDATE employees SET salary = base_salary WHERE (salary IS NULL OR salary = 0) AND base_salary > 0'
                );
                echo "  OK employees.salary ← base_salary\n";
            }
            if (migrateColumnExists($pdo, 'employees', 'name') && migrateColumnExists($pdo, 'employees', 'first_name')) {
                $pdo->exec(
                    "UPDATE employees
                     SET first_name = TRIM(SUBSTRING_INDEX(name, ' ', 1)),
                         last_name = TRIM(SUBSTRING(name, LENGTH(SUBSTRING_INDEX(name, ' ', 1)) + 1))
                     WHERE (first_name IS NULL OR first_name = '') AND name IS NOT NULL AND name <> ''"
                );
                echo "  OK employees nombres desde name\n";
            }
        } catch (Throwable $e) {
            echo "  SKIP employees backfill: " . $e->getMessage() . "\n";
        }
    }

    migrateEnsureIndex($pdo, 'product_stock_debts', 'idx_psd_source_status', '`source`, `status`');

    echo "\n";
}

function migrateStepOpsSchema(PDO $pdo, string $root): void
{
    echo "[6/7] Schema operativo (nómina, compras, columnas ventas)\n";

    $autoload = $root . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        echo "  SKIP TenantOpsSchema (vendor/autoload.php no encontrado)\n\n";
        return;
    }

    require_once $autoload;
    try {
        \SoftNova\Services\TenantOpsSchema::ensure($pdo);
        echo "  OK TenantOpsSchema::ensure()\n\n";
    } catch (Throwable $e) {
        echo "  ERROR TenantOpsSchema: " . $e->getMessage() . "\n\n";
    }
}

function migrateStepProductTax(PDO $pdo, string $root): void
{
    echo "[8/8] IVA por producto + columnas fiscales en sale_items / quote_items\n";

    migrateAddColumn($pdo, 'products', 'tax_rate', 'DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER sale_price');
    migrateAddColumn($pdo, 'sale_items', 'tax_rate', 'DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER subtotal');
    migrateAddColumn($pdo, 'sale_items', 'tax_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER tax_rate');
    migrateAddColumn($pdo, 'quote_items', 'tax_rate', 'DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER subtotal');
    migrateAddColumn($pdo, 'quote_items', 'tax_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER tax_rate');

    // Backfill: productos sin tasa explícita heredan la de Configuración (una sola vez).
    $defaultRate = 0.0;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'tax_rate' LIMIT 1");
        $stmt->execute();
        $raw = $stmt->fetchColumn();
        if ($raw !== false && $raw !== null && trim((string)$raw) !== '') {
            $defaultRate = max(0.0, min(100.0, (float)$raw));
        }
    } catch (Throwable $e) {
        echo "  SKIP lectura settings.tax_rate: " . $e->getMessage() . "\n";
    }

    $flag = 'product_tax_rate_backfill_v1';
    $already = false;
    try {
        $chk = $pdo->prepare("SELECT 1 FROM settings WHERE setting_key = ? LIMIT 1");
        $chk->execute([$flag]);
        $already = (bool)$chk->fetchColumn();
    } catch (Throwable $e) {
        // ignore
    }

    if (!$already && $defaultRate > 0.009 && migrateColumnExists($pdo, 'products', 'tax_rate')) {
        try {
            $upd = $pdo->prepare('UPDATE products SET tax_rate = ? WHERE tax_rate = 0 OR tax_rate IS NULL');
            $upd->execute([round($defaultRate, 2)]);
            echo "  OK products.tax_rate ← {$defaultRate}% (desde configuración)\n";
        } catch (Throwable $e) {
            echo "  SKIP backfill products.tax_rate: " . $e->getMessage() . "\n";
        }
        try {
            $pdo->prepare(
                "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            )->execute([$flag, date('c')]);
        } catch (Throwable $e) {
            try {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)")
                    ->execute([$flag, date('c'), 'Backfill IVA por producto']);
            } catch (Throwable $e2) {
                // ignore
            }
        }
    } else {
        echo "  OK backfill products.tax_rate (omitido; ya aplicado o tasa global 0)\n";
    }

    // También vía autoload si está disponible
    $autoload = $root . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        try {
            \SoftNova\Services\TaxService::ensureSchema($pdo);
            echo "  OK TaxService::ensureSchema()\n";
        } catch (Throwable $e) {
            echo "  SKIP TaxService: " . $e->getMessage() . "\n";
        }
        try {
            $bf = \SoftNova\Services\TaxService::backfillSaleItemTaxes($pdo);
            echo '  OK backfill sale_items IVA: ventas=' . (int)($bf['sales'] ?? 0)
                . ' ítems=' . (int)($bf['items'] ?? 0)
                . ' omitidos=' . (int)($bf['skipped'] ?? 0) . "\n";
        } catch (Throwable $e) {
            echo "  SKIP backfill sale_items IVA: " . $e->getMessage() . "\n";
        }
    }

    echo "\n";
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$dbDir = __DIR__;
$pdo = migrateConnect($root);

echo "[1/8] Consignación — tablas\n";
migrateRunSqlFile($pdo, $dbDir . '/fix_consignacion_schema.sql');
echo "\n";

migrateStepConsignmentColumns($pdo);

echo "[3/8] Auditoría — columnas legacy\n";
migrateRunSqlFile($pdo, $dbDir . '/fix_audit_logs_schema.sql');
echo "\n";

echo "[4/8] Productos — código/barcode largo\n";
migrateRunSqlFile($pdo, $dbDir . '/fix_products_code_barcode.sql');
echo "\n";

migrateStepStandalonePerf($pdo);
migrateStepOpsSchema($pdo, $root);
migrateStepPayrollSchema($pdo);
migrateStepProductTax($pdo, $root);

echo "[9/9] Combos de venta — tablas\n";
migrateRunSqlFile($pdo, $dbDir . '/fix_product_combos_schema.sql');
if (is_file($root . '/vendor/autoload.php')) {
    try {
        (new \SoftNova\Services\ProductComboService($pdo))->ensureSchema();
        echo "  OK ProductComboService::ensureSchema()\n";
    } catch (Throwable $e) {
        echo "  SKIP ProductComboService: " . $e->getMessage() . "\n";
    }
}
echo "\n";

echo "=== Migración completada ===\n";
echo "Siguiente paso recomendado: exportar backup de la BD (php database/backup.php).\n";
