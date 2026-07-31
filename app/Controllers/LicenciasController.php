<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;
use SoftNova\Core\Database;
use SoftNova\Core\Middleware;
use SoftNova\Services\AuditService;

/**
 * Controlador del módulo Control de Licencias
 * Registra ventas y suscripciones de clientes
 */
class LicenciasController extends Controller
{
    private Database $db;
    
    public function __construct()
    {
        parent::__construct();
        Middleware::auth();
        $this->db = Database::getInstance();
    }
    
    /**
     * Genera un código único de venta
     */
    private function generateSaleCode(): string
    {
        $prefix = 'LIC-' . date('Y') . '-';
        $random = strtoupper(bin2hex(random_bytes(3)));
        return $prefix . $random;
    }
    
    /**
     * Calcula la fecha de fin según el ciclo de facturación
     */
    private function calculateEndDate(string $startDate, string $billingCycle): string
    {
        $start = new \DateTime($startDate);
        
        switch ($billingCycle) {
            case 'monthly':
                $start->modify('+1 month');
                break;
            case 'semiannual':
                $start->modify('+6 months');
                break;
            case 'annual':
                $start->modify('+1 year');
                break;
            default:
                $start->modify('+1 month');
        }
        
        return $start->format('Y-m-d');
    }
    
    /**
     * Calcula el monto según el plan y ciclo de facturación
     */
    private function calculateAmount(int $planId, string $billingCycle): float
    {
        $plan = $this->db->query("SELECT monthly_price, annual_price FROM subscription_plans WHERE id = ?", [$planId])->fetch();
        
        if (!$plan) {
            return 0;
        }
        
        return match ($billingCycle) {
            'annual' => (float) $plan['annual_price'],
            'semiannual' => (float) $plan['monthly_price'] * 6,
            default => (float) $plan['monthly_price'],
        };
    }
    
