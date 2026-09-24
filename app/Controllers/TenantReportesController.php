<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\PdfService;

/**
 * Reportes del tenant con gating por plan (basic vs full)
 */
class TenantReportesController extends TenantController
{
    private const MAX_RANGE_DAYS = 366;
    private const CSV_EXPORT_LIMIT = 50000;

    public function index(): void
    {
        TenantMiddleware::authorize('reportes');

        $plan = TenantMiddleware::getPlanContext();
        $reportsFull = TenantMiddleware::hasFullReports();
        $action = (string)($this->request->get('action') ?? '');

        if ($action === 'export' || $action === 'pdf') {
            if (!$this->canExportAnalytics()) {
                $this->respond(
                    false,
                    'La exportación está disponible en planes Pro/Premium con analíticas completas.',
                    '/app/reportes'
                );
                return;
            }
            TenantMiddleware::authorize('reportes', 'export');
            if ($action === 'export') {
                $this->exportReport();
            } else {
                $this->exportPdf();
            }
            return;
        }

        try {
            $data = $this->buildReportData($reportsFull);
        } catch (\Throwable $e) {
            error_log('Reportes buildReportData: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            [$dateFrom, $dateTo] = $this->reportDateRange();
            $data = $this->emptyReportData($dateFrom, $dateTo);
            $_SESSION['warning'] = 'No se pudieron cargar todos los datos del reporte. Intente de nuevo o contacte soporte si persiste.';
        }

        $this->view('tenant.reportes', $this->tenantViewData(array_merge($data, [
            'plan' => $plan,
            'reportsFull' => $reportsFull,
            'canExport' => $this->canExportAnalytics(),
        ])));
    }

    private function canExportAnalytics(): bool
    {
        return TenantMiddleware::hasFullReports()
            && TenantMiddleware::canExportReports()
            && TenantMiddleware::canDo('export', 'reportes');
    }

    /**
     * @return array{0:string,1:string}
     */
    private function reportDateRange(): array
    {
        $defaultFrom = date('Y-m-d', strtotime('-30 days'));
        $defaultTo = date('Y-m-d');
        $from = $this->validDate((string)($_GET['from'] ?? ''), $defaultFrom);
        $to = $this->validDate((string)($_GET['to'] ?? ''), $defaultTo);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $maxFrom = date('Y-m-d', strtotime($to . ' -' . self::MAX_RANGE_DAYS . ' days'));
        if ($from < $maxFrom) {
            $from = $maxFrom;
        }
        return [$from, $to];
    }

    private function validDate(string $date, string $fallback): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date ? $date : $fallback;
    }

    /** Filtro de fechas (mismo criterio que contabilidad / reportes históricos). */
    private function salesRangeSql(string $alias = 's'): string
    {
        $col = $alias !== '' ? "{$alias}.sale_date" : 'sale_date';
        return "DATE({$col}) BETWEEN ? AND ?";
    }

    private function completedSalesSql(string $alias = ''): string
    {
        $col = $alias !== '' ? "{$alias}.status" : 'status';
        return "{$col} = 'completed'";
    }

    /**
     * Subconsulta reutilizable: pagos acumulados por venta.
     */
    private function salePaymentsSubquerySql(): string
    {
        if (!$this->tableExists('sale_payments')) {
            return '(SELECT NULL AS sale_id, 0 AS paid LIMIT 0)';
        }
        return '(SELECT sale_id, SUM(amount) AS paid FROM sale_payments GROUP BY sale_id)';
    }

    private function saleItemCostExpr(): string
    {
        if ($this->columnExists('sale_items', 'unit_cost')) {
            return 'COALESCE(NULLIF(si.unit_cost, 0), p.purchase_price, 0)';
        }
        return 'COALESCE(p.purchase_price, 0)';
    }

    private function salesSubtotalSumExpr(string $alias = ''): string
    {
        $prefix = $alias !== '' ? "{$alias}." : '';
        if ($this->columnExists('sales', 'subtotal')) {
            return "COALESCE(SUM({$prefix}subtotal), 0)";
        }
        return "COALESCE(SUM({$prefix}total), 0)";
    }

