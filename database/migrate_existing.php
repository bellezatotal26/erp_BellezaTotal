<?php
/**
 * Actualiza esquemas de tenants existentes.
 * Incluye tablas/columnas legacy y el módulo contable nativo.
 *
 * Uso (CLI): php database/migrate_existing.php
 */

define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('CORE_PATH', ROOT_PATH . '/core');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('STORAGE_PATH', ROOT_PATH . '/storage');
require_once ROOT_PATH . '/vendor/autoload.php';
require_once ROOT_PATH . '/core/helpers.php';

$masterDb = \SoftNova\Core\Database::getInstance();
$tenants = $masterDb->query(
    "SELECT database_name FROM tenants WHERE status != 'cancelled'"
)->fetchAll();

echo '=== Actualizando ' . count($tenants) . " tenant(s) ===\n\n";

$tenantDb = \SoftNova\Core\TenantDatabase::getInstance();

foreach ($tenants as $tenant) {
    $dbName = $tenant['database_name'];
    echo "Tenant: {$dbName}\n";

    try {
        $pdo = $tenantDb->getTenantConnection($dbName, '', '');

        try {
            $pdo->exec('ALTER TABLE cash_movements ADD COLUMN user_id INT UNSIGNED AFTER reference_id');
            echo "  ✓ user_id agregado a cash_movements\n";
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Duplicate column')) {
                echo "  - user_id ya existe en cash_movements\n";
            } else {
                echo '  ⚠ cash_movements: ' . $e->getMessage() . "\n";
            }
        }

        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS sale_payments (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    sale_id INT UNSIGNED NOT NULL,
                    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    payment_method ENUM('cash','card','transfer','other') DEFAULT 'cash',
                    notes VARCHAR(255),
                    user_id INT UNSIGNED,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_sale (sale_id),
                    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            echo "  ✓ sale_payments lista\n";
        } catch (\Exception $e) {
            echo '  ⚠ sale_payments: ' . $e->getMessage() . "\n";
        }

        try {
            $pdo->exec('ALTER TABLE sale_items ADD COLUMN unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unit_price');
            echo "  ✓ unit_cost agregado a sale_items\n";
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Duplicate column')) {
                echo "  - unit_cost ya existe en sale_items\n";
            } else {
                echo '  ⚠ sale_items: ' . $e->getMessage() . "\n";
            }
        }

        try {
            $accounting = new \SoftNova\Services\AccountingService($pdo);
            $count = count($accounting->accounts());
            echo "  ✓ contabilidad lista ({$count} cuentas)\n";
        } catch (\Exception $e) {
            echo '  ⚠ contabilidad: ' . $e->getMessage() . "\n";
        }

        try {
            $integrations = new \SoftNova\Services\Integrations\IntegrationSettingsService($pdo);
            $integrations->ensureSchema();
            echo "  ✓ integration_settings lista\n";
        } catch (\Exception $e) {
            echo '  ⚠ integraciones: ' . $e->getMessage() . "\n";
        }

        try {
            $ai = new \SoftNova\Services\AiService($pdo);
            $ai->ensureHistorySchema();
            echo "  ✓ historial IA listo\n";
        } catch (\Exception $e) {
            echo '  ⚠ historial IA: ' . $e->getMessage() . "\n";
        }

        try {
            \SoftNova\Services\TenantOpsSchema::ensure($pdo);
            echo "  ✓ esquema compras/trazabilidad/canales listo\n";
        } catch (\Exception $e) {
            echo '  ⚠ ops schema: ' . $e->getMessage() . "\n";
        }

        try {
            $mgr = new \SoftNova\Services\Integrations\IntegrationManager($pdo);
            $mgr->ensureSaleExternalColumns();
            echo "  ✓ columnas FE en sales listas\n";
        } catch (\Exception $e) {
            echo '  ⚠ columnas FE: ' . $e->getMessage() . "\n";
        }

        echo "\n";
    } catch (\Exception $e) {
        echo '  ❌ Error conectando: ' . $e->getMessage() . "\n\n";
    }
}

// Añadir módulos compras + nómina a planes Pro/Enterprise
try {
    $plans = $masterDb->query("SELECT id, name, modules FROM subscription_plans")->fetchAll();
    foreach ($plans as $plan) {
        $mods = json_decode((string)$plan['modules'], true) ?: [];
        if (!is_array($mods)) {
            continue;
        }
        $name = strtolower((string)($plan['name'] ?? ''));
        $byTier = str_contains($name, 'pro') || str_contains($name, 'enterprise')
            || str_contains($name, 'empresarial') || str_contains($name, 'premium');
        $byModules = in_array('inventario', $mods, true) && in_array('proveedores', $mods, true);
        if (!$byTier && !$byModules) {
            continue;
        }
        $changed = false;
        foreach (['compras', 'nomina'] as $mod) {
            if (!in_array($mod, $mods, true)) {
                $mods[] = $mod;
                $changed = true;
                echo "✓ Plan #{$plan['id']} ({$plan['name']}): módulo {$mod} agregado\n";
            }
        }
        if ($changed) {
            $masterDb->query(
                "UPDATE subscription_plans SET modules = ? WHERE id = ?",
                [json_encode(array_values($mods), JSON_UNESCAPED_UNICODE), $plan['id']]
            );
        }
    }
} catch (\Throwable $e) {
    echo '⚠ planes: ' . $e->getMessage() . "\n";
}

// Sembrar cuentas financieras críticas (530505 / CxP) en tenants ya migrados
try {
    foreach ($tenants as $tenant) {
        $pdo = $tenantDb->getTenantConnection($tenant['database_name'], '', '');
        $acc = new \SoftNova\Services\AccountingService($pdo);
        $health = $acc->auditCriticalAccounts();
        if (!empty($health['fixed'])) {
            echo "✓ {$tenant['database_name']}: cuentas críticas verificadas/corregidas\n";
        }
    }
} catch (\Throwable $e) {
    echo '⚠ auditoría cuentas: ' . $e->getMessage() . "\n";
}

echo "=== Migración completada ===\n";
