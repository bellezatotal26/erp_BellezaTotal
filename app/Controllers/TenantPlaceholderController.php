<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;
use SoftNova\Core\TenantMiddleware;

/**
 * Controlador genérico para módulos de tenant aún no implementados
 * Usa el layout tenant y requiere autenticación
 */
class TenantPlaceholderController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        TenantMiddleware::auth();
    }
    
    /**
     * Muestra una vista placeholder con el layout del tenant
     * El nombre del módulo se extrae de la URL: /app/{modulo}
     */
    public function show(): void
    {
        $uri = $this->request->uri();
        $module = basename($uri); // Extrae 'ventas' de '/app/ventas'
        
        $viewFile = APP_PATH . '/Views/tenant/' . $module . '.php';
        
        // Si existe una vista específica del tenant, usarla
        if (file_exists($viewFile)) {
            $this->view('tenant.' . $module, [
                'tenantName' => $_SESSION['tenant_name'] ?? 'Mi Empresa',
                'userName' => $_SESSION['tenant_user_name'] ?? 'Usuario',
            ]);
            return;
        }
        
        // Vista genérica de "Módulo en desarrollo"
        $this->view('tenant.placeholder', [
            'moduleName' => $this->getModuleName($module),
            'tenantName' => $_SESSION['tenant_name'] ?? 'Mi Empresa',
            'userName' => $_SESSION['tenant_user_name'] ?? 'Usuario',
        ]);
    }
    
    private function getModuleName(string $module): string
    {
        $names = [
            'ventas' => 'Ventas',
            'inventario' => 'Inventario',
            'clientes' => 'Clientes',
            'proveedores' => 'Proveedores',
            'gastos' => 'Gastos',
            'reportes' => 'Reportes',
            'cotizaciones' => 'Cotizaciones',
            'contabilidad' => 'Contabilidad',
            'nomina' => 'Nómina',
        ];
        return $names[$module] ?? ucfirst($module);
    }
}
