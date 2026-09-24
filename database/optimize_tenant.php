<?php
/**
 * Mantenimiento de BD tenant: esquema, índices, estadísticas y jobs.
 *
 * Uso (desde la raíz del proyecto, por SSH o cron):
 *   php database/optimize_tenant.php
 *   php database/optimize_tenant.php --schema          Solo migraciones / columnas faltantes
 *   php database/optimize_tenant.php --indexes           Solo índices de rendimiento
 *   php database/optimize_tenant.php --analyze           ANALYZE TABLE (recomendado semanal)
 *   php database/optimize_tenant.php --optimize          OPTIMIZE TABLE (más lento; mensual)
 *   php database/optimize_tenant.php --jobs --jobs-limit=15
 *   php database/optimize_tenant.php --purge-failed=30   Borra jobs failed > N días
 *   php database/optimize_tenant.php --all-tenants     SaaS: todos los tenants activos
 *
 * Por defecto (sin flags): --schema + --indexes + --analyze + --jobs (limit 5).
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', $root);
    define('APP_PATH', $root . '/app');
    define('CORE_PATH', $root . '/core');
    define('CONFIG_PATH', $root . '/config');
    define('PUBLIC_PATH', $root . '/public');
    define('STORAGE_PATH', $root . '/storage');
}

require_once ROOT_PATH . '/core/helpers.php';

// -------------------------------------------------------------------------
// Bootstrap .env
// -------------------------------------------------------------------------
$envFile = $root . '/.env';
if (is_file($envFile)) {
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

// -------------------------------------------------------------------------
// CLI
// -------------------------------------------------------------------------
$opts = getopt('', [
    'schema',
    'indexes',
    'analyze',
    'optimize',
    'jobs',
    'jobs-limit:',
    'purge-failed:',
    'tenant:',
    'all-tenants',
    'repair-caja',
    'help',
]);

if (isset($opts['help'])) {
    echo <<<HELP
Seri ERP — optimize_tenant.php

  php database/optimize_tenant.php                    schema + índices + analyze + jobs
  php database/optimize_tenant.php --schema           columnas/tablas faltantes
  php database/optimize_tenant.php --indexes          índices de rendimiento
  php database/optimize_tenant.php --analyze          ANALYZE TABLE (semanal)
  php database/optimize_tenant.php --optimize           OPTIMIZE TABLE (mensual, más lento)
  php database/optimize_tenant.php --jobs --jobs-limit=15
  php database/optimize_tenant.php --purge-failed=30
  php database/optimize_tenant.php --repair-caja       revierte egresos admin erróneos en caja abierta
  php database/optimize_tenant.php --all-tenants      modo SaaS
  php database/optimize_tenant.php --tenant=nombre_bd

HELP;
    exit(0);
}

$explicitFlags = isset($opts['schema']) || isset($opts['indexes']) || isset($opts['analyze'])
    || isset($opts['optimize']) || isset($opts['jobs']) || isset($opts['repair-caja']);

$doRepairCaja = isset($opts['repair-caja']);

$doSchema = isset($opts['schema']) || !$explicitFlags;
$doIndexes = isset($opts['indexes']) || !$explicitFlags;
$doAnalyze = isset($opts['analyze']) || (!$explicitFlags && !isset($opts['optimize']));
$doOptimize = isset($opts['optimize']);
$doJobs = isset($opts['jobs']) || !$explicitFlags;
$jobsLimit = max(1, min(50, (int)($opts['jobs-limit'] ?? 5)));
$purgeFailedDays = isset($opts['purge-failed']) ? max(1, (int)$opts['purge-failed']) : 0;
$allTenants = isset($opts['all-tenants']);
$tenantFilter = trim((string)($opts['tenant'] ?? ''));

// -------------------------------------------------------------------------
// Conexiones
// -------------------------------------------------------------------------
function dbConfigFromEnv(): array
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_DATABASE') ?: getenv('DB_NAME') ?: '';
    $user = getenv('DB_USERNAME') ?: getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASSWORD') ?: getenv('DB_PASS') ?: '';

    if ($name === '') {
        $cfg = require ROOT_PATH . '/config/database.php';
        $def = $cfg['default'] ?? $cfg['database']['default'] ?? $cfg;
        $host = $def['host'] ?? $host;
        $port = (string)($def['port'] ?? $port);
        $name = $def['database'] ?? $name;
        $user = $def['username'] ?? $user;
        $pass = $def['password'] ?? $pass;
    }

    return compact('host', 'port', 'name', 'user', 'pass');
}

function connectPdo(array $cfg): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $cfg['host'],
        $cfg['port'],
        $cfg['name']
    );
    return new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function resolveTenantTargets(bool $allTenants, string $tenantFilter): array
{
    $standalone = function_exists('SoftNova\Core\is_standalone') && \SoftNova\Core\is_standalone();

    if ($standalone && !$allTenants) {
        $cfg = dbConfigFromEnv();
        if ($cfg['name'] === '') {
            throw new RuntimeException('No se encontró nombre de BD en .env ni config/database.php');
        }
        if ($tenantFilter !== '' && $tenantFilter !== $cfg['name']) {
            throw new RuntimeException("Filtro --tenant={$tenantFilter} no coincide con BD standalone {$cfg['name']}");
        }
        return [['name' => $cfg['name'], 'pdo' => connectPdo($cfg)]];
    }

    $masterCfg = dbConfigFromEnv();
    $masterName = getenv('DB_MASTER_DATABASE') ?: getenv('DB_MASTER_NAME') ?: '';
    if ($masterName === '') {
        // SaaS: misma conexión apunta al master
        $masterPdo = connectPdo($masterCfg);
    } else {
        $masterPdo = connectPdo(array_merge($masterCfg, ['name' => $masterName]));
    }

    $sql = "SELECT database_name FROM tenants WHERE status != 'cancelled'";
    $params = [];
    if ($tenantFilter !== '') {
        $sql .= ' AND database_name = ?';
        $params[] = $tenantFilter;
    }
    $sql .= ' ORDER BY database_name';

    $rows = $masterPdo->prepare($sql);
    $rows->execute($params);
    $tenants = $rows->fetchAll();

    if ($tenants === []) {
        if (!$allTenants && $tenantFilter === '') {
            $cfg = dbConfigFromEnv();
            if ($cfg['name'] !== '') {
                return [['name' => $cfg['name'], 'pdo' => connectPdo($cfg)]];
            }
        }
        throw new RuntimeException('No hay tenants activos para procesar.');
    }

    $tenantDb = \SoftNova\Core\TenantDatabase::getInstance();
    $out = [];
    foreach ($tenants as $t) {
        $dbName = (string)$t['database_name'];
        try {
            $out[] = [
                'name' => $dbName,
                'pdo' => $tenantDb->getTenantConnection($dbName, '', ''),
            ];
        } catch (Throwable $e) {
            echo "SKIP tenant {$dbName}: {$e->getMessage()}\n";
        }
    }
    return $out;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
    );
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
         LIMIT 1"
    );
    $stmt->execute([$table, $index]);
    return (bool)$stmt->fetchColumn();
}

function ensureIndex(PDO $pdo, string $table, string $index, string $columns): void
{
    if (!tableExists($pdo, $table)) {
        echo "  - tabla {$table} no existe (skip índice {$index})\n";
        return;
    }
    if (indexExists($pdo, $table, $index)) {
        echo "  · índice {$table}.{$index} OK\n";
        return;
    }
    try {
        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$columns})");
        echo "  ✓ índice creado {$table}.{$index}\n";
    } catch (Throwable $e) {
        echo "  ⚠ {$table}.{$index}: {$e->getMessage()}\n";
    }
}

function runSchemaEnsures(PDO $pdo): void
{
    echo "  → Esquema PHP (columnas / tablas)\n";

    $steps = [
        'cash_movements.user_id' => static function (PDO $db): void {
            try {
                $db->exec('ALTER TABLE cash_movements ADD COLUMN user_id INT UNSIGNED NULL AFTER reference_id');
                echo "    ✓ cash_movements.user_id\n";
            } catch (Throwable $e) {
                if (!str_contains($e->getMessage(), 'Duplicate column')) {
                    echo '    ⚠ cash_movements.user_id: ' . $e->getMessage() . "\n";
                }
            }
        },
        'sale_items.unit_cost' => static function (PDO $db): void {
            try {
                $db->exec('ALTER TABLE sale_items ADD COLUMN unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unit_price');
                echo "    ✓ sale_items.unit_cost\n";
            } catch (Throwable $e) {
                if (!str_contains($e->getMessage(), 'Duplicate column')) {
                    echo '    ⚠ sale_items.unit_cost: ' . $e->getMessage() . "\n";
                }
            }
        },
        'sale_payments' => static function (PDO $db): void {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS sale_payments (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    sale_id INT UNSIGNED NOT NULL,
                    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    payment_method VARCHAR(50) DEFAULT 'cash',
                    notes VARCHAR(255),
                    user_id INT UNSIGNED NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_sale (sale_id),
                    CONSTRAINT sale_payments_ibfk_1 FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            echo "    ✓ sale_payments\n";
        },
        'AccountingService' => static function (PDO $db): void {
            new \SoftNova\Services\AccountingService($db);
            echo "    ✓ contabilidad\n";
        },
        'SalesDocumentService' => static function (PDO $db): void {
            new \SoftNova\Services\SalesDocumentService($db);
            echo "    ✓ sales (payment_terms, delivery_fee, …)\n";
        },
        'SupplyChainSchema' => static function (PDO $db): void {
            \SoftNova\Services\SupplyChainSchema::ensure($db);
            echo "    ✓ caja/compras/lotes\n";
        },
        'TenantOpsSchema' => static function (PDO $db): void {
            \SoftNova\Services\TenantOpsSchema::ensure($db);
            echo "    ✓ ops/canales/empleados\n";
        },
        'ExpenseCategoryService' => static function (PDO $db): void {
            new \SoftNova\Services\ExpenseCategoryService($db);
            echo "    ✓ gastos/categorías\n";
        },
        'repairAdminCash' => static function (PDO $db): void {
            $result = (new \SoftNova\Services\CashService($db))->repairAdministrativeExpenseMovements();
            if (($result['reversed'] ?? 0) > 0) {
                echo '    ✓ egresos admin revertidos en caja: ' . (int)$result['reversed']
                    . ' ($' . number_format((float)($result['amount'] ?? 0), 0, ',', '.') . ")\n";
            }
        },
        'CommissionService' => static function (PDO $db): void {
            new \SoftNova\Services\CommissionService($db);
            echo "    ✓ comisiones\n";
        },
        'JobQueue' => static function (PDO $db): void {
            new \SoftNova\Services\JobQueue($db);
            echo "    ✓ jobs\n";
        },
        'IntegrationSettings' => static function (PDO $db): void {
            (new \SoftNova\Services\Integrations\IntegrationSettingsService($db))->ensureSchema();
            echo "    ✓ integraciones\n";
        },
        'IntegrationManager FE' => static function (PDO $db): void {
            (new \SoftNova\Services\Integrations\IntegrationManager($db))->ensureSaleExternalColumns();
            echo "    ✓ columnas FE en sales\n";
        },
        'CustomOptionService' => static function (PDO $db): void {
            new \SoftNova\Services\CustomOptionService($db);
            echo "    ✓ opciones personalizadas\n";
        },
        'StockService' => static function (PDO $db): void {
            new \SoftNova\Services\StockService($db);
            echo "    ✓ stock/trazabilidad\n";
        },
        'auditCriticalAccounts' => static function (PDO $db): void {
            $health = (new \SoftNova\Services\AccountingService($db))->auditCriticalAccounts();
            if (!empty($health['fixed'])) {
                echo '    ✓ cuentas críticas corregidas: ' . count($health['fixed']) . "\n";
            } else {
                echo "    ✓ cuentas críticas OK\n";
            }
        },
    ];

    foreach ($steps as $label => $fn) {
        try {
            $fn($pdo);
        } catch (Throwable $e) {
            echo "    ⚠ {$label}: {$e->getMessage()}\n";
        }
    }

    // Datos legacy
    try {
        $pdo->exec("UPDATE sales SET payment_terms = 'cash' WHERE payment_terms IS NULL OR payment_terms = ''");
    } catch (Throwable $e) {
        // columna puede no existir aún
    }
}

function runPerformanceIndexes(PDO $pdo): void
{
    echo "  → Índices de rendimiento\n";
    $indexes = [
        ['sales', 'idx_sale_date', '`sale_date`'],
        ['products', 'idx_products_code', '`code`'],
        ['products', 'idx_products_name', '`name`(80)'],
        ['products', 'idx_products_barcode', '`barcode`'],
        ['products', 'idx_products_category', '`category_id`'],
        ['products', 'idx_products_status_type', '`status`, `product_type`'],
        ['sale_payments', 'idx_sp_sale', '`sale_id`'],
        ['sale_payments', 'idx_sp_method', '`payment_method`'],
        ['sale_items', 'idx_si_product', '`product_id`'],
        ['sale_items', 'idx_si_sale', '`sale_id`'],
        ['stock_movements', 'idx_sm_product_date', '`product_id`, `created_at`'],
        ['stock_movements', 'idx_sm_ref', '`reference_type`, `reference_id`'],
        ['cash_movements', 'idx_cm_session', '`cash_session_id`'],
        ['cash_movements', 'idx_cm_ref', '`reference_type`, `reference_id`'],
        ['cash_sessions', 'idx_cs_status', '`status`, `opening_date`'],
        ['customers', 'idx_customers_doc', '`document_number`'],
        ['accounting_entries', 'idx_ae_source', '`source_type`, `source_id`'],
        ['accounting_lines', 'idx_al_entry', '`entry_id`'],
        ['jobs', 'idx_jobs_status_avail', '`status`, `available_at`'],
    ];

    foreach ($indexes as [$table, $index, $cols]) {
        ensureIndex($pdo, $table, $index, $cols);
    }

    // Índice único offline UUID (SalesDocumentService lo crea; reforzar)
    if (tableExists($pdo, 'sales') && !indexExists($pdo, 'sales', 'uq_sales_client_uuid')) {
        try {
            $pdo->exec('CREATE UNIQUE INDEX uq_sales_client_uuid ON sales (client_sale_uuid)');
            echo "  ✓ índice uq_sales_client_uuid\n";
        } catch (Throwable $e) {
            if (!str_contains($e->getMessage(), 'Duplicate')) {
                echo '  ⚠ uq_sales_client_uuid: ' . $e->getMessage() . "\n";
            }
        }
    }
}

/** @return list<string> */
function maintenanceTables(PDO $pdo): array
{
    $wanted = [
        'products', 'categories', 'sales', 'sale_items', 'sale_payments',
        'stock_movements', 'customers', 'suppliers', 'cash_sessions', 'cash_movements',
        'expenses', 'purchase_orders', 'purchase_order_items',
        'accounting_entries', 'accounting_lines', 'accounting_accounts',
        'jobs', 'tenant_notifications', 'audit_logs',
    ];
    return array_values(array_filter($wanted, static fn (string $t) => tableExists($pdo, $t)));
}

