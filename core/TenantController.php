<?php

namespace SoftNova\Core;

/**
 * Clase base para controladores del panel tenant
 * Centraliza PDO, moneda, paginación y exportación CSV
 */
abstract class TenantController extends Controller
{
    protected \PDO $db;
    protected int $perPage = 25;
    private ?array $currencyCache = null;
    
    public function __construct()
    {
        parent::__construct();
        TenantMiddleware::auth();
        $this->db = TenantMiddleware::getDb();
    }
    
    protected function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    protected function getCurrency(): array
    {
        if ($this->currencyCache !== null) {
            return $this->currencyCache;
        }
        
        $c = $this->query("SELECT setting_value FROM settings WHERE setting_key = 'currency'")->fetch();
        $code = $c['setting_value'] ?? 'COP';
        
        $currencies = [
            'COP' => ['symbol' => '$', 'name' => 'Peso Colombiano', 'decimals' => 2, 'thousands' => '.', 'decimal' => ','],
            'USD' => ['symbol' => 'US$', 'name' => 'Dolar Estadounidense', 'decimals' => 2, 'thousands' => ',', 'decimal' => '.'],
            'EUR' => ['symbol' => '€', 'name' => 'Euro', 'decimals' => 2, 'thousands' => '.', 'decimal' => ','],
        ];
        
        $this->currencyCache = $currencies[$code] ?? $currencies['COP'];
        return $this->currencyCache;
    }
    
    protected function formatMoney(float $amount): string
    {
        $c = $this->getCurrency();
        return $c['symbol'] . ' ' . number_format($amount, $c['decimals'], $c['decimal'], $c['thousands']);
    }
    
    /**
     * Datos comunes para vistas tenant
     */
    protected function tenantViewData(array $extra = []): array
    {
        return array_merge([
            'tenantName' => $_SESSION['tenant_name'] ?? 'Mi Empresa',
            'userName' => $_SESSION['tenant_user_name'] ?? 'Usuario',
            'currency' => $this->getCurrency(),
        ], $extra);
    }

    protected function refreshDbConnection(): void
    {
        $this->db = TenantMiddleware::refreshDb();
    }
    
    /**
     * Paginación server-side
     * @return array{page:int,perPage:int,offset:int,total:int,totalPages:int}
     */
    protected function paginate(int $total, ?int $perPage = null): array
    {
        $perPage = $perPage ?? $this->perPage;
        $page = max(1, (int)$this->request->get('page', 1));
        $totalPages = max(1, (int)ceil($total / max(1, $perPage)));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        
        return [
            'page' => $page,
            'perPage' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Cantidad por página (mismo patrón que Inventario).
     *
     * @param list<int> $options
     * @return array{perPage:int,options:list<int>}
     */
    protected function resolvePerPage(array $options = [20, 50, 100, 200], int $default = 20): array
    {
        $options = array_values(array_unique(array_map('intval', $options)));
        if ($options === []) {
            $options = [20, 50, 100, 200];
        }
        if (!in_array($default, $options, true)) {
            $default = $options[0];
        }
        $perPage = (int)$this->request->get('per_page', $default);
        if (!in_array($perPage, $options, true)) {
            $perPage = $default;
        }
        return ['perPage' => $perPage, 'options' => $options];
    }

    /**
     * Filtros de listado: búsqueda, fechas, estado y ordenamiento seguro.
     *
     * @param array<string,string> $sortableMap columna_request => expresión SQL
     * @return array{q:string,from:string,to:string,status:string,sort:string,dir:string,orderSql:string,query:array}
     */
    protected function listFilters(array $sortableMap, string $defaultSort, string $defaultDir = 'desc'): array
    {
        $q = trim((string)$this->request->get('q', ''));
        $from = trim((string)$this->request->get('from', ''));
        $to = trim((string)$this->request->get('to', ''));
        $status = trim((string)$this->request->get('status', ''));
        $sort = (string)$this->request->get('sort', $defaultSort);
        $dir = strtolower((string)$this->request->get('dir', $defaultDir)) === 'asc' ? 'asc' : 'desc';

        if (!isset($sortableMap[$sort])) {
            $sort = $defaultSort;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = '';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = '';
        }

        return [
            'q' => $q,
            'from' => $from,
            'to' => $to,
            'status' => $status,
            'sort' => $sort,
            'dir' => $dir,
            'orderSql' => $sortableMap[$sort] . ' ' . strtoupper($dir),
            'query' => array_filter([
                'q' => $q !== '' ? $q : null,
                'from' => $from !== '' ? $from : null,
                'to' => $to !== '' ? $to : null,
                'status' => $status !== '' ? $status : null,
                'sort' => $sort !== $defaultSort ? $sort : null,
                'dir' => ($sort !== $defaultSort || $dir !== $defaultDir) ? $dir : null,
            ], static fn($v) => $v !== null && $v !== ''),
        ];
    }
    
    /**
     * Exportar CSV y terminar la petición
     */
    protected function exportCsv(string $filename, array $headers, array $rows): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, $headers, ';');
        
        foreach ($rows as $row) {
            fputcsv($out, $row, ';');
        }
        
        fclose($out);
        exit;
    }
    
    protected function getSetting(string $key, string $default = ''): string
    {
        return \SoftNova\Services\SettingsCache::get($this->db, $key, $default);
    }
    
    protected function companySettings(): array
    {
        return \SoftNova\Services\SettingsCache::all($this->db);
    }
    
    /**
     * Garantiza tablas de cotizaciones (fallback si no se ejecuto migracion 011)
     */
    protected function ensureQuotesSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        
        try {
            $this->query("SELECT 1 FROM quotes LIMIT 0");
        } catch (\Exception $e) {
            $this->db->exec("CREATE TABLE IF NOT EXISTS quotes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                quote_number VARCHAR(50) NOT NULL UNIQUE,
                customer_id INT UNSIGNED,
                user_id INT UNSIGNED,
                quote_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                subtotal DECIMAL(12,2) DEFAULT 0,
                tax DECIMAL(12,2) DEFAULT 0,
                total DECIMAL(12,2) DEFAULT 0,
                status ENUM('pending','accepted','rejected','converted') DEFAULT 'pending',
                notes TEXT,
                valid_until DATE,
                sale_id INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_customer(customer_id), INDEX idx_status(status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            $this->db->exec("CREATE TABLE IF NOT EXISTS quote_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                quote_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED,
                product_name VARCHAR(255) NOT NULL,
                quantity INT NOT NULL DEFAULT 1,
                unit_price DECIMAL(12,2) DEFAULT 0,
                subtotal DECIMAL(12,2) DEFAULT 0,
                tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
                tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                INDEX idx_quote(quote_id),
                FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        try {
            $col = $this->query("SHOW COLUMNS FROM quotes LIKE 'sale_id'")->fetch();
            if (!$col) {
                $this->db->exec("ALTER TABLE quotes ADD COLUMN sale_id INT UNSIGNED NULL AFTER valid_until");
            }
        } catch (\Throwable $e) {
            // ignore
        }
        foreach ([
            ['tax_rate', 'DECIMAL(5,2) NOT NULL DEFAULT 0'],
            ['tax_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ] as [$colName, $def]) {
            try {
                $col = $this->query("SHOW COLUMNS FROM quote_items LIKE ?", [$colName])->fetch();
                if (!$col) {
                    $this->db->exec("ALTER TABLE quote_items ADD COLUMN `{$colName}` {$def}");
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        
        $done = true;
    }
}
