<?php

namespace SoftNova\Core;

/**
 * Enrutador de solicitudes
 */

class Router
{
    private Request $request;
    private array $routes = [];
    
    public function __construct(Request $request)
    {
        $this->request = $request;
    }
    
    /**
     * Registrar ruta GET
     */
    public function get(string $path, string $controller, string $action): void
    {
        $this->routes['GET'][$path] = [
            'controller' => $controller,
            'action' => $action
        ];
    }
    
    /**
     * Registrar ruta POST
     */
    public function post(string $path, string $controller, string $action): void
    {
        $this->routes['POST'][$path] = [
            'controller' => $controller,
            'action' => $action
        ];
    }
    
    /**
     * Despachar la solicitud
     */
    public function dispatch(): void
    {
        $method = $this->request->method();
        $uri = $this->request->uri();
        
        // Cargar rutas definidas
        $this->loadRoutes();
        
        // Buscar ruta coincidente
        if (isset($this->routes[$method][$uri])) {
            $route = $this->routes[$method][$uri];
            $this->callController($route['controller'], $route['action']);
        } else {
            // Intentar rutas dinámicas
            $this->handleDynamicRoute($method, $uri);
        }
    }
    
    /**
     * Manejar rutas dinámicas
     */
    private function handleDynamicRoute(string $method, string $uri): void
    {
        // Ruta para usuarios de tenant: /superadmin/tenants/{id}/users
        if (preg_match('#^/superadmin/tenants/(\d+)/users$#', $uri, $matches)) {
            $tenant_id = (int)$matches[1];
            $this->callControllerWithParams('SuperAdminController', 'tenantUsers', [$tenant_id]);
            return;
        }
        
        // Ruta para ver detalle de ticket: /superadmin/tickets/view/{id}
        if (preg_match('#^/superadmin/tickets/view/(\d+)$#', $uri, $matches)) {
            $ticket_id = (int)$matches[1];
            $this->callControllerWithParams('TicketsController', 'show', [$ticket_id]);
            return;
        }
        
        // Ruta por defecto
        $this->handleDefaultRoute($uri);
    }
    
    /**
     * Invocar controlador con parámetros
     */
    private function callControllerWithParams(string $controllerName, string $action, array $params): void
    {
        $controllerClass = 'SoftNova\\Controllers\\' . $controllerName;
        
        if (!class_exists($controllerClass)) {
            throw new \Exception("Controlador no encontrado: {$controllerClass}");
        }
        
        $controller = new $controllerClass();
        
        if (!method_exists($controller, $action)) {
            throw new \Exception("Acción no encontrada: {$action} en {$controllerClass}");
        }
        
        call_user_func_array([$controller, $action], $params);
    }
    
