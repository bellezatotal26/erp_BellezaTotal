<?php

namespace SoftNova\Core;

/**
 * Middleware para verificar autenticación de tenant
 * y configurar conexión a su base de datos
 */
class TenantMiddleware
{
    /** Acciones finas disponibles por módulo */
    public const ACTIONS = ['view', 'create', 'edit', 'delete', 'export'];

    /** Módulos gestionables en la matriz de permisos (User) */
    public const MANAGED_MODULES = [
        'dashboard', 'caja', 'ventas', 'inventario', 'clientes', 'proveedores',
        'compras', 'cotizaciones', 'gastos', 'contabilidad', 'nomina', 'reportes',
    ];

    /**
     * Verificar que el usuario esté autenticado como tenant
     */
    public static function auth(): bool
    {
        if (empty($_SESSION['tenant_authenticated'])) {
            redirect('/app/login');
            return false;
        }
        return true;
    }
    
    /**
     * Obtener conexión PDO a la base de datos del tenant actual
     */
    public static function getDb(): \PDO
    {
        if (\SoftNova\Core\is_standalone()) {
            return Database::getInstance()->getConnection();
        }

        $tenantDb = TenantDatabase::getInstance();
        
        return $tenantDb->getTenantConnection(
            (string)($_SESSION['tenant_db_name'] ?? ''),
            (string)($_SESSION['tenant_db_user'] ?? ''),
            (string)($_SESSION['tenant_db_pass'] ?? '')
        );
    }
    
    /**
     * Verificar que el tenant tenga acceso a un módulo específico
     */
    public static function hasModule(string $module): bool
    {
        return self::canAccess($module);
    }

    /**
     * Etiquetas de roles gestionables por el admin local
     */
    public static function teamRoleLabels(): array
    {
        return [
            'user' => 'User',
            'pos' => 'User POS',
        ];
    }

