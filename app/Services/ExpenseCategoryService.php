<?php

namespace SoftNova\Services;

/**
 * Catálogo de categorías de gasto: operativos (caja) y contables (sub-grupos) con cuenta PUC.
 */
class ExpenseCategoryService
{
    /** Grupos visibles en UI (kind en expense_categories). */
    public const KINDS = ['operational', 'administrative', 'financial', 'management'];

    /** Sub-grupos contables integrados (no descuentan caja). */
    public const ACCOUNTING_KINDS = ['administrative', 'financial', 'management'];

    /** Prefijo kind para sub-grupos contables creados por el usuario (sg_codigo). */
    public const SUBGROUP_KIND_PREFIX = 'sg_';

    /** Códigos del catálogo financiero (comisiones, GMF, etc.). */
    public const CORE_FINANCIAL_CODES = [
        'bank_fees', 'dataphone_fees', 'sales_commissions', 'gmf_4x1000', 'other_financial',
    ];

    /** Códigos de pérdidas / gestión operativa contable. */
    public const CORE_MANAGEMENT_CODES = [
        'inv_warranty', 'inv_broken', 'inv_return', 'inv_expiry',
    ];

    /** Placeholders de UI — no listar como categoría seleccionable. */
    public const PLACEHOLDER_CODES = [
        'other_operational', 'other_administrative', 'other_financial', 'other_management',
    ];

    /** Gasto generado por el módulo Nómina (costo total de la liquidación). */
    public const PAYROLL_CODE = 'payroll';

    /** Categorías de sistema: las crea otro módulo; no se eligen ni editan desde Gastos. */
    public const SYSTEM_CODES = [self::PAYROLL_CODE];

    /** Códigos del catálogo base operativo (gastos generales de caja). */
    public const CORE_OPERATIONAL_CODES = [
        'papeleria', 'alimentos', 'transport', 'maintenance',
    ];

    /** Códigos legados tratados como administrativos si kind no está definido. */
    public const ADMINISTRATIVE_CODES = [
        'rent', 'chamber_commerce', 'copyright', 'iva', 'ica', 'withholding_renta',
        'licenses_permits', 'notary_fees', 'legal_accounting', 'other_administrative',
    ];

    /** Clave en settings: migración one-shot a dos grupos (no re-ejecutar en cada request). */
    private const MIGRATION_TWO_GROUPS = 'expense_categories_two_groups_v1';
    private const MIGRATION_ACCOUNTING_SUBGROUPS = 'expense_categories_accounting_subgroups_v1';

    public function __construct(private \PDO $db)
    {
        $this->ensureSchema();
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS expense_categories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(40) NOT NULL UNIQUE,
                name VARCHAR(120) NOT NULL,
                kind ENUM('financial','operational','administrative','management') NOT NULL DEFAULT 'operational',
                account_code VARCHAR(20) NOT NULL DEFAULT '510505',
                sort_order INT NOT NULL DEFAULT 0,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ec_kind (kind),
                INDEX idx_ec_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        try {
            $this->db->exec(
                "ALTER TABLE expense_categories
                 MODIFY COLUMN kind ENUM('financial','operational','administrative','management') NOT NULL DEFAULT 'operational'"
            );
        } catch (\Throwable $e) {
            // ya ampliado o sin privilegios
        }

        // Ampliar expenses para categoría tipada + comprobante foto/PDF
        $this->ensureExpenseColumns();
        $this->seed();
        $this->migrateToTwoGroups();
        $this->migrateAccountingSubgroups();
        $this->ensureSubgroupSchema();
        $this->widenKindColumn();
        $done = true;
    }

    private function widenKindColumn(): void
    {
        try {
            $this->db->exec(
                "ALTER TABLE expense_categories
                 MODIFY COLUMN kind VARCHAR(50) NOT NULL DEFAULT 'operational'"
            );
        } catch (\Throwable $e) {
            // sin privilegios o ya ampliado
        }
    }