    /**
     * Cargar rutas desde archivo de configuración
     */
    private function loadRoutes(): void
    {
        $standalone = \SoftNova\Core\is_standalone();

        if ($standalone) {
            // Cliente final: sin SuperAdmin. Entrada directa al ERP.
            $this->get('/', 'TenantAuthController', 'login');
            $this->get('/login', 'TenantAuthController', 'login');
            $this->post('/login', 'TenantAuthController', 'authenticate');
            $this->get('/logout', 'TenantAuthController', 'logout');
            $this->post('/logout', 'TenantAuthController', 'logout');
        } else {
            $this->get('/', 'AuthController', 'login');
            $this->get('/login', 'AuthController', 'login');
            $this->post('/login', 'AuthController', 'authenticate');
            $this->get('/logout', 'AuthController', 'logout');
            $this->post('/logout', 'AuthController', 'logout');

            // Rutas de Super Admin
            $this->get('/superadmin', 'SuperAdminController', 'index');
            $this->get('/superadmin/tenants', 'SuperAdminController', 'tenants');
            $this->post('/superadmin/tenants', 'SuperAdminController', 'tenants');
            $this->get('/superadmin/plans', 'SuperAdminController', 'plans');
            $this->post('/superadmin/plans', 'SuperAdminController', 'plans');
            $this->get('/superadmin/audits', 'SuperAdminController', 'audits');
            $this->get('/superadmin/tenants/users', 'SuperAdminController', 'tenantUsers');
            $this->post('/superadmin/tenants/users', 'SuperAdminController', 'tenantUsers');
            $this->get('/superadmin/settings', 'SuperAdminController', 'settings');
            $this->post('/superadmin/settings', 'SuperAdminController', 'settings');
            $this->get('/superadmin/search', 'SuperAdminController', 'search');
            $this->get('/superadmin/licencias', 'LicenciasController', 'index');
            $this->post('/superadmin/licencias', 'LicenciasController', 'index');
            $this->get('/superadmin/tickets', 'TicketsController', 'index');
            $this->post('/superadmin/tickets', 'TicketsController', 'index');
            $this->get('/superadmin/announcements', 'SuperAdminController', 'announcements');
            $this->post('/superadmin/announcements', 'SuperAdminController', 'announcements');
        }
        
        // Rutas de Tenant (app cliente)
        $this->get('/app/login', 'TenantAuthController', 'login');
        $this->post('/app/login', 'TenantAuthController', 'authenticate');
        $this->get('/app/logout', 'TenantAuthController', 'logout');
        $this->get('/app/dashboard', 'TenantDashboardController', 'index');
        $this->post('/app/dashboard', 'TenantDashboardController', 'index');
        $this->get('/app/notifications', 'TenantNotificationsController', 'index');
        $this->post('/app/notifications', 'TenantNotificationsController', 'index');
        
        // Rutas de módulos del tenant
        $this->get('/app/caja', 'TenantCajaController', 'index');
        $this->post('/app/caja', 'TenantCajaController', 'index');
        $this->get('/app/configuracion', 'TenantConfigController', 'index');
        $this->post('/app/configuracion', 'TenantConfigController', 'index');
        
        // Asistente IA
        $this->get('/app/ia', 'TenantAiController', 'index');
        $this->post('/app/ia/chat', 'TenantAiController', 'chat');
        $this->get('/app/ia/history', 'TenantAiController', 'history');
        
        // Soporte del tenant
        $this->get('/app/soporte', 'TenantTicketsController', 'index');
        $this->post('/app/soporte', 'TenantTicketsController', 'index');
        
        // Módulos del tenant
        $this->get('/app/ventas', 'TenantVentasController', 'index');
        $this->post('/app/ventas', 'TenantVentasController', 'index');
        $this->get('/app/inventario', 'TenantInventarioController', 'index');
        $this->post('/app/inventario', 'TenantInventarioController', 'index');
        $this->get('/app/prestamos', 'TenantPrestamosController', 'index');
        $this->post('/app/prestamos', 'TenantPrestamosController', 'index');
        $this->get('/app/consignacion', 'TenantConsignacionController', 'index');
        $this->post('/app/consignacion', 'TenantConsignacionController', 'index');
        $this->get('/app/clientes', 'TenantClientesController', 'index');
        $this->post('/app/clientes', 'TenantClientesController', 'index');
        $this->get('/app/proveedores', 'TenantProveedoresController', 'index');
        $this->post('/app/proveedores', 'TenantProveedoresController', 'index');
        $this->get('/app/reportes', 'TenantReportesController', 'index');
        $this->get('/app/cotizaciones', 'TenantCotizacionesController', 'index');
        $this->post('/app/cotizaciones', 'TenantCotizacionesController', 'index');
        $this->get('/app/compras', 'TenantComprasController', 'index');
        $this->post('/app/compras', 'TenantComprasController', 'index');
        $this->get('/app/gastos', 'TenantGastosController', 'index');
        $this->post('/app/gastos', 'TenantGastosController', 'index');
        $this->get('/app/contabilidad', 'TenantContabilidadController', 'index');
        $this->post('/app/contabilidad', 'TenantContabilidadController', 'index');
        $this->get('/app/bancos', 'TenantBancosController', 'index');
        $this->post('/app/bancos', 'TenantBancosController', 'index');
        $this->get('/app/nomina', 'TenantNominaController', 'index');
        $this->post('/app/nomina', 'TenantNominaController', 'index');

        // Archivos protegidos (uploads)
        $this->get('/app/media', 'TenantMediaController', 'serve');
        
        // API v1
        $this->get('/api/v1/ping', 'ApiController', 'ping');
    }
    
    /**
     * Manejar ruta por defecto
     */
    private function handleDefaultRoute(string $uri): void
    {
        http_response_code(404);
        echo 'Página no encontrada';
    }
    
    /**
     * Invocar controlador y acción
     */
    private function callController(string $controllerName, string $action): void
    {
        $controllerClass = 'SoftNova\\Controllers\\' . $controllerName;
        
        if (!class_exists($controllerClass)) {
            throw new \Exception("Controlador no encontrado: {$controllerClass}");
        }
        
        $controller = new $controllerClass();
        
        if (!method_exists($controller, $action)) {
            throw new \Exception("Acción no encontrada: {$action} en {$controllerClass}");
        }
        
        call_user_func([$controller, $action]);
    }
}
