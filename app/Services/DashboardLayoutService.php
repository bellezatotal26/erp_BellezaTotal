<?php

namespace SoftNova\Services;

/**
 * Layout personalizable del dashboard tenant (widgets + orden + columna + visibilidad)
 */
class DashboardLayoutService
{
    public const SETTING_PREFIX = 'dashboard_layout_u';
    public const COLUMNS = 2;
    
    public static function catalog(): array
    {
        return [
            'kpi_products' => [
                'id' => 'kpi_products',
                'title' => 'Productos',
                'module' => 'inventario',
                'type' => 'kpi',
                'description' => 'Total de productos en inventario',
            ],
            'kpi_customers' => [
                'id' => 'kpi_customers',
                'title' => 'Clientes',
                'module' => 'clientes',
                'type' => 'kpi',
                'description' => 'Clientes registrados',
            ],
            'kpi_today_sales' => [
                'id' => 'kpi_today_sales',
                'title' => 'Ventas Hoy',
                'module' => 'ventas',
                'type' => 'kpi',
                'description' => 'Monto de ventas de hoy',
            ],
            'kpi_low_stock' => [
                'id' => 'kpi_low_stock',
                'title' => 'Stock Bajo',
                'module' => 'inventario',
                'type' => 'kpi',
                'description' => 'Productos bajo el minimo',
            ],
            'kpi_total_sales' => [
                'id' => 'kpi_total_sales',
                'title' => 'Ventas Totales',
                'module' => 'ventas',
                'type' => 'kpi',
                'description' => 'Cantidad total de ventas',
            ],
            'receivables' => [
                'id' => 'receivables',
                'title' => 'Cuentas por cobrar',
                'module' => 'ventas',
                'type' => 'list',
                'description' => 'Saldos pendientes y ultimas ventas saldadas',
            ],
            'recent_sales' => [
                'id' => 'recent_sales',
                'title' => 'Ultimas Ventas',
                'module' => 'ventas',
                'type' => 'list',
                'description' => 'Ventas mas recientes',
            ],
            'low_stock_list' => [
                'id' => 'low_stock_list',
                'title' => 'Productos con stock bajo',
                'module' => 'inventario',
                'type' => 'list',
                'description' => 'Detalle de inventario critico',
            ],
        ];
    }
    
    public static function defaultLayout(): array
    {
        $defaults = [
            ['id' => 'kpi_products', 'visible' => true, 'order' => 0, 'column' => 0],
            ['id' => 'kpi_customers', 'visible' => true, 'order' => 1, 'column' => 0],
            ['id' => 'kpi_today_sales', 'visible' => true, 'order' => 2, 'column' => 1],
            ['id' => 'kpi_low_stock', 'visible' => true, 'order' => 3, 'column' => 1],
            ['id' => 'kpi_total_sales', 'visible' => false, 'order' => 4, 'column' => 0],
            ['id' => 'recent_sales', 'visible' => true, 'order' => 5, 'column' => 0],
            ['id' => 'receivables', 'visible' => true, 'order' => 6, 'column' => 1],
            ['id' => 'low_stock_list', 'visible' => false, 'order' => 7, 'column' => 1],
        ];
        return self::normalizeStatic($defaults);
    }
    
    public function __construct(private \PDO $db)
    {
    }
    
    private function settingKey(int $userId): string
    {
        return self::SETTING_PREFIX . max(0, $userId);
    }
    
    public function getLayout(int $userId): array
    {
        $key = $this->settingKey($userId);
        try {
            $row = $this->db->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
            $row->execute([$key]);
            $raw = $row->fetchColumn();
        } catch (\Throwable $e) {
            return self::defaultLayout();
        }
        
        $layout = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($layout) || empty($layout)) {
            return self::defaultLayout();
        }
        
        return $this->normalize($layout);
    }
    
    public function saveLayout(int $userId, array $layout): bool
    {
        $normalized = $this->normalize($layout);
        $json = json_encode(array_values($normalized), JSON_UNESCAPED_UNICODE);
        $key = $this->settingKey($userId);
        
        try {
            $exists = $this->db->prepare("SELECT id FROM settings WHERE setting_key = ? LIMIT 1");
            $exists->execute([$key]);
            if ($exists->fetch()) {
                $stmt = $this->db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                $stmt->execute([$json, $key]);
            } else {
                $stmt = $this->db->prepare(
                    "INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)"
                );
                $stmt->execute([$key, $json, 'Layout personalizado del dashboard']);
            }
            return true;
        } catch (\Throwable $e) {
            error_log('DashboardLayoutService::saveLayout: ' . $e->getMessage());
            return false;
        }
    }
    
    public function resolveVisible(array $layout, callable $canAccessModule): array
    {
        $catalog = self::catalog();
        $items = [];
        
        foreach ($this->normalize($layout) as $item) {
            $id = $item['id'];
            if (empty($item['visible']) || !isset($catalog[$id])) {
                continue;
            }
            $meta = $catalog[$id];
            $module = $meta['module'] ?? '';
            if ($module !== '' && !$canAccessModule($module)) {
                continue;
            }
            $items[] = array_merge($meta, [
                'order' => (int)$item['order'],
                'column' => (int)$item['column'],
                'visible' => true,
            ]);
        }
        
        usort($items, static function ($a, $b) {
            $c = $a['column'] <=> $b['column'];
            return $c !== 0 ? $c : ($a['order'] <=> $b['order']);
        });
        
        return $items;
    }
    
    public function normalize(array $layout): array
    {
        return self::normalizeStatic($layout);
    }
    
    private static function normalizeStatic(array $layout): array
    {
        $catalog = self::catalog();
        $byId = [];
        
        foreach ($layout as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string)($item['id'] ?? '');
            if ($id === '' || !isset($catalog[$id]) || isset($byId[$id])) {
                continue;
            }
            $visible = $item['visible'] ?? false;
            if ($visible === '1' || $visible === 1 || $visible === true || $visible === 'true') {
                $visible = true;
            } else {
                $visible = false;
            }
            $column = (int)($item['column'] ?? 0);
            if ($column < 0 || $column >= self::COLUMNS) {
                $column = 0;
            }
            $byId[$id] = [
                'id' => $id,
                'visible' => $visible,
                'order' => isset($item['order']) ? (int)$item['order'] : (int)$i,
                'column' => $column,
            ];
        }
        
        $order = count($byId);
        foreach ($catalog as $id => $meta) {
            if (!isset($byId[$id])) {
                $byId[$id] = [
                    'id' => $id,
                    'visible' => false,
                    'order' => $order++,
                    'column' => 0,
                ];
            }
        }
        
        $list = array_values($byId);
        usort($list, static function ($a, $b) {
            $c = $a['column'] <=> $b['column'];
            return $c !== 0 ? $c : ($a['order'] <=> $b['order']);
        });
        
        $perCol = [];
        foreach ($list as &$row) {
            $col = $row['column'];
            if (!isset($perCol[$col])) {
                $perCol[$col] = 0;
            }
            $row['order'] = $perCol[$col]++;
        }
        unset($row);
        
        return $list;
    }
}