    private function ensureSubgroupSchema(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS expense_subgroups (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(40) NOT NULL UNIQUE,
                name VARCHAR(120) NOT NULL,
                account_code VARCHAR(20) NOT NULL DEFAULT '519595',
                sort_order INT NOT NULL DEFAULT 500,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_es_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * Restaura sub-grupos contables: financieros, administrativos y gestión (no descuentan caja).
     */
    private function migrateAccountingSubgroups(): void
    {
        if ($this->migrationFlagIsSet(self::MIGRATION_ACCOUNTING_SUBGROUPS)) {
            return;
        }
        try {
            $fin = self::CORE_FINANCIAL_CODES;
            $finPh = implode(',', array_fill(0, count($fin), '?'));
            $this->query(
                "UPDATE expense_categories SET kind = 'financial' WHERE code IN ($finPh)",
                $fin
            );
            $mgmt = self::CORE_MANAGEMENT_CODES;
            $mgmtPh = implode(',', array_fill(0, count($mgmt), '?'));
            $this->query(
                "UPDATE expense_categories SET kind = 'management' WHERE code IN ($mgmtPh)",
                $mgmt
            );
            $this->markMigrationDone(self::MIGRATION_ACCOUNTING_SUBGROUPS);
        } catch (\Throwable $e) {
            error_log('ExpenseCategoryService::migrateAccountingSubgroups: ' . $e->getMessage());
        }
    }

    /**
     * Normaliza tenants antiguos: financieros → administrativos; operativos fuera del núcleo → administrativos.
     */
    private function migrateToTwoGroups(): void
    {
        if ($this->migrationFlagIsSet(self::MIGRATION_TWO_GROUPS)) {
            return;
        }

        try {
            $this->query("UPDATE expense_categories SET kind = 'administrative' WHERE kind = 'financial'");
        } catch (\Throwable $e) {
            // silencioso
        }

        $core = self::CORE_OPERATIONAL_CODES;
        $placeholders = implode(',', array_fill(0, count($core), '?'));
        try {
            $this->query(
                "UPDATE expense_categories
                 SET kind = 'administrative'
                 WHERE kind = 'operational'
                   AND code NOT IN ($placeholders)",
                $core
            );
        } catch (\Throwable $e) {
            // silencioso
        }

        try {
            $this->query(
                "UPDATE expense_categories SET status = 'inactive'
                 WHERE code IN ('other_operational','other_administrative')"
            );
        } catch (\Throwable $e) {
            // silencioso
        }

        $admCodes = self::ADMINISTRATIVE_CODES;
        $admPh = implode(',', array_fill(0, count($admCodes), '?'));
        try {
            $this->query(
                "UPDATE expense_categories
                 SET kind = 'administrative'
                 WHERE code IN ($admPh) AND kind <> 'administrative'",
                $admCodes
            );
        } catch (\Throwable $e) {
            // silencioso
        }

        $renames = [
            'general' => ['name' => 'Gastos generales (histórico)', 'kind' => 'administrative'],
            'fuel' => ['name' => 'Combustible / gasolina', 'kind' => 'administrative'],
            'utilities' => ['name' => 'Servicios públicos', 'kind' => 'administrative'],
            'advertising' => ['name' => 'Publicidad', 'kind' => 'administrative'],
        ];
        foreach ($renames as $code => $meta) {
            try {
                $this->query(
                    "UPDATE expense_categories SET name = ?, kind = ? WHERE code = ?",
                    [$meta['name'], $meta['kind'], $code]
                );
            } catch (\Throwable $e) {
                // silencioso
            }
        }

        $this->markMigrationDone(self::MIGRATION_TWO_GROUPS);
    }

    private function migrationFlagIsSet(string $key): bool
    {
        try {
            $val = $this->query(
                "SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1",
                [$key]
            )->fetchColumn();
            return (string)$val === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function markMigrationDone(string $key): void
    {
        try {
            $this->query(
                "INSERT INTO settings (setting_key, setting_value) VALUES (?, '1')
                 ON DUPLICATE KEY UPDATE setting_value = '1'",
                [$key]
            );
        } catch (\Throwable $e) {
            // silencioso
        }
    }

    private function ensureExpenseColumns(): void
    {
        $cols = [];
        try {
            $rows = $this->db->query('SHOW COLUMNS FROM expenses')->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $cols[strtolower((string)$row['Field'])] = $row;
            }
        } catch (\Throwable $e) {
            return;
        }

        $alters = [];
        if (!isset($cols['category_id'])) {
            $alters[] = 'ADD COLUMN category_id INT UNSIGNED NULL AFTER category';
        }
        if (!isset($cols['receipt_path'])) {
            $alters[] = 'ADD COLUMN receipt_path VARCHAR(255) NULL AFTER receipt_number';
        }
        if (!isset($cols['receipt_original_name'])) {
            $alters[] = 'ADD COLUMN receipt_original_name VARCHAR(255) NULL AFTER receipt_path';
        }
        if (!isset($cols['receipt_mime'])) {
            $alters[] = 'ADD COLUMN receipt_mime VARCHAR(100) NULL AFTER receipt_original_name';
        }
        // Soft-delete: anular sin borrar (trazabilidad contable / comprobantes).
        if (!isset($cols['status'])) {
            $alters[] = "ADD COLUMN status ENUM('active','cancelled') NOT NULL DEFAULT 'active' AFTER notes";
        }
        if (!isset($cols['cancelled_at'])) {
            $alters[] = 'ADD COLUMN cancelled_at DATETIME NULL AFTER status';
        }

        // payment_method: pasar a VARCHAR para admitir datáfono / link de pago
        if (isset($cols['payment_method'])) {
            $type = strtolower((string)($cols['payment_method']['Type'] ?? ''));
            if (str_starts_with($type, 'enum(')) {
                $alters[] = "MODIFY COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'cash'";
            }
        }

        if ($alters) {
            try {
                $this->db->exec('ALTER TABLE expenses ' . implode(', ', $alters));
            } catch (\Throwable $e) {
                // Hosting sin ALTER o columna ya existe.
            }
        }

        try {
            $this->db->exec('CREATE INDEX idx_expenses_status ON expenses (status)');
        } catch (\Throwable $e) {
            // índice ya existe
        }
    }

    private function seed(): void
    {
        $items = [
            // Gastos operativos (descuentan caja)
            ['papeleria', 'Papelería', 'operational', '510505', 10],
            ['alimentos', 'Alimentos', 'operational', '510505', 20],
            ['transport', 'Transporte', 'operational', '515505', 30],
            ['maintenance', 'Mantenimiento', 'operational', '514505', 40],
            // Comisiones / GMF (contables — financieros)
            ['bank_fees', 'Comisiones bancarias', 'financial', '530505', 110],
            ['dataphone_fees', 'Comisiones datáfono', 'financial', '530510', 120],
            ['sales_commissions', 'Comisiones de ventas', 'financial', '530515', 130],
            ['gmf_4x1000', 'Gravamen 4x1000', 'financial', '530525', 140],
            ['other_financial', 'Otros gastos financieros', 'financial', '539595', 150],
            // Gastos contables — administrativos
            ['utilities', 'Servicios públicos', 'administrative', '513505', 160],
            ['fuel', 'Combustible / gasolina', 'administrative', '515010', 170],
            ['advertising', 'Publicidad', 'administrative', '516005', 180],
            // Gastos contables — operacionales o de gestión
            ['inv_warranty', 'Pérdida por garantía', 'management', '519595', 185],
            ['inv_broken', 'Producto roto / merma', 'management', '519595', 186],
            ['inv_return', 'Pérdida por devolución', 'management', '519595', 187],
            ['inv_expiry', 'Pérdida por vencimiento', 'management', '519595', 188],
            ['rent', 'Arrendamientos', 'administrative', '512010', 190],
            ['chamber_commerce', 'Cámara de comercio', 'administrative', '519595', 200],
            ['copyright', 'Derechos de autor', 'administrative', '519595', 210],
            ['iva', 'IVA', 'administrative', '511570', 220],
            ['ica', 'ICA', 'administrative', '511570', 230],
            ['withholding_renta', 'Retención en la fuente', 'administrative', '135515', 240],
            ['licenses_permits', 'Licencias y permisos', 'administrative', '519595', 250],
            ['notary_fees', 'Gastos notariales', 'administrative', '519595', 260],
            ['legal_accounting', 'Asesoría legal / contable', 'administrative', '513095', 270],
        ];

        $stmt = $this->db->prepare(
            "INSERT INTO expense_categories (code, name, kind, account_code, sort_order)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                kind = VALUES(kind),
                account_code = VALUES(account_code),
                sort_order = VALUES(sort_order)"
        );
        foreach ($items as $item) {
            try {
                $stmt->execute($item);
            } catch (\Throwable $e) {
                if (in_array($item[2] ?? '', self::ACCOUNTING_KINDS, true)) {
                    try {
                        $fallback = $item;
                        $fallback[2] = 'operational';
                        $stmt->execute($fallback);
                    } catch (\Throwable $e2) {
                        // ignore
                    }
                }
            }
        }

        // IVA como gasto no debe apuntar a pasivo 240801 (IVA generado).
        try {
            $this->query(
                "UPDATE expense_categories
                 SET account_code = '511570'
                 WHERE code = 'iva' AND account_code = '240801'"
            );
        } catch (\Throwable $e) {
            // silencioso
        }
    }

    /**
     * ¿Este gasto descuenta el cajón físico de caja/POS?
     * Los contables nunca (aunque el medio de pago diga «efectivo»).
     */
    public function affectsCashDrawer(?array $category): bool
    {
        if (!$category) {
            return true;
        }
        return !$this->isAccounting($category);
    }

    /** Gasto contable (administrativo, financiero o de gestión) — no descuenta caja. */
    public function isAccounting(?array $category): bool
    {
        if (!$category) {
            return false;
        }
        $kind = (string)($category['kind'] ?? '');
        if (self::isAccountingKind($kind)) {
            return true;
        }
        if ($kind === 'operational') {
            return false;
        }
        $code = strtolower(trim((string)($category['code'] ?? '')));
        if (in_array($code, self::CORE_FINANCIAL_CODES, true) || in_array($code, self::CORE_MANAGEMENT_CODES, true)) {
            return true;
        }
        return in_array($code, self::ADMINISTRATIVE_CODES, true);
    }

    /** @deprecated Use isAccounting() — mantiene compatibilidad con código existente. */
    public function isAdministrative(?array $category): bool
    {
        return $this->isAccounting($category);
    }

    public static function isAccountingKind(string $kind): bool
    {
        if ($kind === '' || $kind === 'operational') {
            return false;
        }
        if (in_array($kind, self::ACCOUNTING_KINDS, true)) {
            return true;
        }
        return str_starts_with($kind, self::SUBGROUP_KIND_PREFIX);
    }

    public static function isValidKind(string $kind): bool
    {
        return $kind === 'operational' || self::isAccountingKind($kind);
    }

    public static function subgroupKind(string $code): string
    {
        return self::SUBGROUP_KIND_PREFIX . strtolower(trim($code));
    }

    public static function accountingKindsSql(): string
    {
        return self::accountingKindsWhere('');
    }

    public static function accountingKindsWhere(string $alias = ''): string
    {
        $prefix = $alias !== '' ? "{$alias}." : '';
        $builtin = "'" . implode("','", self::ACCOUNTING_KINDS) . "'";
        $sg = self::SUBGROUP_KIND_PREFIX;
        return "{$prefix}kind IN ({$builtin}) OR {$prefix}kind LIKE '{$sg}%'";
    }

    /** @return list<array<string,mixed>> */
    public function listSubgroups(bool $includeInactive = false): array
    {
        $where = $includeInactive ? '' : "WHERE status = 'active'";
        return $this->query(
            "SELECT * FROM expense_subgroups {$where} ORDER BY sort_order, name"
        )->fetchAll();
    }

    public function findSubgroup(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->query("SELECT * FROM expense_subgroups WHERE id = ?", [$id])->fetch();
        return $row ?: null;
    }

    public function findSubgroupByCode(string $code): ?array
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return null;
        }
        $row = $this->query(
            "SELECT * FROM expense_subgroups WHERE code = ? LIMIT 1",
            [$code]
        )->fetch();
        return $row ?: null;
    }

    public function saveSubgroup(array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        $name = trim(preg_replace('/\s+/', ' ', (string)($data['name'] ?? '')) ?? '');
        if (mb_strlen($name) < 2) {
            throw new \InvalidArgumentException('El nombre del sub-grupo debe tener al menos 2 caracteres');
        }
        if (mb_strlen($name) > 120) {
            $name = mb_substr($name, 0, 120);
        }
        $account = trim((string)($data['account_code'] ?? '')) ?: '519595';
        $sort = (int)($data['sort_order'] ?? 500);

        if ($id > 0) {
            $existing = $this->findSubgroup($id);
            if (!$existing) {
                throw new \InvalidArgumentException('Sub-grupo no encontrado');
            }
            $this->query(
                "UPDATE expense_subgroups SET name = ?, account_code = ?, sort_order = ?, status = 'active' WHERE id = ?",
                [$name, $account, $sort, $id]
            );
            return $id;
        }

        $code = $this->uniqueSubgroupCode($name);
        $this->query(
            "INSERT INTO expense_subgroups (code, name, account_code, sort_order, status)
             VALUES (?, ?, ?, ?, 'active')",
            [$code, $name, $account, $sort]
        );
        return (int)$this->db->lastInsertId();
    }

    public function deactivateSubgroup(int $id): void
    {
        $row = $this->findSubgroup($id);
        if (!$row) {
            throw new \InvalidArgumentException('Sub-grupo no encontrado');
        }
        $this->query("UPDATE expense_subgroups SET status = 'inactive' WHERE id = ?", [$id]);
    }

    /** @return list<array{subgroup:array<string,mixed>,kind:string,categories:list<array<string,mixed>>}> */
    public function listCustomSubgroupSections(): array
    {
        $sections = [];
        foreach ($this->listSubgroups() as $sg) {
            $kind = self::subgroupKind((string)$sg['code']);
            $sections[] = [
                'subgroup' => $sg,
                'kind' => $kind,
                'categories' => $this->listActive($kind),
            ];
        }
        return $sections;
    }

    public function resolveKindGroupLabel(string $kind): string
    {
        if (str_starts_with($kind, self::SUBGROUP_KIND_PREFIX)) {
            $code = substr($kind, strlen(self::SUBGROUP_KIND_PREFIX));
            $sg = $this->findSubgroupByCode($code);
            $name = (string)($sg['name'] ?? $code);
            return 'Gastos contables — ' . $name;
        }
        return self::kindGroupLabel($kind);
    }

    public function resolveKindLabel(string $kind): string
    {
        if (str_starts_with($kind, self::SUBGROUP_KIND_PREFIX)) {
            $code = substr($kind, strlen(self::SUBGROUP_KIND_PREFIX));
            $sg = $this->findSubgroupByCode($code);
            return (string)($sg['name'] ?? $code);
        }
        return self::kindLabel($kind);
    }

    private function uniqueSubgroupCode(string $name): string
    {
        $base = mb_strtolower(trim($name));
        $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base;
        $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?? '';
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'grupo';
        }
        $base = substr($base, 0, 30);
        $candidate = $base;
        $i = 2;
        while ($this->findSubgroupByCode($candidate)) {
            $candidate = substr($base, 0, 26) . '_' . $i;
            $i++;
            if ($i > 50) {
                $candidate = substr($base, 0, 20) . '_' . bin2hex(random_bytes(2));
                break;
            }
        }
        return $candidate;
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'administrative' => 'Administrativo',
            'financial' => 'Financiero',
            'management' => 'Gestión',
            'operational' => 'Operativo',
            default => $kind,
        };
    }

    public static function kindGroupLabel(string $kind): string
    {
        return match ($kind) {
            'operational' => 'Gastos operativos',
            'administrative' => 'Gastos contables — Administrativos',
            'financial' => 'Gastos contables — Financieros',
            'management' => 'Gastos contables — Operacionales o de gestión',
            default => $kind,
        };
    }

    public static function accountingParentLabel(): string
    {
        return 'Gastos contables';
    }

    /** @return list<array<string,mixed>> */
    private function excludePlaceholdersSql(string $alias = ''): string
    {
        $prefix = $alias !== '' ? "{$alias}." : '';
        $codes = array_merge(self::PLACEHOLDER_CODES, self::SYSTEM_CODES);
        $quoted = implode(',', array_map(fn ($c) => $this->db->quote($c), $codes));
        return "{$prefix}code NOT IN ($quoted)";
    }

    public static function isSystemCategory(?array $category): bool
    {
        return $category !== null
            && in_array(strtolower(trim((string)($category['code'] ?? ''))), self::SYSTEM_CODES, true);
    }

    /** Id de una categoría de sistema; la crea si falta (Nómina → administrativo, 510503). */
    public function systemCategoryId(string $code): int
    {
        if (!in_array($code, self::SYSTEM_CODES, true)) {
            return 0;
        }
        try {
            $id = (int)$this->query(
                'SELECT id FROM expense_categories WHERE code = ? LIMIT 1',
                [$code]
            )->fetchColumn();
            if ($id > 0) {
                return $id;
            }
            $this->query(
                "INSERT IGNORE INTO expense_categories (code, name, kind, account_code, sort_order, status)
                 VALUES (?, 'Nómina (liquidaciones)', 'administrative', '510503', 900, 'active')",
                [$code]
            );
            return (int)$this->query(
                'SELECT id FROM expense_categories WHERE code = ? LIMIT 1',
                [$code]
            )->fetchColumn();
        } catch (\Throwable $e) {
            error_log('ExpenseCategoryService::systemCategoryId: ' . $e->getMessage());
            return 0;
        }
    }

    public function listActive(?string $kind = null): array
    {
        $exclude = $this->excludePlaceholdersSql('');
        $orderBuiltin = "FIELD(kind,'operational','administrative','financial','management')";
        if ($kind === 'accounting') {
            $acct = self::accountingKindsSql();
            return $this->query(
                "SELECT * FROM expense_categories
                 WHERE status = 'active' AND ({$acct}) AND {$exclude}
                 ORDER BY {$orderBuiltin}, sort_order, name"
            )->fetchAll();
        }
        if ($kind === 'administrative' || $kind === 'financial' || $kind === 'management' || str_starts_with((string)$kind, self::SUBGROUP_KIND_PREFIX)) {
            return $this->query(
                "SELECT * FROM expense_categories
                 WHERE status = 'active' AND kind = ? AND {$exclude}
                 ORDER BY sort_order, name",
                [$kind]
            )->fetchAll();
        }
        if ($kind === 'operational') {
            return $this->query(
                "SELECT * FROM expense_categories
                 WHERE status = 'active' AND kind = 'operational' AND {$exclude}
                 ORDER BY sort_order, name"
            )->fetchAll();
        }
        return $this->query(
            "SELECT * FROM expense_categories
             WHERE status = 'active' AND {$exclude}
             ORDER BY {$orderBuiltin}, sort_order, name"
        )->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function listAll(bool $includeInactive = false): array
    {
        $exclude = $this->excludePlaceholdersSql('');
        $where = $includeInactive
            ? "WHERE {$exclude}"
            : "WHERE status = 'active' AND {$exclude}";
        return $this->query(
            "SELECT * FROM expense_categories {$where}
             ORDER BY FIELD(kind,'operational','administrative','financial','management'), sort_order, name"
        )->fetchAll();
    }

    public function findAny(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->query("SELECT * FROM expense_categories WHERE id = ?", [$id])->fetch();
        return $row ?: null;
    }

    public function saveCategory(array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        $name = trim(preg_replace('/\s+/', ' ', (string)($data['name'] ?? '')) ?? '');
        $kind = (string)($data['kind'] ?? 'operational');
        if (!self::isValidKind($kind)) {
            $kind = 'administrative';
        }
        if (mb_strlen($name) < 2) {
            throw new \InvalidArgumentException('El nombre debe tener al menos 2 caracteres');
        }
        if (mb_strlen($name) > 120) {
            $name = mb_substr($name, 0, 120);
        }
        $account = trim((string)($data['account_code'] ?? ''));
        if ($account === '') {
            if (str_starts_with($kind, self::SUBGROUP_KIND_PREFIX)) {
                $code = substr($kind, strlen(self::SUBGROUP_KIND_PREFIX));
                $sg = $this->findSubgroupByCode($code);
                $account = (string)($sg['account_code'] ?? '519595');
            } else {
                $account = match ($kind) {
                    'operational' => '510505',
                    'financial' => '530505',
                    'management' => '519595',
                    default => '519595',
                };
            }
        }
        $sort = (int)($data['sort_order'] ?? match ($kind) {
            'operational' => 60,
            'financial' => 110,
            'management' => 185,
            default => 300,
        });

        if ($id > 0) {
            $existing = $this->findAny($id);
            if (!$existing) {
                throw new \InvalidArgumentException('Categoría no encontrada');
            }
            $this->query(
                "UPDATE expense_categories
                 SET name = ?, kind = ?, account_code = ?, sort_order = ?, status = 'active'
                 WHERE id = ?",
                [$name, $kind, $account, $sort, $id]
            );
            return $id;
        }

        $code = $this->uniqueCode($name);
        $this->query(
            "INSERT INTO expense_categories (code, name, kind, account_code, sort_order, status)
             VALUES (?, ?, ?, ?, ?, 'active')",
            [$code, $name, $kind, $account, $sort]
        );
        return (int)$this->db->lastInsertId();
    }

    public function setKind(int $id, string $kind): void
    {
        if (!self::isValidKind($kind)) {
            throw new \InvalidArgumentException('Grupo inválido');
        }
        $row = $this->findAny($id);
        if (!$row) {
            throw new \InvalidArgumentException('Categoría no encontrada');
        }
        $this->query("UPDATE expense_categories SET kind = ? WHERE id = ?", [$kind, $id]);
    }

    public function deactivate(int $id): void
    {
        $row = $this->findAny($id);
        if (!$row) {
            throw new \InvalidArgumentException('Categoría no encontrada');
        }
        $this->query("UPDATE expense_categories SET status = 'inactive' WHERE id = ?", [$id]);
    }

    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->query(
            "SELECT * FROM expense_categories WHERE id = ? AND status = 'active'",
            [$id]
        )->fetch();
        if ($row && self::isSystemCategory($row)) {
            return null;
        }
        return $row ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return null;
        }
        $row = $this->query(
            "SELECT * FROM expense_categories WHERE code = ? AND status = 'active' LIMIT 1",
            [$code]
        )->fetch();
        return $row ?: null;
    }

    /**
     * Crea (o reutiliza) una categoría permanente, p.ej. desde "Otro" en el desplegable.
     *
     * @return array{id:int,name:string,code:string,kind:string,created:bool}
     */
    public function addOrReuse(string $name, string $kind = 'operational'): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if (mb_strlen($name) < 2) {
            throw new \InvalidArgumentException('Escriba un nombre de categoría válido (mínimo 2 caracteres)');
        }
        if (mb_strlen($name) > 120) {
            $name = mb_substr($name, 0, 120);
        }
        if (!self::isValidKind($kind)) {
            $kind = 'operational';
        }

        $existing = $this->query(
            "SELECT id, name, code, kind, status, account_code FROM expense_categories
             WHERE LOWER(name) = LOWER(?)
               AND code NOT IN ('other_operational','other_administrative','payroll')
             ORDER BY status = 'active' DESC, id DESC
             LIMIT 1",
            [$name]
        )->fetch();
        if ($existing) {
            $id = (int)$existing['id'];
            $existingKind = (string)($existing['kind'] ?? '');
            $existingStatus = (string)($existing['status'] ?? '');
            $account = trim((string)($existing['account_code'] ?? ''));
            if ($account === '') {
                $account = $kind === 'operational' ? '510505' : '519595';
            }
            if ($existingStatus !== 'active' || $existingKind !== $kind || (string)$existing['name'] !== $name) {
                $this->query(
                    "UPDATE expense_categories
                     SET name = ?, kind = ?, account_code = ?, status = 'active'
                     WHERE id = ?",
                    [$name, $kind, $account, $id]
                );
            }
            return [
                'id' => $id,
                'name' => $name,
                'code' => (string)$existing['code'],
                'kind' => $kind,
                'created' => false,
            ];
        }

        $code = $this->uniqueCode($name);
        $account = match ($kind) {
            'operational' => '510505',
            'financial' => '530505',
            'management' => '519595',
            default => '519595',
        };
        $sort = match ($kind) {
            'operational' => 55,
            'financial' => 115,
            'management' => 190,
            default => 900,
        };

        $this->query(
            "INSERT INTO expense_categories (code, name, kind, account_code, sort_order, status)
             VALUES (?, ?, ?, ?, ?, 'active')",
            [$code, $name, $kind, $account, $sort]
        );
        $id = (int)$this->db->lastInsertId();

        return [
            'id' => $id,
            'name' => $name,
            'code' => $code,
            'kind' => $kind,
            'created' => true,
        ];
    }

    private function uniqueCode(string $name): string
    {
        $base = mb_strtolower(trim($name));
        $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base;
        $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?? '';
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'custom';
        }
        $base = 'ec_' . substr($base, 0, 30);

        $candidate = $base;
        $i = 2;
        while ($this->findByCode($candidate) || $this->codeExistsAny($candidate)) {
            $candidate = substr($base, 0, 28) . '_' . $i;
            $i++;
            if ($i > 50) {
                $candidate = substr($base, 0, 20) . '_' . bin2hex(random_bytes(2));
                break;
            }
        }
        return $candidate;
    }

    private function codeExistsAny(string $code): bool
    {
        $n = (int)$this->query(
            "SELECT COUNT(*) FROM expense_categories WHERE code = ?",
            [$code]
        )->fetchColumn();
        return $n > 0;
    }

    public function accountCodeFor(?int $categoryId, string $fallbackCategory = ''): string
    {
        if ($categoryId) {
            $cat = $this->find($categoryId);
            if ($cat && !empty($cat['account_code'])) {
                return (string)$cat['account_code'];
            }
        }
        // Compatibilidad con texto libre legado
        $map = [
            'servicios' => '513505',
            'servicios públicos' => '513505',
            'arriendo' => '512010',
            'arrendamientos' => '512010',
            'gasolina' => '515010',
            'combustible' => '515010',
            'comisiones bancarias' => '530505',
            '4x1000' => '530525',
            'gravamen 4x1000' => '530525',
            'cámara de comercio' => '519595',
            'camara de comercio' => '519595',
            'iva' => '511570',
            'derechos de autor' => '519595',
        ];
        $key = mb_strtolower(trim($fallbackCategory));
        return $map[$key] ?? '510505';
    }
}
