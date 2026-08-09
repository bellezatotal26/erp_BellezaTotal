<?php

namespace SoftNova\Services;

/**
 * Catálogo de categorías de gasto (financieros, operativos, administrativos) con cuenta PUC.
 */
class ExpenseCategoryService
{
    public const KINDS = ['financial', 'operational', 'administrative'];

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
                kind ENUM('financial','operational','administrative') NOT NULL DEFAULT 'operational',
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
                 MODIFY COLUMN kind ENUM('financial','operational','administrative') NOT NULL DEFAULT 'operational'"
            );
        } catch (\Throwable $e) {
            // ya ampliado o sin privilegios
        }

        // Ampliar expenses para categoría tipada + comprobante foto/PDF
        $this->ensureExpenseColumns();
        $this->seed();
        $done = true;
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

        // payment_method: pasar a VARCHAR para admitir datáfono / link de pago
        if (isset($cols['payment_method'])) {
            $type = strtolower((string)($cols['payment_method']['Type'] ?? ''));
            if (str_starts_with($type, 'enum(')) {
                $alters[] = "MODIFY COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'cash'";
            }
        }

        if ($alters) {
            $this->db->exec('ALTER TABLE expenses ' . implode(', ', $alters));
        }
    }

    private function seed(): void
    {
        $items = [
            // Gastos financieros
            ['bank_fees', 'Comisiones bancarias', 'financial', '530505', 10],
            ['dataphone_fees', 'Comisiones datáfono', 'financial', '530510', 20],
            ['sales_commissions', 'Comisiones de ventas', 'financial', '530515', 30],
            ['gmf_4x1000', 'Gravamen 4x1000', 'financial', '530525', 40],
            ['other_financial', 'Otros gastos financieros', 'financial', '539595', 50],
            // Gastos operativos
            ['general', 'Gastos generales', 'operational', '510505', 100],
            ['rent', 'Arrendamientos', 'operational', '512010', 110],
            ['utilities', 'Servicios públicos', 'operational', '513505', 120],
            ['fuel', 'Combustible / gasolina', 'operational', '515010', 130],
            ['transport', 'Transporte', 'operational', '515505', 140],
            ['maintenance', 'Mantenimiento', 'operational', '514505', 150],
            ['advertising', 'Publicidad', 'operational', '516005', 160],
            ['other_operational', 'Otros gastos operacionales', 'operational', '519595', 170],
            ['inv_warranty', 'Pérdida por garantía', 'operational', '519595', 175],
            ['inv_broken', 'Producto roto / merma', 'operational', '519595', 176],
            ['inv_return', 'Pérdida por devolución', 'operational', '519595', 177],
            ['inv_expiry', 'Pérdida por vencimiento', 'operational', '519595', 178],
            // Gastos administrativos
            ['chamber_commerce', 'Cámara de comercio', 'administrative', '519595', 200],
            ['copyright', 'Derechos de autor', 'administrative', '519595', 210],
            ['iva', 'IVA', 'administrative', '511570', 220],
            ['ica', 'ICA', 'administrative', '511570', 230],
            ['withholding_renta', 'Retención en la fuente', 'administrative', '135515', 240],
            ['licenses_permits', 'Licencias y permisos', 'administrative', '519595', 250],
            ['notary_fees', 'Gastos notariales', 'administrative', '519595', 260],
            ['legal_accounting', 'Asesoría legal / contable', 'administrative', '513095', 270],
            ['other_administrative', 'Otros gastos administrativos', 'administrative', '519595', 280],
        ];

        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO expense_categories (code, name, kind, account_code, sort_order)
             VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($items as $item) {
            $stmt->execute($item);
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

    /** @return list<array<string,mixed>> */
    public function listActive(?string $kind = null): array
    {
        if ($kind !== null && in_array($kind, self::KINDS, true)) {
            return $this->query(
                "SELECT * FROM expense_categories
                 WHERE status = 'active' AND kind = ?
                 ORDER BY sort_order, name",
                [$kind]
            )->fetchAll();
        }
        return $this->query(
            "SELECT * FROM expense_categories
             WHERE status = 'active'
             ORDER BY FIELD(kind,'financial','operational','administrative'), sort_order, name"
        )->fetchAll();
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
    public function addOrReuse(string $name, string $kind = 'administrative'): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if (mb_strlen($name) < 2) {
            throw new \InvalidArgumentException('Escriba un nombre de categoría válido (mínimo 2 caracteres)');
        }
        if (mb_strlen($name) > 120) {
            $name = mb_substr($name, 0, 120);
        }
        if (!in_array($kind, self::KINDS, true)) {
            $kind = 'administrative';
        }

        $existing = $this->query(
            "SELECT id, name, code, kind, status FROM expense_categories
             WHERE LOWER(name) = LOWER(?) LIMIT 1",
            [$name]
        )->fetch();
        if ($existing) {
            if (($existing['status'] ?? '') !== 'active') {
                $this->query(
                    "UPDATE expense_categories SET status = 'active', name = ? WHERE id = ?",
                    [$name, (int)$existing['id']]
                );
            }
            return [
                'id' => (int)$existing['id'],
                'name' => (string)$existing['name'],
                'code' => (string)$existing['code'],
                'kind' => (string)$existing['kind'],
                'created' => false,
            ];
        }

        $code = $this->uniqueCode($name);
        $account = $kind === 'financial' ? '539595' : '519595';
        $sort = $kind === 'administrative' ? 900 : ($kind === 'financial' ? 90 : 190);

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