function runAnalyze(PDO $pdo): void
{
    echo "  → ANALYZE TABLE (actualiza estadísticas del optimizador)\n";
    foreach (maintenanceTables($pdo) as $table) {
        try {
            $pdo->exec("ANALYZE TABLE `{$table}`");
            echo "    ✓ {$table}\n";
        } catch (Throwable $e) {
            echo "    ⚠ {$table}: {$e->getMessage()}\n";
        }
    }
}

function runOptimize(PDO $pdo): void
{
    echo "  → OPTIMIZE TABLE (puede tardar; bloquea escrituras brevemente)\n";
    foreach (maintenanceTables($pdo) as $table) {
        try {
            $pdo->exec("OPTIMIZE TABLE `{$table}`");
            echo "    ✓ {$table}\n";
        } catch (Throwable $e) {
            echo "    ⚠ {$table}: {$e->getMessage()}\n";
        }
    }
}

function runJobs(PDO $pdo, int $limit): void
{
    echo "  → Procesar jobs pendientes (limit {$limit})\n";
    $queue = new \SoftNova\Services\JobQueue($pdo);
    $pending = $queue->pendingCount();
    echo "    pendientes antes: {$pending}\n";
    $result = $queue->process($limit);
    echo "    procesados: {$result['processed']}, fallidos: {$result['failed']}, pendientes: "
        . $queue->pendingCount() . "\n";
}