    /**
     * Permisos fijos del rol User POS
     */
    public static function posDefaultPermissions(): array
    {
        return [
            'caja' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false, 'export' => false],
            'ventas' => ['view' => true, 'create' => true, 'edit' => false, 'delete' => false, 'export' => false],
            'clientes' => ['view' => true, 'create' => true, 'edit' => false, 'delete' => false, 'export' => false],
            'gastos' => ['view' => true, 'create' => true, 'edit' => false, 'delete' => false, 'export' => false],
            'compras' => ['view' => true, 'create' => true, 'edit' => false, 'delete' => false, 'export' => false],
        ];
    }

    /**
     * Normaliza JSON de permisos a mapa módulo => acciones bool
     */
    public static function normalizePermissions($raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $module => $actions) {
            $module = strtolower(trim((string)$module));
            if ($module === '' || !in_array($module, self::MANAGED_MODULES, true)) {
                continue;
            }
            if (!is_array($actions)) {
                continue;
            }
            $row = [];
            foreach (self::ACTIONS as $action) {
                $row[$action] = !empty($actions[$action]);
            }
            // Si tiene cualquier acción, view queda implícito
            if ($row['create'] || $row['edit'] || $row['delete'] || $row['export']) {
                $row['view'] = true;
            }
            if ($row['view'] || $row['create'] || $row['edit'] || $row['delete'] || $row['export']) {
                $out[$module] = $row;
            }
        }
        return $out;
    }

    /**
     * Guarda en sesión el mapa de permisos efectivo
     */
    public static function hydrateSessionPermissions(?string $role, $permissionsRaw = null): void
    {
        $role = self::normalizeRole((string)$role);
        $_SESSION['tenant_user_role'] = $role;

        if ($role === 'admin') {
            $_SESSION['tenant_permissions'] = [];
            $_SESSION['tenant_permissions_map'] = null; // null = full
            return;
        }

        if ($role === 'pos') {
            $map = self::posDefaultPermissions();
            $_SESSION['tenant_permissions_map'] = $map;
            $_SESSION['tenant_permissions'] = array_keys($map);
            return;
        }

        if ($role === 'user') {
            $map = self::normalizePermissions($permissionsRaw);
            $_SESSION['tenant_permissions_map'] = $map;
            $_SESSION['tenant_permissions'] = array_keys(array_filter($map, static fn($a) => !empty($a['view'])));
            return;
        }

        // Roles legacy: mapa hardcodeado
        $legacy = self::rolePermissions()[$role] ?? null;
        if ($legacy) {
            $map = [];
            foreach ($legacy['modules'] as $mod) {
                $actions = $legacy['module_actions'][$mod] ?? $legacy['actions'];
                $row = [];
                foreach (self::ACTIONS as $a) {
                    $row[$a] = in_array($a, $actions, true);
                }
                $map[$mod] = $row;
            }
            $_SESSION['tenant_permissions_map'] = $map;
            $_SESSION['tenant_permissions'] = array_keys($map);
            return;
        }

        $_SESSION['tenant_permissions_map'] = [];
        $_SESSION['tenant_permissions'] = [];
    }

    /**
     * Mapa de permisos por rol legacy (fallback)
     */
    private static function rolePermissions(): array
    {
        $admin = [
            'modules' => ['dashboard', 'caja', 'ventas', 'inventario', 'clientes', 'proveedores', 'compras', 'cotizaciones', 'gastos', 'contabilidad', 'nomina', 'reportes', 'configuracion'],
            'actions' => ['create', 'edit', 'delete', 'view', 'export'],
        ];
        $manager = [
            'modules' => ['dashboard', 'caja', 'ventas', 'inventario', 'clientes', 'proveedores', 'compras', 'cotizaciones', 'gastos', 'contabilidad', 'nomina', 'reportes'],
            'actions' => ['create', 'edit', 'delete', 'view', 'export'],
        ];
        
        return [
            'admin' => $admin,
            'manager' => $manager,
            'cashier' => [
                'modules' => ['dashboard', 'caja', 'ventas', 'clientes', 'gastos'],
                'actions' => ['create', 'view'],
            ],
            'viewer' => [
                'modules' => ['dashboard', 'reportes'],
                'actions' => ['view'],
            ],
            'auxiliar' => [
                'modules' => ['ventas', 'inventario'],
                'actions' => ['view'],
                'module_actions' => [
                    'ventas' => ['view', 'create'],
                    'inventario' => ['view'],
                ],
            ],
            'mesero' => [
                'modules' => ['ventas', 'inventario'],
                'actions' => ['view'],
                'module_actions' => [
                    'ventas' => ['view', 'create'],
                    'inventario' => ['view'],
                ],
            ],
        ];
    }

    public static function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        if ($role === '' || $role === 'administrator') {
            return 'admin';
        }
        if (in_array($role, ['waiter', 'auxiliar_mesero', 'auxiliar/mesero'], true)) {
            return 'auxiliar';
        }
        // Alias User POS
        if (in_array($role, ['user_pos', 'user-pos', 'pos_user', 'cashier_pos'], true)) {
            return 'pos';
        }
        return $role;
    }
    
    /**
     * Obtener el rol del usuario actual
     */
    public static function getRole(): string
    {
        return self::normalizeRole((string)($_SESSION['tenant_user_role'] ?? 'viewer'));
    }

    /**
     * ¿Es User POS?
     */
    public static function isPosRole(): bool
    {
        return self::getRole() === 'pos';
    }
    
    /**
     * Home segun rol (evitar redirigir a modulos sin acceso)
     */
    public static function homePath(): string
    {
        $role = self::getRole();
        if ($role === 'pos') {
            return '/app/caja';
        }
        if (in_array($role, ['auxiliar', 'mesero'], true)) {
            return '/app/ventas';
        }
        if ($role === 'user') {
            $map = $_SESSION['tenant_permissions_map'] ?? [];
            if (!empty($map['dashboard']['view'])) {
                return '/app/dashboard';
            }
            if (!empty($map['caja']['view'])) {
                return '/app/caja';
            }
            if (!empty($map['ventas']['view'])) {
                return '/app/ventas';
            }
            foreach (array_keys($map) as $mod) {
                if (!empty($map[$mod]['view'])) {
                    return '/app/' . $mod;
                }
            }
        }
        return '/app/dashboard';
    }
    
    /**
     * Verificar si el usuario puede acceder a un módulo
     */
    public static function canAccess(string $module): bool
    {
        self::ensureHydrated();
        $role = self::getRole();
        if ($role === 'admin') {
            return true;
        }

        // Configuración solo admin
        if ($module === 'configuracion') {
            return false;
        }

        $map = $_SESSION['tenant_permissions_map'] ?? null;
        if (is_array($map)) {
            return !empty($map[$module]['view']);
        }

        // Fallback legacy (sesión sin hidratar)
        $perms = self::rolePermissions();
        if (!isset($perms[$role])) {
            return false;
        }
        return in_array($module, $perms[$role]['modules'], true);
    }
    
    /**
     * Verificar si el usuario puede realizar una acción
     * Si se indica $module, usa module_actions / JSON del usuario
     */
    public static function canDo(string $action, ?string $module = null): bool
    {
        self::ensureHydrated();
        $role = self::getRole();
        if ($role === 'admin') {
            return true;
        }

        $action = strtolower(trim($action));
        if (!in_array($action, self::ACTIONS, true)) {
            return false;
        }

        $map = $_SESSION['tenant_permissions_map'] ?? null;
        if (is_array($map)) {
            if ($module === null) {
                foreach ($map as $row) {
                    if (!empty($row[$action])) {
                        return true;
                    }
                }
                return false;
            }
            return !empty($map[$module][$action]);
        }

        $perms = self::rolePermissions();
        if (!isset($perms[$role])) {
            return false;
        }
        if ($module !== null && !empty($perms[$role]['module_actions'][$module])) {
            return in_array($action, $perms[$role]['module_actions'][$module], true);
        }
        return in_array($action, $perms[$role]['actions'], true);
    }

    /**
     * Rehidrata permisos si la sesión quedó a medias (deploy en caliente)
     */
    private static function ensureHydrated(): void
    {
        if (array_key_exists('tenant_permissions_map', $_SESSION)) {
            return;
        }
        if (empty($_SESSION['tenant_authenticated'])) {
            return;
        }
        self::hydrateSessionPermissions(
            (string)($_SESSION['tenant_user_role'] ?? 'viewer'),
            null
        );
    }
    
    /**
     * Verificar acceso a módulo y acción, redirigir si no tiene permiso
     */
    public static function authorize(string $module, string $action = 'view'): bool
    {
        if (!self::canAccess($module)) {
            self::deny('No tienes acceso a este modulo', self::homePath());
            return false;
        }
        
        if (!self::canDo($action, $module)) {
            self::deny('No tienes permisos para realizar esta accion', '/app/' . ltrim($module, '/'));
            return false;
        }
        
        return true;
    }
    
    private static function deny(string $message, string $redirect): void
    {
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        $wantsJson = $isAjax
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string)$_SERVER['HTTP_ACCEPT'], 'application/json'));
        
        if ($wantsJson) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => $message,
                'redirect' => '',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $_SESSION['error'] = $message;
        redirect($redirect);
    }
    
    /**
     * Contexto del plan del tenant (tier + features)
     * Prioridad: columna features JSON > nombre del plan > precio
     */
    public static function getPlanContext(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        if (\SoftNova\Core\is_standalone()) {
            return $cache = [
                'tier' => 'premium',
                'reports' => 'full',
                'export' => true,
                'plan_name' => 'Licencia local',
                'upgrade_plans' => '',
            ];
        }
        
        $default = [
            'tier' => 'basic',
            'reports' => 'basic',
            'export' => false,
            'plan_name' => 'Plan Basico',
            'upgrade_plans' => 'Pro o Premium',
        ];
        
        $tenantId = (int)($_SESSION['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            return $cache = $default;
        }
        
        try {
            $db = Database::getInstance();
            $row = null;
            try {
                $row = $db->query(
                    "SELECT sp.name, sp.monthly_price, sp.features, sp.modules
                     FROM tenants t
                     JOIN subscription_plans sp ON t.subscription_plan_id = sp.id
                     WHERE t.id = ? LIMIT 1",
                    [$tenantId]
                )->fetch();
            } catch (\Throwable $e) {
                $row = $db->query(
                    "SELECT sp.name, sp.monthly_price, sp.modules
                     FROM tenants t
                     JOIN subscription_plans sp ON t.subscription_plan_id = sp.id
                     WHERE t.id = ? LIMIT 1",
                    [$tenantId]
                )->fetch();
            }
            
            if (!$row) {
                return $cache = $default;
            }
            
            $planName = (string)($row['name'] ?? 'Plan');
            $features = [];
            if (!empty($row['features'])) {
                $decoded = is_array($row['features'])
                    ? $row['features']
                    : json_decode((string)$row['features'], true);
                if (is_array($decoded)) {
                    $features = $decoded;
                }
            }
            
            if (!empty($features['reports']) || !empty($features['tier'])) {
                $tier = strtolower((string)($features['tier'] ?? 'basic'));
                $reports = strtolower((string)($features['reports'] ?? 'basic'));
                $export = array_key_exists('export', $features)
                    ? (bool)$features['export']
                    : ($reports === 'full');
                
                return $cache = [
                    'tier' => in_array($tier, ['basic', 'pro', 'premium', 'custom'], true) ? $tier : 'basic',
                    'reports' => $reports === 'full' ? 'full' : 'basic',
                    'export' => $export,
                    'plan_name' => $planName,
                    'upgrade_plans' => 'Pro o Premium',
                ];
            }
            
            $name = mb_strtolower($planName);
            $isBasic = (bool)preg_match('/b[aá]sic|basic|starter|inicio|essentials/', $name);
            $isFull = (bool)preg_match('/pro|premium|enterprise|empresarial|personal|custom|unlimited/', $name);
            
            if (!$isBasic && !$isFull) {
                $isFull = ((float)($row['monthly_price'] ?? 0)) >= 50;
            }
            
            return $cache = [
                'tier' => $isFull ? (preg_match('/premium|enterprise/', $name) ? 'premium' : (preg_match('/personal|custom/', $name) ? 'custom' : 'pro')) : 'basic',
                'reports' => $isFull ? 'full' : 'basic',
                'export' => $isFull,
                'plan_name' => $planName,
                'upgrade_plans' => 'Pro o Premium',
            ];
        } catch (\Throwable $e) {
            error_log('getPlanContext: ' . $e->getMessage());
            return $cache = $default;
        }
    }
    
    public static function hasFullReports(): bool
    {
        return self::getPlanContext()['reports'] === 'full';
    }
    
    public static function canExportReports(): bool
    {
        return !empty(self::getPlanContext()['export']);
    }
}