    /**
     * Lista todas las ventas de licencias
     */
    public function index(): void
    {
        $action = $this->request->post('action') ?? $this->request->get('action');
        
        if ($this->request->method() === 'POST') {
            if ($action === 'create') {
                $this->createSale();
                return;
            }
            
            if ($action === 'edit') {
                $this->editSale();
                return;
            }
            
            if ($action === 'delete') {
                $this->deleteSale();
                return;
            }
            
            if ($action === 'payment') {
                $this->registerPayment();
                return;
            }
        }
        
        $sales = $this->db->query("
            SELECT ls.*,
                   t.company_name,
                   t.email as tenant_email,
                   t.status as tenant_status,
                   sp.name as plan_name,
                   sp.monthly_price,
                   sp.annual_price,
                   COALESCE(SUM(lp.amount), 0) as paid_amount
            FROM license_sales ls
            LEFT JOIN tenants t ON ls.tenant_id = t.id
            LEFT JOIN subscription_plans sp ON ls.plan_id = sp.id
            LEFT JOIN license_payments lp ON ls.id = lp.license_sale_id
            GROUP BY ls.id
            ORDER BY ls.created_at DESC
        ")->fetchAll();
        
        $tenants = $this->db->query("
            SELECT id, company_name, email, status
            FROM tenants
            ORDER BY company_name ASC
        ")->fetchAll();
        
        $plans = $this->db->query("SELECT * FROM subscription_plans WHERE status = 'active'")->fetchAll();
        
        $stats = [
            'total_sales' => $this->db->query("SELECT COUNT(*) as count FROM license_sales WHERE status = 'active'")->fetch()['count'],
            'total_revenue' => $this->db->query("SELECT COALESCE(SUM(amount), 0) as total FROM license_sales WHERE status = 'active'")->fetch()['total'],
            'pending_payments' => $this->db->query("SELECT COUNT(*) as count FROM license_sales WHERE payment_status = 'pending'")->fetch()['count'],
            'active_subscriptions' => $this->db->query("SELECT COUNT(*) as count FROM license_sales WHERE status = 'active' AND end_date >= CURDATE()")->fetch()['count'],
        ];
        
        $this->view('superadmin.licencias', [
            'sales' => $sales,
            'tenants' => $tenants,
            'plans' => $plans,
            'stats' => $stats
        ]);
    }
    
    /**
     * Crea una nueva venta de licencia
     */
    private function createSale(): void
    {
        if (!$this->validateCsrfOrFail('/superadmin/licencias')) {
            return;
        }
        
        $tenantIdRaw = (string)($this->request->post('tenant_id') ?? '');
        $tenantId = ($tenantIdRaw !== '' && $tenantIdRaw !== '__new__') ? (int)$tenantIdRaw : null;
        
        $adminCredMsg = '';
        // Crear nuevo cliente + usuario admin
        if ($tenantIdRaw === '__new__' || ($tenantId === null && $this->request->post('new_company_name'))) {
            $created = $this->createTenantFromSaleForm();
            if ($created['success'] === false) {
                $this->respond(false, $created['message'], '/superadmin/licencias');
                return;
            }
            $tenantId = (int)$created['tenant_id'];
            $adminCredMsg = $created['admin_message'] ?? '';
        }
        
        if (!$tenantId) {
            $this->respond(false, 'Debe seleccionar un cliente o crear uno nuevo', '/superadmin/licencias');
            return;
        }
        
        $planId = (int) $this->request->post('plan_id');
        $saleDate = $this->request->post('sale_date') ?: date('Y-m-d');
        $startDate = $this->request->post('start_date') ?: date('Y-m-d');
        $billingCycle = $this->request->post('billing_cycle') ?: 'monthly';
        $paymentStatus = $this->request->post('payment_status') ?: 'paid';
        $paymentMethod = $this->request->post('payment_method') ?: 'transfer';
        $notes = $this->request->post('notes');
        
        if ($planId <= 0) {
            $this->respond(false, 'Debe seleccionar un plan', '/superadmin/licencias');
            return;
        }
        
        $endDate = $this->calculateEndDate($startDate, $billingCycle);
        $amount = $this->calculateAmount($planId, $billingCycle);
        $saleCode = $this->generateSaleCode();
        $referenceNumber = $saleCode; // Auto-generado = mismo código de licencia
        $createdBy = $_SESSION['super_admin_id'] ?? null;
        
        try {
            // Vincular plan/fechas al tenant si se creó o ya existía
            $this->db->query(
                "UPDATE tenants SET subscription_plan_id = ?, subscription_start_date = ?, subscription_end_date = ?, status = 'active', updated_at = NOW() WHERE id = ?",
                [$planId, $startDate, $endDate, $tenantId]
            );
            
            $this->db->query("
                INSERT INTO license_sales
                (tenant_id, plan_id, sale_code, sale_date, start_date, end_date, billing_cycle, amount, payment_status, payment_method, reference_number, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $tenantId, $planId, $saleCode, $saleDate, $startDate, $endDate, $billingCycle, $amount,
                $paymentStatus, $paymentMethod, $referenceNumber, $notes, $createdBy
            ]);
            
            $saleId = $this->db->lastInsertId();
            
            // Si el pago es completo, registrar el pago automáticamente
            if ($paymentStatus === 'paid') {
                $this->db->query("
                    INSERT INTO license_payments (license_sale_id, payment_date, amount, payment_method, reference_number, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ", [$saleId, $saleDate, $amount, $paymentMethod, $referenceNumber, $notes, $createdBy]);
            }
            
            AuditService::log(
                'create',
                'license_sales',
                "Venta de licencia creada: {$saleCode} por \${$amount}",
                $tenantId
            );
            
            $msg = 'Venta de licencia creada exitosamente';
            if ($adminCredMsg !== '') {
                $msg .= ' ' . $adminCredMsg;
            }
            $this->respond(true, $msg, '/superadmin/licencias');
        } catch (\Exception $e) {
            $this->respond(false, 'Error al crear la venta: ' . $e->getMessage(), '/superadmin/licencias');
        }
    }
    
    /**
     * Crea tenant + BD + usuario admin desde el formulario de nueva venta
     */
    private function createTenantFromSaleForm(): array
    {
        $name = trim((string)$this->request->post('new_company_name'));
        $email = trim((string)$this->request->post('new_email'));
        $phone = trim((string)$this->request->post('new_phone'));
        $docTipo = trim((string)$this->request->post('new_documento_tipo', 'NIT'));
        $docNum = trim((string)$this->request->post('new_documento_numero'));
        $razon = trim((string)$this->request->post('new_razon_social'));
        $address = trim((string)$this->request->post('new_address'));
        $adminName = trim((string)$this->request->post('new_admin_name'));
        $adminPassword = trim((string)$this->request->post('new_admin_password'));
        $planId = (int)$this->request->post('plan_id');
        $billingCycle = $this->request->post('billing_cycle') ?: 'monthly';
        $startDate = $this->request->post('start_date') ?: date('Y-m-d');
        
        if ($name === '' || $email === '' || $phone === '' || $docNum === '' || $docTipo === '') {
            return ['success' => false, 'message' => 'Nuevo cliente: complete empresa, email, teléfono y documento'];
        }
        if ($planId <= 0) {
            return ['success' => false, 'message' => 'Debe seleccionar un plan para el nuevo cliente'];
        }
        
        $existing = $this->db->query("SELECT id FROM tenants WHERE email = ? LIMIT 1", [$email])->fetch();
        if ($existing) {
            return ['success' => false, 'message' => 'Ya existe un cliente con ese email'];
        }
        
        $endDate = $this->calculateEndDate($startDate, $billingCycle);
        $companySlug = strtolower(preg_replace('/[^a-z0-9]+/', '_', $name));
        $databaseName = 'softnova_' . $companySlug . '_' . time();
        $databaseUser = 'tn_' . bin2hex(random_bytes(6));
        $databasePassword = bin2hex(random_bytes(16));
        
        try {
            $this->db->query(
                "INSERT INTO tenants
                    (company_name, razon_social, documento_tipo, documento_numero, email, phone, address,
                     database_name, database_host, database_port, database_user, database_password,
                     subscription_plan_id, subscription_start_date, subscription_end_date, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'localhost', 3306, ?, ?, ?, ?, ?, 'active', NOW())",
                [
                    $name, $razon ?: null, $docTipo, $docNum, $email, $phone, $address ?: null,
                    $databaseName, $databaseUser, $databasePassword,
                    $planId, $startDate, $endDate,
                ]
            );
            $tenantId = (int)$this->db->lastInsertId();
            
            $tenantDb = \SoftNova\Core\TenantDatabase::getInstance();
            if (!$tenantDb->createTenantDatabase($databaseName, $databaseUser, $databasePassword)) {
                $this->db->query("DELETE FROM tenants WHERE id = ?", [$tenantId]);
                return ['success' => false, 'message' => 'Error al crear la base de datos del cliente'];
            }
            
            $generated = false;
            if ($adminPassword === '') {
                $adminPassword = substr(bin2hex(random_bytes(6)), 0, 10);
                $generated = true;
            }
            if (strlen($adminPassword) < 8) {
                $this->db->query("DELETE FROM tenants WHERE id = ?", [$tenantId]);
                return ['success' => false, 'message' => 'La contraseña del admin debe tener al menos 8 caracteres'];
            }
            if ($adminName === '') {
                $adminName = 'Admin ' . $name;
            }
            
            $hashed = password_hash($adminPassword, PASSWORD_ARGON2ID);
            $this->db->query(
                "INSERT INTO tenant_users (tenant_id, name, email, password, role, permissions, status, created_at)
                 VALUES (?, ?, ?, ?, 'admin', '{}', 'active', NOW())",
                [$tenantId, $adminName, $email, $hashed]
            );
            
            $adminMsg = 'Usuario admin creado (' . $email . ').';
            if ($generated) {
                $adminMsg .= ' Contraseña temporal: ' . $adminPassword;
            }
            
            return [
                'success' => true,
                'tenant_id' => $tenantId,
                'admin_message' => $adminMsg,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error al crear cliente: ' . $e->getMessage()];
        }
    }
    
    /**
     * Edita una venta de licencia existente
     */
    private function editSale(): void
    {
        if (!$this->validateCsrfOrFail('/superadmin/licencias')) {
            return;
        }
        
        $id = (int) $this->request->post('id');
        
        $sale = $this->db->query("SELECT * FROM license_sales WHERE id = ?", [$id])->fetch();
        
        if (!$sale) {
            $this->respond(false, 'Venta no encontrada', '/superadmin/licencias');
            return;
        }
        
        $tenantId = $this->request->post('tenant_id') ? (int) $this->request->post('tenant_id') : null;
        $planId = (int) $this->request->post('plan_id');
        $saleDate = $this->request->post('sale_date');
        $startDate = $this->request->post('start_date');
        $billingCycle = $this->request->post('billing_cycle');
        $paymentStatus = $this->request->post('payment_status');
        $paymentMethod = $this->request->post('payment_method');
        $notes = $this->request->post('notes');
        $status = $this->request->post('status');
        
        $endDate = $this->calculateEndDate($startDate, $billingCycle);
        $amount = $this->calculateAmount($planId, $billingCycle);
        
        try {
            $this->db->query("
                UPDATE license_sales
                SET tenant_id = ?, plan_id = ?, sale_date = ?, start_date = ?, end_date = ?,
                    billing_cycle = ?, amount = ?, payment_status = ?, payment_method = ?,
                    notes = ?, status = ?
                WHERE id = ?
            ", [
                $tenantId, $planId, $saleDate, $startDate, $endDate, $billingCycle, $amount,
                $paymentStatus, $paymentMethod, $notes, $status, $id
            ]);
            
            AuditService::log(
                'update',
                'license_sales',
                "Venta de licencia actualizada ID: {$id}",
                $tenantId
            );
            
            $this->respond(true, 'Venta de licencia actualizada exitosamente', '/superadmin/licencias');
        } catch (\Exception $e) {
            $this->respond(false, 'Error al actualizar la venta: ' . $e->getMessage(), '/superadmin/licencias');
        }
    }
    
    /**
     * Elimina una venta de licencia
     */
    private function deleteSale(): void
    {
        if (!$this->validateCsrfOrFail('/superadmin/licencias')) {
            return;
        }
        
        $id = (int) $this->request->post('id');
        
        $sale = $this->db->query("SELECT * FROM license_sales WHERE id = ?", [$id])->fetch();
        
        if (!$sale) {
            $this->respond(false, 'Venta no encontrada', '/superadmin/licencias');
            return;
        }
        
        $this->db->query("DELETE FROM license_sales WHERE id = ?", [$id]);
        
        AuditService::log(
            'delete',
            'license_sales',
            "Venta de licencia eliminada ID: {$id}",
            $sale['tenant_id']
        );
        
        $this->respond(true, 'Venta de licencia eliminada exitosamente', '/superadmin/licencias');
    }
    
    /**
     * Registra un pago para una venta de licencia
     */
    private function registerPayment(): void
    {
        if (!$this->validateCsrfOrFail('/superadmin/licencias')) {
            return;
        }
        
        $saleId = (int) $this->request->post('sale_id');
        $amount = (float) $this->request->post('amount');
        $paymentDate = $this->request->post('payment_date') ?: date('Y-m-d');
        $paymentMethod = $this->request->post('payment_method') ?: 'other';
        $referenceNumber = $this->request->post('reference_number');
        $notes = $this->request->post('notes');
        $createdBy = $_SESSION['super_admin_id'] ?? null;
        
        $sale = $this->db->query("SELECT * FROM license_sales WHERE id = ?", [$saleId])->fetch();
        
        if (!$sale) {
            $this->respond(false, 'Venta no encontrada', '/superadmin/licencias');
            return;
        }
        
        if ($amount <= 0) {
            $this->respond(false, 'El monto del pago debe ser mayor a cero', '/superadmin/licencias');
            return;
        }
        
        try {
            $this->db->query("
                INSERT INTO license_payments (license_sale_id, payment_date, amount, payment_method, reference_number, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ", [$saleId, $paymentDate, $amount, $paymentMethod, $referenceNumber, $notes, $createdBy]);
            
            // Actualizar estado de pago
            $totalPaid = $this->db->query("
                SELECT COALESCE(SUM(amount), 0) as total
                FROM license_payments
                WHERE license_sale_id = ?
            ", [$saleId])->fetch()['total'];
            
            $newStatus = 'pending';
            if ($totalPaid >= $sale['amount']) {
                $newStatus = 'paid';
            } elseif ($totalPaid > 0) {
                $newStatus = 'partial';
            }
            
            $this->db->query("UPDATE license_sales SET payment_status = ? WHERE id = ?", [$newStatus, $saleId]);
            
            AuditService::log(
                'create',
                'license_payments',
                "Pago registrado por \${$amount} para venta ID: {$saleId}",
                $sale['tenant_id']
            );
            
            $this->respond(true, 'Pago registrado exitosamente', '/superadmin/licencias');
        } catch (\Exception $e) {
            $this->respond(false, 'Error al registrar el pago: ' . $e->getMessage(), '/superadmin/licencias');
        }
    }
}