function purgeFailedJobs(PDO $pdo, int $days): void
{
    if (!tableExists($pdo, 'jobs')) {
        return;
    }
    echo "  → Purgar jobs failed > {$days} días\n";
    $stmt = $pdo->prepare(
        "DELETE FROM jobs WHERE status = 'failed' AND finished_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
    );
    $stmt->execute([$days]);
    echo '    eliminados: ' . $stmt->rowCount() . "\n";
}

function printTableStats(PDO $pdo): void
{
    echo "  → Resumen tablas principales\n";
    $tables = maintenanceTables($pdo);
    if ($tables === []) {
        return;
    }
    $in = implode(',', array_map(static fn ($t) => $pdo->quote($t), $tables));
    $rows = $pdo->query(
        "SELECT TABLE_NAME, TABLE_ROWS, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS mb
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$in})
         ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC"
    )->fetchAll();

    foreach ($rows as $r) {
        printf(
            "    %-28s %10s filas  %8s MB\n",
            $r['TABLE_NAME'],
            number_format((int)$r['TABLE_ROWS']),
            $r['mb']
        );
    }
}

// -------------------------------------------------------------------------
// Main
// -------------------------------------------------------------------------
echo '=== Seri ERP — optimize_tenant.php ===' . PHP_EOL;
echo date('Y-m-d H:i:s') . PHP_EOL;
echo 'Modo: '
    . ($doSchema ? 'schema ' : '')
    . ($doIndexes ? 'indexes ' : '')
    . ($doAnalyze ? 'analyze ' : '')
    . ($doOptimize ? 'optimize ' : '')
    . ($doJobs ? 'jobs ' : '')
    . ($doRepairCaja ? 'repair-caja ' : '')
    . ($purgeFailedDays > 0 ? "purge-failed={$purgeFailedDays} " : '')
    . PHP_EOL . PHP_EOL;

