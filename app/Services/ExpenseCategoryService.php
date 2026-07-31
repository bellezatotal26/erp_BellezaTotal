<?php

namespace SoftNova\Services;

/**
 * Catálogo de categorías de gasto (financieros + operativos) con cuenta PUC.
 */
class ExpenseCategoryService
{
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
                kind ENUM('financial','operational') NOT NULL DEFAULT 'operational',
                account_code VARCHAR(20) NOT NULL DEFAULT '510505',
                sort_order INT NOT NULL DEFAULT 0,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ec_kind (kind),
                INDEX idx_ec_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

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
        ];

        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO expense_categories (code, name, kind, account_code, sort_order)
             VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($items as $item) {
            $stmt->execute($item);
        }
    }

    /** @return list<array<string,mixed>> */
    public function listActive(?string $kind = null): array
    {
        if ($kind !== null && in_array($kind, ['financial', 'operational'], true)) {
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
             ORDER BY kind, sort_order, name"
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
        ];
        $key = mb_strtolower(trim($fallbackCategory));
        return $map[$key] ?? '510505';
    }
}