    private function saleItemSubtotalExpr(): string
    {
        if ($this->columnExists('sale_items', 'subtotal')) {
            return 'COALESCE(si.subtotal, si.quantity * si.unit_price, 0)';
        }
        return 'COALESCE(si.quantity * si.unit_price, 0)';
    }

    private function lowStockCondition(): string
    {
        if ($this->columnExists('products', 'min_stock')) {
            return 'stock <= min_stock';
        }
        return 'stock <= 5';
    }

    private function stockMovementTsExpr(string $alias = 'sm'): string
    {
        if ($this->columnExists('stock_movements', 'movement_date')) {
            return "COALESCE({$alias}.movement_date, {$alias}.created_at)";
        }
        return "{$alias}.created_at";
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (!array_key_exists($table, $cache)) {
            try {
                $safe = str_replace(['`', ';'], '', $table);
                $cache[$table] = (bool)$this->db
                    ->query('SHOW TABLES LIKE ' . $this->db->quote($safe))
                    ->fetch();
            } catch (\Throwable $e) {
                $cache[$table] = false;
            }
        }
        return $cache[$table];
    }

    private function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            try {
                $safeTable = str_replace(['`', ';'], '', $table);
                $cache[$key] = (bool)$this->db
                    ->query(
                        'SHOW COLUMNS FROM `' . $safeTable . '` LIKE ' . $this->db->quote($column)
                    )
                    ->fetch();
            } catch (\Throwable $e) {
                $cache[$key] = false;
            }
        }
        return $cache[$key];
    }

    private function fetchScalar(string $sql, array $params, string $column, float|int $default = 0): float|int
    {
        $row = $this->query($sql, $params)->fetch();
        if (!$row || !array_key_exists($column, $row)) {
            return $default;
        }
        return is_int($default) ? (int)$row[$column] : (float)$row[$column];
    }

    /**
     * Ejecuta un bloque y devuelve fallback ante error SQL (evita HTTP 500 en tenants heterogéneos).
     */
    private function tryBlock(callable $fn, mixed $fallback, string $label = 'reportes'): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            error_log('Reportes ' . $label . ': ' . $e->getMessage());
            return $fallback;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyReportData(string $dateFrom, string $dateTo): array
    {
        return [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'prevFrom' => $dateFrom,
            'prevTo' => $dateTo,
            'chartType' => 'line',
            'profitSummary' => [
                'revenue' => 0.0,
                'billed' => 0.0,
                'subtotal' => 0.0,
                'cost' => 0.0,
                'gross' => 0.0,
                'expenses' => 0.0,
                'expenses_fixed' => 0.0,
                'expenses_financial' => 0.0,
                'expenses_operating' => 0.0,
                'net' => 0.0,
                'margin' => 0.0,
            ],
            'receivablesAging' => ['total' => 0, 'd0' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'cnt' => 0],
            'topDebtors' => [],
            'cashSummary' => ['income' => 0, 'expense' => 0, 'net' => 0, 'opening' => 0, 'expected' => 0, 'session' => null],
            'totalSales' => 0.0,
            'totalSalesCount' => 0,
            'prevTotalSales' => 0.0,
            'prevSalesCount' => 0,
            'totalProducts' => 0,
            'totalCustomers' => 0,
            'lowStock' => 0,
            'salesTrend' => 0,
            'countTrend' => 0,
            'ticketTrend' => 0,
            'avgTicket' => 0.0,
            'salesByMonth' => [],
            'salesByDay' => [],
            'topProducts' => [],
            'profitability' => [],
            'salesByCategory' => [],
            'topCustomers' => [],
            'stockMovements' => [],
            'lowStockProducts' => [],
            'productTrend' => [],
            'paymentMethods' => [],
            'marginByChannel' => [],
            'dataphoneRecon' => [
                'sales_total' => 0, 'expected_total' => 0, 'recorded_total' => 0, 'gap' => 0,
                'dataphone_sales' => 0, 'card_sales' => 0, 'rate_dataphone' => 2.5, 'rate_card' => 2.8,
                'suggest_amount' => 0, 'suggest_description' => '', 'suggest_category' => 'dataphone_commission',
            ],
            'expenseBreakdown' => ['fixed' => 0.0, 'financial' => 0.0, 'operating' => 0.0, 'total' => 0.0, 'by_category' => [], 'by_group' => []],
            'commissionSummary' => ['pending' => 0.0, 'paid' => 0.0],
            'inventoryByMonth' => [],
            'inventorySalesByMonth' => [],
        ];
    }

    private function defaultReceivablesAging(): array
    {
        return ['total' => 0, 'd0' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'cnt' => 0];
    }

    /** Asegura unit_cost en tenants antiguos (idempotente). */
    private function ensureSaleItemCostColumn(): void
    {
        if ($this->columnExists('sale_items', 'unit_cost')) {
            return;
        }
        try {
            $this->db->exec(
                'ALTER TABLE sale_items ADD COLUMN unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unit_price'
            );
        } catch (\Throwable $e) {
            error_log('Reportes ensure unit_cost: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function buildReportData(bool $reportsFull): array
    {
        [$dateFrom, $dateTo] = $this->reportDateRange();
        $chartType = $this->normalizeChartType((string)($_GET['chart'] ?? 'line'));
        $costExpr = $this->saleItemCostExpr();
        $itemSubtotal = $this->saleItemSubtotalExpr();
        $lowStockWhere = $this->lowStockCondition();
        $smTs = $this->stockMovementTsExpr('sm');

        $diff = max(86400, strtotime($dateTo) - strtotime($dateFrom));
        $prevFrom = date('Y-m-d', strtotime($dateFrom) - $diff);
        $prevTo = date('Y-m-d', strtotime($dateFrom) - 86400);
        $rangeParams = [$dateFrom, $dateTo];
        $salesRange = $this->salesRangeSql('s');
        $completed = $this->completedSalesSql('s');

        $salesAgg = $this->tryBlock(
            fn () => $this->query(
                "SELECT COALESCE(SUM(total), 0) AS billed, COUNT(*) AS cnt
                 FROM sales
                 WHERE {$this->completedSalesSql('')}
                   AND {$this->salesRangeSql()}",
                $rangeParams
            )->fetch() ?: ['billed' => 0, 'cnt' => 0],
            ['billed' => 0, 'cnt' => 0],
            'salesAgg'
        );

        $totalSales = (float)$salesAgg['billed'];
        $totalSalesCount = (int)$salesAgg['cnt'];
        $avgTicket = $totalSalesCount > 0 ? $totalSales / $totalSalesCount : 0;

        $customersCountSql = $this->tableExists('customers')
            ? "(SELECT COUNT(*) FROM customers WHERE status = 'active')"
            : '(SELECT 0)';

        $counts = $this->tryBlock(
            fn () => $this->query(
                "SELECT
                    (SELECT COUNT(*) FROM products WHERE status = 'active') AS products,
                    {$customersCountSql} AS customers,
                    (SELECT COUNT(*) FROM products WHERE {$lowStockWhere} AND status = 'active') AS low_stock"
            )->fetch() ?: ['products' => 0, 'customers' => 0, 'low_stock' => 0],
            ['products' => 0, 'customers' => 0, 'low_stock' => 0],
            'counts'
        );

        $totalProducts = (int)$counts['products'];
        $totalCustomers = (int)$counts['customers'];
        $lowStock = (int)$counts['low_stock'];

        $salesByDay = $this->tryBlock(
            fn () => $this->query(
                "SELECT DATE(sale_date) AS day, COALESCE(SUM(total), 0) AS total
                 FROM sales
                 WHERE {$this->completedSalesSql('')}
                   AND {$this->salesRangeSql()}
                 GROUP BY DATE(sale_date)
                 ORDER BY day ASC",
                $rangeParams
            )->fetchAll(),
            [],
            'salesByDay'
        );

        $minStockSelect = $this->columnExists('products', 'min_stock') ? 'min_stock' : '5 AS min_stock';
        $lowStockOrder = $this->columnExists('products', 'min_stock') ? '(min_stock - stock)' : '(5 - stock)';
        $lowStockProducts = $this->tryBlock(
            fn () => $this->query(
                "SELECT name, stock, {$minStockSelect}, sale_price FROM products
                 WHERE status = 'active' AND {$lowStockWhere}
                 ORDER BY {$lowStockOrder} DESC LIMIT 10"
            )->fetchAll(),
            [],
            'lowStockProducts'
        );

        $revenue = $this->tryBlock(
            fn () => $this->fetchScalar(
                "SELECT COALESCE(SUM({$itemSubtotal}), 0) t
                 FROM sale_items si
                 JOIN sales s ON si.sale_id = s.id
                 WHERE {$completed} AND {$salesRange}",
                $rangeParams,
                't',
                0.0
            ),
            0.0,
            'revenue'
        );

        $costOfSales = $this->tryBlock(
            fn () => $this->fetchScalar(
                "SELECT COALESCE(SUM(si.quantity * {$costExpr}), 0) t
                 FROM sale_items si
                 JOIN sales s ON si.sale_id = s.id
                 LEFT JOIN products p ON p.id = si.product_id
                 WHERE {$completed} AND {$salesRange}",
                $rangeParams,
                't',
                0.0
            ),
            0.0,
            'costOfSales'
        );

        $periodExpenses = 0.0;
        if ($this->tableExists('expenses')) {
            $periodExpenses = (float)$this->fetchScalar(
                "SELECT COALESCE(SUM(amount), 0) t FROM expenses WHERE expense_date BETWEEN ? AND ?",
                [$dateFrom, $dateTo],
                't',
                0.0
            );
        }

        $grossProfit = (float)$revenue - (float)$costOfSales;
        $netProfit = $grossProfit - (float)$periodExpenses;
        $margin = (float)$revenue > 0 ? round(($netProfit / (float)$revenue) * 100, 1) : 0.0;

        $accounting = new \SoftNova\Services\AccountingService($this->db);
        $expenseBreakdown = ['fixed' => 0.0, 'financial' => 0.0, 'operating' => 0.0, 'total' => 0.0, 'by_category' => [], 'by_group' => []];
        $marginByChannel = [];
        $dataphoneRecon = [
            'sales_total' => 0, 'expected_total' => 0, 'recorded_total' => 0, 'gap' => 0,
            'dataphone_sales' => 0, 'card_sales' => 0, 'rate_dataphone' => 2.5, 'rate_card' => 2.8,
            'suggest_amount' => 0, 'suggest_description' => '', 'suggest_category' => 'dataphone_commission',
        ];
        try {
            $expenseBreakdown = $accounting->expenseBreakdown($dateFrom, $dateTo);
            $marginByChannel = $accounting->marginByChannel($dateFrom, $dateTo);
            $dataphoneRecon = $accounting->dataphoneReconciliation($dateFrom, $dateTo);
        } catch (\Throwable $e) {
            error_log('Reportes accounting: ' . $e->getMessage());
        }

        $commissionSummary = ['pending' => 0.0, 'paid' => 0.0];
        try {
            $comm = new \SoftNova\Services\CommissionService($this->db);
            $commissionSummary = $comm->list(['from' => $dateFrom, 'to' => $dateTo], 1);
        } catch (\Throwable $e) {
            // ignore
        }

        $profitSummary = [
            'revenue' => $revenue,
            'billed' => $totalSales,
            'subtotal' => (float)$revenue,
            'cost' => $costOfSales,
            'gross' => $grossProfit,
            'expenses' => $periodExpenses,
            'expenses_fixed' => (float)($expenseBreakdown['fixed'] ?? 0),
            'expenses_financial' => (float)($expenseBreakdown['financial'] ?? 0),
            'expenses_operating' => (float)($expenseBreakdown['operating'] ?? 0),
            'net' => $netProfit,
            'margin' => $margin,
        ];

        $paymentsJoin = $this->salePaymentsSubquerySql();
        $receivablesAging = $this->defaultReceivablesAging();
        $topDebtors = [];
        if ($this->tableExists('sales') && $this->columnExists('sales', 'payment_status')) {
            $receivablesAging = $this->tryBlock(
                fn () => $this->query(
                    "SELECT
                        COALESCE(SUM(bal), 0) total,
                        COALESCE(SUM(CASE WHEN days <= 30 THEN bal ELSE 0 END), 0) d0,
                        COALESCE(SUM(CASE WHEN days BETWEEN 31 AND 60 THEN bal ELSE 0 END), 0) d30,
                        COALESCE(SUM(CASE WHEN days BETWEEN 61 AND 90 THEN bal ELSE 0 END), 0) d60,
                        COALESCE(SUM(CASE WHEN days > 90 THEN bal ELSE 0 END), 0) d90,
                        COUNT(*) cnt
                     FROM (
                        SELECT (s.total - COALESCE(sp.paid, 0)) bal,
                               DATEDIFF(CURDATE(), DATE(s.sale_date)) days
                        FROM sales s
                        LEFT JOIN {$paymentsJoin} sp ON sp.sale_id = s.id
                        WHERE s.status != 'cancelled'
                          AND s.payment_status IN ('pending','partial')
                     ) x
                     WHERE bal > 0.009"
                )->fetch() ?: $this->defaultReceivablesAging(),
                $this->defaultReceivablesAging(),
                'receivablesAging'
            );

            $topDebtors = $this->tryBlock(
                fn () => $this->query(
                    "SELECT COALESCE(c.name, 'General') customer_name,
                            SUM(s.total - COALESCE(sp.paid, 0)) balance,
                            MAX(DATEDIFF(CURDATE(), DATE(s.sale_date))) max_days
                     FROM sales s
                     LEFT JOIN customers c ON c.id = s.customer_id
                     LEFT JOIN {$paymentsJoin} sp ON sp.sale_id = s.id
                     WHERE s.status != 'cancelled'
                       AND s.payment_status IN ('pending','partial')
                     GROUP BY s.customer_id, c.name
                     HAVING balance > 0.009
                     ORDER BY balance DESC
                     LIMIT 8"
                )->fetchAll(),
                [],
                'topDebtors'
            );
        }

        $cashToday = ['income' => 0, 'expense' => 0];
        $openSession = null;
        if ($this->tableExists('cash_movements')) {
            $cashToday = $this->tryBlock(
                fn () => $this->query(
                    "SELECT
                        COALESCE(SUM(CASE WHEN cm.type = 'income' THEN cm.amount ELSE 0 END), 0) income,
                        COALESCE(SUM(CASE WHEN cm.type = 'expense' THEN cm.amount ELSE 0 END), 0) expense
                     FROM cash_movements cm
                     WHERE DATE(cm.created_at) = CURDATE()"
                )->fetch() ?: ['income' => 0, 'expense' => 0],
                ['income' => 0, 'expense' => 0],
                'cashToday'
            );
        }
        if ($this->tableExists('cash_sessions')) {
            $openSession = $this->tryBlock(
                fn () => $this->query(
                    "SELECT cs.*, u.name user_name FROM cash_sessions cs
                     LEFT JOIN users u ON u.id = cs.user_id
                     WHERE cs.status = 'open' ORDER BY cs.opening_date DESC LIMIT 1"
                )->fetch(),
                null,
                'openSession'
            );
        }

        $cashSummary = [
            'income' => (float)$cashToday['income'],
            'expense' => (float)$cashToday['expense'],
            'net' => (float)$cashToday['income'] - (float)$cashToday['expense'],
            'opening' => $openSession ? (float)$openSession['opening_amount'] : 0.0,
            'expected' => $openSession
                ? (float)$openSession['opening_amount'] + (float)$cashToday['income'] - (float)$cashToday['expense']
                : 0.0,
            'session' => $openSession ?: null,
        ];

        $productLimit = $reportsFull ? 10 : 5;
        $topProducts = $this->tryBlock(
            fn () => $this->query(
                "SELECT p.name,
                        SUM(si.quantity) AS total_qty,
                        SUM({$itemSubtotal}) AS total_sales,
                        SUM({$itemSubtotal}) - SUM(si.quantity * {$costExpr}) AS profit
                 FROM sale_items si
                 JOIN products p ON si.product_id = p.id
                 JOIN sales s ON si.sale_id = s.id
                 WHERE {$completed} AND {$salesRange}
                 GROUP BY p.id, p.name
                 ORDER BY total_qty DESC
                 LIMIT {$productLimit}",
                $rangeParams
            )->fetchAll(),
            [],
            'topProducts'
        );

        $profitability = $this->tryBlock(
            fn () => $this->query(
                "SELECT p.name,
                        SUM(si.quantity) AS qty,
                        SUM({$itemSubtotal}) AS revenue,
                        SUM({$itemSubtotal}) - SUM(si.quantity * {$costExpr}) AS profit
                 FROM sale_items si
                 JOIN products p ON si.product_id = p.id
                 JOIN sales s ON si.sale_id = s.id
                 WHERE {$completed} AND {$salesRange}
                 GROUP BY p.id, p.name
                 ORDER BY profit DESC
                 LIMIT {$productLimit}",
                $rangeParams
            )->fetchAll(),
            [],
            'profitability'
        );

        $inventoryByMonth = [];
        $inventorySalesByMonth = [];
        if ($this->tableExists('stock_movements')) {
            $inventoryByMonth = $this->tryBlock(
                fn () => $this->query(
                    "SELECT DATE_FORMAT({$smTs}, '%Y-%m') AS month,
                            COALESCE(SUM(CASE WHEN sm.type = 'in' THEN sm.quantity ELSE 0 END), 0) qty_in,
                            COALESCE(SUM(CASE WHEN sm.type = 'out' THEN sm.quantity ELSE 0 END), 0) qty_out,
                            COALESCE(SUM(CASE WHEN sm.type = 'adjustment' THEN sm.quantity ELSE 0 END), 0) qty_adj,
                            COUNT(*) movements
                     FROM stock_movements sm
                     WHERE {$smTs} >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                     GROUP BY DATE_FORMAT({$smTs}, '%Y-%m')
                     ORDER BY month ASC"
                )->fetchAll(),
                [],
                'inventoryByMonth'
            );

            $inventorySalesByMonth = $this->tryBlock(
                fn () => $this->query(
                    "SELECT DATE_FORMAT(s.sale_date, '%Y-%m') AS month,
                            COALESCE(SUM(si.quantity), 0) units_sold,
                            COALESCE(SUM({$itemSubtotal}), 0) revenue
                     FROM sale_items si
                     JOIN sales s ON s.id = si.sale_id
                     WHERE {$completed}
                       AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                     GROUP BY DATE_FORMAT(s.sale_date, '%Y-%m')
                     ORDER BY month ASC"
                )->fetchAll(),
                [],
                'inventorySalesByMonth'
            );
        }

        $prevTotalSales = 0;
        $prevSalesCount = 0;
        $salesTrend = 0;
        $countTrend = 0;
        $ticketTrend = 0;
        $salesByMonth = [];
        $salesByCategory = [];
        $topCustomers = [];
        $stockMovements = [];
        $productTrend = [];
        $paymentMethods = [];

        if ($reportsFull) {
            $prevRange = [$prevFrom, $prevTo];
            $prevAgg = $this->tryBlock(
                fn () => $this->query(
                    "SELECT COALESCE(SUM(total), 0) AS billed, COUNT(*) AS cnt
                     FROM sales
                     WHERE {$this->completedSalesSql('')}
                       AND {$this->salesRangeSql()}",
                    $prevRange
                )->fetch() ?: ['billed' => 0, 'cnt' => 0],
                ['billed' => 0, 'cnt' => 0],
                'prevAgg'
            );

            $prevTotalSales = (float)$prevAgg['billed'];
            $prevSalesCount = (int)$prevAgg['cnt'];

            $salesByMonth = $this->tryBlock(
                fn () => $this->query(
                    "SELECT DATE_FORMAT(sale_date, '%Y-%m') AS month,
                            COALESCE(SUM(total), 0) AS total,
                            COUNT(*) AS count
                     FROM sales
                     WHERE {$this->completedSalesSql('')}
                       AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                     GROUP BY DATE_FORMAT(sale_date, '%Y-%m')
                     ORDER BY month ASC"
                )->fetchAll(),
                [],
                'salesByMonth'
            );

            if ($this->tableExists('categories')) {
                $salesByCategory = $this->tryBlock(
                    fn () => $this->query(
                        "SELECT COALESCE(c.name, 'Sin categoria') AS category,
                                COALESCE(SUM({$itemSubtotal}), 0) AS total
                         FROM sale_items si
                         LEFT JOIN products p ON si.product_id = p.id
                         LEFT JOIN categories c ON p.category_id = c.id
                         JOIN sales s ON si.sale_id = s.id
                         WHERE {$completed} AND {$salesRange}
                         GROUP BY COALESCE(c.name, 'Sin categoria')
                         ORDER BY total DESC",
                        $rangeParams
                    )->fetchAll(),
                    [],
                    'salesByCategory'
                );
            }

            if ($this->tableExists('customers')) {
                $topCustomers = $this->tryBlock(
                    fn () => $this->query(
                        "SELECT COALESCE(cu.name, 'General') AS customer_name,
                                COUNT(s.id) AS purchase_count,
                                COALESCE(SUM(s.total), 0) AS total
                         FROM sales s
                         LEFT JOIN customers cu ON s.customer_id = cu.id
                         WHERE {$completed}
                           AND {$salesRange}
                           AND s.customer_id IS NOT NULL
                         GROUP BY s.customer_id, cu.name
                         ORDER BY total DESC
                         LIMIT 10",
                        $rangeParams
                    )->fetchAll(),
                    [],
                    'topCustomers'
                );
            }

            if ($this->tableExists('stock_movements')) {
                $stockMovements = $this->tryBlock(
                    fn () => $this->query(
                        "SELECT sm.*, p.name AS product_name
                         FROM stock_movements sm
                         JOIN products p ON sm.product_id = p.id
                         ORDER BY {$smTs} DESC
                         LIMIT 20"
                    )->fetchAll(),
                    [],
                    'stockMovements'
                );
            }

            $productTrend = $this->tryBlock(
                fn () => $this->query(
                    "SELECT p.name,
                            COALESCE(SUM(CASE WHEN s.sale_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN si.quantity ELSE 0 END), 0) AS current_month,
                            COALESCE(SUM(CASE WHEN s.sale_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
                                               AND s.sale_date < DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN si.quantity ELSE 0 END), 0) AS last_month
                     FROM sale_items si
                     JOIN products p ON si.product_id = p.id
                     JOIN sales s ON si.sale_id = s.id
                     WHERE {$completed}
                       AND s.sale_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
                     GROUP BY p.id, p.name
                     HAVING current_month > 0 OR last_month > 0
                     ORDER BY current_month DESC
                     LIMIT 10"
                )->fetchAll(),
                [],
                'productTrend'
            );

            if ($this->tableExists('sale_payments')) {
                $paymentMethods = $this->tryBlock(
                    fn () => $this->query(
                        "SELECT pm.payment_method,
                                COUNT(*) AS count,
                                COALESCE(SUM(pm.amount), 0) AS total
                         FROM sale_payments pm
                         JOIN sales s ON s.id = pm.sale_id
                         WHERE {$completed} AND {$salesRange}
                         GROUP BY pm.payment_method
                         ORDER BY total DESC",
                        $rangeParams
                    )->fetchAll(),
                    [],
                    'paymentMethods'
                );
            }

            if ($paymentMethods === []) {
                $paymentMethods = $this->tryBlock(
                    fn () => $this->query(
                        "SELECT payment_method, COUNT(*) AS count, COALESCE(SUM(total), 0) AS total
                         FROM sales
                         WHERE {$this->completedSalesSql('')}
                           AND {$this->salesRangeSql()}
                         GROUP BY payment_method
                         ORDER BY count DESC",
                        $rangeParams
                    )->fetchAll(),
                    [],
                    'paymentMethodsFallback'
                );
            }

            $salesTrend = $prevTotalSales > 0
                ? round((($totalSales - $prevTotalSales) / $prevTotalSales) * 100, 1)
                : 0;
            $countTrend = $prevSalesCount > 0
                ? round((($totalSalesCount - $prevSalesCount) / $prevSalesCount) * 100, 1)
                : 0;
            $prevAvgTicket = $prevSalesCount > 0 ? $prevTotalSales / $prevSalesCount : 0;
            $ticketTrend = $prevAvgTicket > 0
                ? round((($avgTicket - $prevAvgTicket) / $prevAvgTicket) * 100, 1)
                : 0;
        }

        return [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'prevFrom' => $prevFrom,
            'prevTo' => $prevTo,
            'chartType' => $chartType,
            'profitSummary' => $profitSummary,
            'receivablesAging' => $receivablesAging,
            'topDebtors' => $topDebtors,
            'cashSummary' => $cashSummary,
            'totalSales' => $totalSales,
            'totalSalesCount' => $totalSalesCount,
            'prevTotalSales' => $prevTotalSales,
            'prevSalesCount' => $prevSalesCount,
            'totalProducts' => $totalProducts,
            'totalCustomers' => $totalCustomers,
            'lowStock' => $lowStock,
            'salesTrend' => $salesTrend,
            'countTrend' => $countTrend,
            'ticketTrend' => $ticketTrend,
            'avgTicket' => $avgTicket,
            'salesByMonth' => $salesByMonth,
            'salesByDay' => $salesByDay,
            'topProducts' => $topProducts,
            'profitability' => $profitability,
            'salesByCategory' => $salesByCategory,
            'topCustomers' => $topCustomers,
            'stockMovements' => $stockMovements,
            'lowStockProducts' => $lowStockProducts,
            'productTrend' => $productTrend,
            'paymentMethods' => $paymentMethods,
            'marginByChannel' => $marginByChannel,
            'dataphoneRecon' => $dataphoneRecon,
            'expenseBreakdown' => $expenseBreakdown,
            'commissionSummary' => $commissionSummary,
            'inventoryByMonth' => $inventoryByMonth,
            'inventorySalesByMonth' => $inventorySalesByMonth,
        ];
    }

    private function normalizeChartType(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, ['line', 'bar', 'pie', 'doughnut'], true) ? $type : 'line';
    }

    private function exportReport(): void
    {
        [$dateFrom, $dateTo] = $this->reportDateRange();
        $salesRange = $this->salesRangeSql('s');
        $completed = $this->completedSalesSql('s');
        $subtotalCol = $this->columnExists('sales', 'subtotal') ? 's.subtotal' : 's.total';
        $taxCol = $this->columnExists('sales', 'tax') ? 's.tax' : '0';

        $rows = $this->query(
            "SELECT s.invoice_number, COALESCE(c.name, 'General') AS customer_name, s.sale_date,
                    {$subtotalCol} AS subtotal, {$taxCol} AS tax, s.total, s.payment_method, s.payment_status
             FROM sales s
             LEFT JOIN customers c ON s.customer_id = c.id
             WHERE {$completed} AND {$salesRange}
             ORDER BY s.sale_date DESC
             LIMIT " . self::CSV_EXPORT_LIMIT,
            [$dateFrom, $dateTo]
        )->fetchAll();

        $csvRows = [];
        foreach ($rows as $r) {
            $csvRows[] = [
                $r['invoice_number'],
                $r['customer_name'],
                $r['sale_date'],
                $r['subtotal'],
                $r['tax'],
                $r['total'],
                $r['payment_method'],
                $r['payment_status'],
            ];
        }

        $suffix = count($rows) >= self::CSV_EXPORT_LIMIT ? '_max' . self::CSV_EXPORT_LIMIT : '';
        $this->exportCsv(
            'ventas_' . $dateFrom . '_' . $dateTo . $suffix . '.csv',
            ['Factura', 'Cliente', 'Fecha', 'Subtotal', 'Impuesto', 'Total', 'Metodo', 'Pago'],
            $csvRows
        );
    }

    private function exportPdf(): void
    {
        $reportsFull = TenantMiddleware::hasFullReports();
        $data = $this->buildReportData($reportsFull);
        $plan = TenantMiddleware::getPlanContext();
        $data['channelLabels'] = \SoftNova\Core\product_channels();
        $data['reportsFull'] = $reportsFull;
        $data['planName'] = (string)($plan['plan_name'] ?? 'Plan');

        try {
            $pdf = new PdfService($this->companySettings(), $this->getCurrency());
            $content = $pdf->generateAnalyticsReport($data);
            $pdf->download($content, 'informe_analitico_' . date('Ymd_His') . '.pdf');
        } catch (\Throwable $e) {
            error_log('PDF reportes: ' . $e->getMessage());
            $this->respond(false, 'No se pudo generar el PDF. Intente de nuevo o contacte soporte.', '/app/reportes');
        }
    }
}