try {
    $targets = resolveTenantTargets($allTenants, $tenantFilter);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

foreach ($targets as $target) {
    $dbName = $target['name'];
    /** @var PDO $pdo */
    $pdo = $target['pdo'];
    echo "━━━ Tenant: {$dbName} ━━━\n";

    if ($doSchema) {
        runSchemaEnsures($pdo);
    }
    if ($doIndexes) {
        runPerformanceIndexes($pdo);
    }
    if ($doOptimize) {
        runOptimize($pdo);
    } elseif ($doAnalyze) {
        runAnalyze($pdo);
    }
    if ($purgeFailedDays > 0) {
        purgeFailedJobs($pdo, $purgeFailedDays);
    }
    if ($doJobs) {
        runJobs($pdo, $jobsLimit);
    }
    if ($doRepairCaja) {
        echo "  → Reparar egresos admin en caja\n";
        try {
            new \SoftNova\Services\ExpenseCategoryService($pdo);
            $result = (new \SoftNova\Services\CashService($pdo))->repairAdministrativeExpenseMovements();
            if (($result['reversed'] ?? 0) > 0) {
                echo '    ✓ revertidos: ' . (int)$result['reversed']
                    . ' · $' . number_format((float)($result['amount'] ?? 0), 0, ',', '.') . "\n";
            } else {
                echo "    (sin egresos admin que revertir — ¿caja abierta?)\n";
            }
        } catch (Throwable $e) {
            echo '    ⚠ repair-caja: ' . $e->getMessage() . "\n";
        }
    }
    printTableStats($pdo);
    echo "\n";
}

echo "=== Completado ===\n";
