<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;

/**
 * Reportes del tenant con gating por plan (basic vs full)
 */
class TenantReportesController extends TenantController
{
    public function index(): void
    {
        TenantMiddleware::authorize('reportes');
        
        $plan = TenantMiddleware::getPlanContext();
        $reportsFull = ($plan['reports'] ?? 'basic') === 'full';
        
        if (($this->request->get('action') ?? '') === 'export') {
            if (!TenantMiddleware::canExportReports()) {
                $this->respond(
                    false,
                    'La exportacion esta disponible en planes Pro, Premium o personalizados.',
                    '/app/reportes'
                );
                return;
            }
            TenantMiddleware::authorize('reportes', 'export');
            $this->exportReport();
            return;
        }
        
        $this->ensureSaleItemCostColumn();
        \SoftNova\Services\TenantOpsSchema::ensure($this->db);

        $currency = $this->getCurrency();
        $dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $_GET['to'] ?? date('Y-m-d');
        
        $diff = strtotime($dateTo) - strtotime($dateFrom);
        $prevFrom = date('Y-m-d', strtotime($dateFrom) - $diff - 86400);
        $prevTo = date('Y-m-d', strtotime($dateFrom) - 86400);
        
        // --- Datos basicos (todos los planes) ---
        $totalSales = (float)$this->query(
            "SELECT COALESCE(SUM(total), 0) as t FROM sales WHERE status = 'completed' AND DATE(sale_date) BETWEEN ? AND ?",
            [$dateFrom, $dateTo]
        )->fetch()['t'];
        
        $totalSalesCount = (int)$this->query(
            "SELECT COUNT(*) as c FROM sales WHERE status = 'completed' AND DATE(sale_date) BETWEEN ? AND ?",
            [$dateFrom, $dateTo]
        )->fetch()['c'];
        
        $totalProducts = (int)$this->query("SELECT COUNT(*) as c FROM products WHERE status = 'active'")->fetch()['c'];
        $totalCustomers = (int)$this->query("SELECT COUNT(*) as c FROM customers WHERE status = 'active'")->fetch()['c'];
        $lowStock = (int)$this->query("SELECT COUNT(*) as c FROM products WHERE stock <= min_stock AND status = 'active'")->fetch()['c'];
        
        $salesByDay = $this->query(
            "SELECT DATE(sale_date) as day, COALESCE(SUM(total), 0) as total
             FROM sales WHERE status = 'completed' AND DATE(sale_date) BETWEEN ? AND ?
             GROUP BY DATE(sale_date) ORDER BY day ASC",
            [$dateFrom, $dateTo]
        )->fetchAll();
        
        $lowStockProducts = $this->query(
            "SELECT name, stock, min_stock, sale_price FROM products
             WHERE status = 'active' AND stock <= min_stock
             ORDER BY (min_stock - stock) DESC LIMIT 10"
        )->fetchAll();
        
        $avgTicket = $totalSalesCount > 0 ? $totalSales / $totalSalesCount : 0;

        // === Reportes gancho (todos los planes) ===
        // 1) Utilidad del periodo: ingresos - costo de ventas - gastos
        $revenue = (float)$this->query(
            "SELECT COALESCE(SUM(si.subtotal), 0) t
             FROM sale_items si JOIN sales s ON si.sale_id = s.id
             WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?",
            [$dateFrom, $dateTo]
        )->fetch()['t'];
        $costOfSales = (float)$this->query(
            "SELECT COALESCE(SUM(si.quantity * COALESCE(NULLIF(si.unit_cost, 0), p.purchase_price, 0)), 0) t
             FROM sale_items si
             JOIN sales s ON si.sale_id = s.id
             LEFT JOIN products p ON p.id = si.product_id
             WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?",
            [$dateFrom, $dateTo]
        )->fetch()['t'];
        $periodExpenses = (float)$this->query(
            "SELECT COALESCE(SUM(amount), 0) t FROM expenses WHERE expense_date BETWEEN ? AND ?",
            [$dateFrom, $dateTo]
        )->fetch()['t'];
        $grossProfit = $revenue - $costOfSales;
        $netProfit = $grossProfit - $periodExpenses;
        $margin = $revenue > 0 ? round(($netProfit / $revenue) * 100, 1) : 0.0;

        $accounting = new \SoftNova\Services\AccountingService($this->db);
        $accounting->auditCriticalAccounts();
        $expenseBreakdown = $accounting->expenseBreakdown($dateFrom, $dateTo);
        $marginByChannel = $accounting->marginByChannel($dateFrom, $dateTo);
        $dataphoneRecon = $accounting->dataphoneReconciliation($dateFrom, $dateTo);

        $commissionSummary = ['pending' => 0.0, 'paid' => 0.0];
        try {
            $comm = new \SoftNova\Services\CommissionService($this->db);
            $commissionSummary = $comm->list(['from' => $dateFrom, 'to' => $dateTo], 1);
        } catch (\Throwable $e) {
            // ignore
        }

        $profitSummary = [
            'revenue' => $revenue,
            'cost' => $costOfSales,
            'gross' => $grossProfit,
            'expenses' => $periodExpenses,
            'expenses_fixed' => (float)($expenseBreakdown['fixed'] ?? 0),
            'expenses_financial' => (float)($expenseBreakdown['financial'] ?? 0),
            'expenses_operating' => (float)($expenseBreakdown['operating'] ?? 0),
            'net' => $netProfit,
            'margin' => $margin,
        ];

        // 2) Cuentas por cobrar (aging) sobre saldo pendiente
        $receivablesAging = $this->query(
            "SELECT
                COALESCE(SUM(bal), 0) total,
                COALESCE(SUM(CASE WHEN days <= 30 THEN bal ELSE 0 END), 0) d0,
                COALESCE(SUM(CASE WHEN days BETWEEN 31 AND 60 THEN bal ELSE 0 END), 0) d30,
                COALESCE(SUM(CASE WHEN days BETWEEN 61 AND 90 THEN bal ELSE 0 END), 0) d60,
                COALESCE(SUM(CASE WHEN days > 90 THEN bal ELSE 0 END), 0) d90,
                COUNT(*) cnt
             FROM (
                SELECT (s.total - COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp WHERE sp.sale_id = s.id), 0)) bal,
                       DATEDIFF(NOW(), s.sale_date) days
                FROM sales s
                WHERE s.status != 'cancelled' AND s.payment_status IN ('pending','partial')
             ) x
             WHERE bal > 0.009"
        )->fetch() ?: [];
        $topDebtors = $this->query(
            "SELECT COALESCE(c.name, 'General') customer_name,
                    SUM(s.total - COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp WHERE sp.sale_id = s.id), 0)) balance,
                    MAX(DATEDIFF(NOW(), s.sale_date)) max_days
             FROM sales s
             LEFT JOIN customers c ON c.id = s.customer_id
             WHERE s.status != 'cancelled' AND s.payment_status IN ('pending','partial')
             GROUP BY s.customer_id, c.name
             HAVING balance > 0.009
             ORDER BY balance DESC LIMIT 8"
        )->fetchAll();

        // 3) Caja del día (sesiones abiertas hoy)
        $cashToday = $this->query(
            "SELECT
                COALESCE(SUM(CASE WHEN cm.type = 'income' THEN cm.amount ELSE 0 END), 0) income,
                COALESCE(SUM(CASE WHEN cm.type = 'expense' THEN cm.amount ELSE 0 END), 0) expense
             FROM cash_movements cm
             WHERE DATE(cm.created_at) = CURDATE()"
        )->fetch() ?: ['income' => 0, 'expense' => 0];
        $openSession = $this->query(
            "SELECT cs.*, u.name user_name FROM cash_sessions cs
             LEFT JOIN users u ON u.id = cs.user_id
             WHERE cs.status = 'open' ORDER BY cs.opening_date DESC LIMIT 1"
        )->fetch();
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

        // 4) Top productos (compacto, todos los planes) + stock crítico ya calculado
        $topSellers = $this->query(
            "SELECT p.name, SUM(si.quantity) qty, SUM(si.subtotal) total
             FROM sale_items si
             JOIN products p ON p.id = si.product_id
             JOIN sales s ON s.id = si.sale_id
             WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
             GROUP BY p.id, p.name ORDER BY qty DESC LIMIT 5",
            [$dateFrom, $dateTo]
        )->fetchAll();
        
        // Defaults avanzados
        $prevTotalSales = 0;
        $prevSalesCount = 0;
        $salesTrend = 0;
        $countTrend = 0;
        $ticketTrend = 0;
        $salesByMonth = [];
        $topProducts = [];
        $profitability = [];
        $salesByCategory = [];
        $topCustomers = [];
        $stockMovements = [];
        $productTrend = [];
        $paymentMethods = [];
        
        if ($reportsFull) {
            $prevTotalSales = (float)$this->query(
                "SELECT COALESCE(SUM(total), 0) as t FROM sales WHERE status = 'completed' AND DATE(sale_date) BETWEEN ? AND ?",
                [$prevFrom, $prevTo]
            )->fetch()['t'];
            
            $prevSalesCount = (int)$this->query(
                "SELECT COUNT(*) as c FROM sales WHERE status = 'completed' AND DATE(sale_date) BETWEEN ? AND ?",
                [$prevFrom, $prevTo]
            )->fetch()['c'];
            
            $salesByMonth = $this->query("
                SELECT DATE_FORMAT(sale_date, '%Y-%m') as month,
                       COALESCE(SUM(total), 0) as total, COUNT(*) as count
                FROM sales
                WHERE status = 'completed' AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(sale_date, '%Y-%m')
                ORDER BY month ASC
            ")->fetchAll();
            
            $topProducts = $this->query("
                SELECT p.name, SUM(si.quantity) as total_qty, SUM(si.subtotal) as total_sales
                FROM sale_items si
                JOIN products p ON si.product_id = p.id
                JOIN sales s ON si.sale_id = s.id
                WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
                GROUP BY p.id, p.name
                ORDER BY total_qty DESC LIMIT 10
            ", [$dateFrom, $dateTo])->fetchAll();
            
            $profitability = $this->query("
                SELECT p.name,
                       SUM(si.quantity) as qty,
                       SUM(si.subtotal) as revenue,
                       SUM(si.subtotal) - SUM(si.quantity * COALESCE(p.purchase_price, 0)) as profit
                FROM sale_items si
                JOIN products p ON si.product_id = p.id
                JOIN sales s ON si.sale_id = s.id
                WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
                GROUP BY p.id, p.name
                ORDER BY profit DESC LIMIT 10
            ", [$dateFrom, $dateTo])->fetchAll();
            
            $salesByCategory = $this->query("
                SELECT COALESCE(c.name, 'Sin categoria') as category, COALESCE(SUM(si.subtotal), 0) as total
                FROM sale_items si
                LEFT JOIN products p ON si.product_id = p.id
                LEFT JOIN categories c ON p.category_id = c.id
                JOIN sales s ON si.sale_id = s.id
                WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
                GROUP BY c.id, c.name
                ORDER BY total DESC
            ", [$dateFrom, $dateTo])->fetchAll();
            
            $topCustomers = $this->query("
                SELECT COALESCE(cu.name, 'General') as customer_name, COUNT(s.id) as purchase_count, COALESCE(SUM(s.total), 0) as total
                FROM sales s
                LEFT JOIN customers cu ON s.customer_id = cu.id
                WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ? AND s.customer_id IS NOT NULL
                GROUP BY cu.id, cu.name
                ORDER BY purchase_count DESC LIMIT 10
            ", [$dateFrom, $dateTo])->fetchAll();
            
            $stockMovements = $this->query("
                SELECT sm.*, p.name as product_name
                FROM stock_movements sm
                JOIN products p ON sm.product_id = p.id
                ORDER BY sm.created_at DESC LIMIT 20
            ")->fetchAll();
            
            $productTrend = $this->query("
                SELECT p.name,
                       COALESCE(SUM(CASE WHEN MONTH(s.sale_date) = MONTH(CURDATE()) AND YEAR(s.sale_date) = YEAR(CURDATE()) THEN si.quantity ELSE 0 END), 0) as current_month,
                       COALESCE(SUM(CASE WHEN MONTH(s.sale_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(s.sale_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) THEN si.quantity ELSE 0 END), 0) as last_month
                FROM sale_items si
                JOIN products p ON si.product_id = p.id
                JOIN sales s ON si.sale_id = s.id
                WHERE s.status = 'completed' AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH)
                GROUP BY p.id, p.name
                HAVING current_month > 0 OR last_month > 0
                ORDER BY current_month DESC LIMIT 10
            ")->fetchAll();
            
            $paymentMethods = $this->query("
                SELECT payment_method, COUNT(*) as count, COALESCE(SUM(total), 0) as total
                FROM sales WHERE status = 'completed' AND DATE(sale_date) BETWEEN ? AND ?
                GROUP BY payment_method ORDER BY count DESC
            ", [$dateFrom, $dateTo])->fetchAll();
            
            $salesTrend = $prevTotalSales > 0 ? round((($totalSales - $prevTotalSales) / $prevTotalSales) * 100, 1) : 0;
            $countTrend = $prevSalesCount > 0 ? round((($totalSalesCount - $prevSalesCount) / $prevSalesCount) * 100, 1) : 0;
            $prevAvgTicket = $prevSalesCount > 0 ? $prevTotalSales / $prevSalesCount : 0;
            $ticketTrend = $prevAvgTicket > 0 ? round((($avgTicket - $prevAvgTicket) / $prevAvgTicket) * 100, 1) : 0;
        }
        
        $this->view('tenant.reportes', $this->tenantViewData([
            'plan' => $plan,
            'reportsFull' => $reportsFull,
            'profitSummary' => $profitSummary,
            'receivablesAging' => $receivablesAging,
            'topDebtors' => $topDebtors,
            'cashSummary' => $cashSummary,
            'topSellers' => $topSellers,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'prevFrom' => $prevFrom,
            'prevTo' => $prevTo,
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
        ]));
    }
    
    /** Asegura la columna unit_cost en tenants sin migrar (COGS histórico). */
    private function ensureSaleItemCostColumn(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $col = $this->query("SHOW COLUMNS FROM sale_items LIKE 'unit_cost'")->fetch();
            if (!$col) {
                $this->query("ALTER TABLE sale_items ADD COLUMN unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unit_price");
            }
        } catch (\Throwable $e) {
            // silencioso
        }
    }

    private function exportReport(): void
    {
        $dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $_GET['to'] ?? date('Y-m-d');
        
        $rows = $this->query(
            "SELECT s.invoice_number, COALESCE(c.name, 'General') as customer_name, s.sale_date,
                    s.subtotal, s.tax, s.total, s.payment_method, s.payment_status
             FROM sales s
             LEFT JOIN customers c ON s.customer_id = c.id
             WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
             ORDER BY s.sale_date DESC",
            [$dateFrom, $dateTo]
        )->fetchAll();
        
        $csvRows = [];
        foreach ($rows as $r) {
            $csvRows[] = [
                $r['invoice_number'], $r['customer_name'], $r['sale_date'],
                $r['subtotal'], $r['tax'], $r['total'], $r['payment_method'], $r['payment_status'],
            ];
        }
        
        $this->exportCsv(
            'reportes_' . date('Ymd_His') . '.csv',
            ['Factura', 'Cliente', 'Fecha', 'Subtotal', 'Impuesto', 'Total', 'Metodo', 'Pago'],
            $csvRows
        );
    }
}
