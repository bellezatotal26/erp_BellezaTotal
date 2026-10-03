<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Core\Security;
use SoftNova\Services\TenantBackupService;

/**
 * Controlador de Configuración del Tenant
 * Permite al cliente configurar moneda, impuestos, datos de empresa, perfil, idioma
 */
class TenantConfigController extends Controller
{
    private \PDO $db;
    
    public function __construct()
    {
        parent::__construct();
        TenantMiddleware::auth();
        $this->db = TenantMiddleware::getDb();
    }
    
    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    /**
     * Página de configuración
     */
    public function index(): void
    {
        \SoftNova\Core\TenantMiddleware::authorize('configuracion');
        
        $action = $this->request->get('action');
        
        if ($action === 'save' && $this->request->method() === 'POST') {
            \SoftNova\Core\TenantMiddleware::authorize('configuracion', 'edit');
            $this->saveSettings();
            return;
        }

        if ($action === 'saveDocuments' && $this->request->method() === 'POST') {
            \SoftNova\Core\TenantMiddleware::authorize('configuracion', 'edit');
            $this->saveDocuments();
            return;
        }
        
        if ($action === 'changePassword' && $this->request->method() === 'POST') {
            $this->changePassword();
            return;
        }
        
        if ($action === 'uploadAvatar' && $this->request->method() === 'POST') {
            $this->uploadAvatar();
            return;
        }

        if ($action === 'uploadLogo' && $this->request->method() === 'POST') {
            \SoftNova\Core\TenantMiddleware::authorize('configuracion', 'edit');
            $this->uploadLogo();
            return;
        }

        if ($action === 'removeLogo' && $this->request->method() === 'POST') {
            \SoftNova\Core\TenantMiddleware::authorize('configuracion', 'edit');
            $this->removeLogo();
            return;
        }
        
        if ($action === 'backupDownload' && $this->request->method() === 'POST') {
            \SoftNova\Core\TenantMiddleware::authorize('configuracion', 'edit');
            $this->backupDownload();
            return;
        }
        
        if ($action === 'backupRestore' && $this->request->method() === 'POST') {
            \SoftNova\Core\TenantMiddleware::authorize('configuracion', 'edit');
            $this->backupRestore();
            return;
        }
        
        if ($action === 'backupSchedule' && $this->request->method() === 'POST') {
            \SoftNova\Core\TenantMiddleware::authorize('configuracion', 'edit');
            $this->backupSchedule();
            return;
        }
        
        if ($action === 'backupGetFile' && in_array($this->request->method(), ['GET', 'POST'], true)) {
            \SoftNova\Core\TenantMiddleware::authorize('configuracion', 'edit');
            $this->backupGetFile();
            return;
        }
        
        if ($action === 'backupDelete' && $this->request->method() === 'POST') {
            \SoftNova\Core\TenantMiddleware::authorize('configuracion', 'edit');
            $this->backupDelete();
            return;
        }

        if ($action === 'wipeDatabase' && $this->request->method() === 'POST') {
            \SoftNova\Core\TenantMiddleware::authorize('configuracion', 'edit');
            $this->wipeDatabase();
            return;
        }

        if ($action === 'sandboxEnter' && $this->request->method() === 'POST') {
            \SoftNova\Core\TenantMiddleware::authorize('configuracion', 'edit');
            $this->sandboxEnter();
            return;
        }
        if ($action === 'sandboxExit' && $this->request->method() === 'POST') {
            \SoftNova\Core\TenantMiddleware::authorize('configuracion', 'edit');
            $this->sandboxExit();
            return;
        }

        if ($action === 'createUser' && $this->request->method() === 'POST') {
            $this->createTeamUser();
            return;
        }
        if ($action === 'editUser' && $this->request->method() === 'POST') {
            $this->editTeamUser();
            return;
        }
        if ($action === 'toggleUser' && $this->request->method() === 'POST') {
            $this->toggleTeamUser();
            return;
        }
        if ($action === 'processJobs' && $this->request->method() === 'POST') {
            $this->processJobs();
            return;
        }

        if ($action === 'auditDetail' && $this->request->method() === 'GET') {
            $this->auditDetailJson();
            return;
        }

        if ($action === 'expenseCategorySave' && $this->request->method() === 'POST') {
            $this->saveExpenseCategory();
            return;
        }
        if ($action === 'expenseCategoryDelete' && $this->request->method() === 'POST') {
            $this->deleteExpenseCategory();
            return;
        }
        if ($action === 'expenseCategoryMove' && $this->request->method() === 'POST') {
            $this->moveExpenseCategory();
            return;
        }
        if ($action === 'expenseSubgroupSave' && $this->request->method() === 'POST') {
            $this->saveExpenseSubgroup();
            return;
        }
        if ($action === 'expenseSubgroupDelete' && $this->request->method() === 'POST') {
            $this->deleteExpenseSubgroup();
            return;
        }
        
        // Cargar settings actuales
        $settings = [];
        $rows = $this->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        if (empty($settings['timezone'])) {
            $defaultTz = \SoftNova\Core\BusinessTimezone::DEFAULT;
            $this->upsertSetting('timezone', $defaultTz);
            $settings['timezone'] = $defaultTz;
        }
        \SoftNova\Core\BusinessTimezone::applyFromTenantDb($this->db);
        
        // Auto-backup lazy al entrar a configuracion
        $this->runScheduledBackupIfDue($settings);
        // Recargar settings por si se actualizo last_run
        $settings = [];
        $rows = $this->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        $backupService = new TenantBackupService();
        $backupPage = max(1, (int)($this->request->get('backup_page') ?? 1));
        $backupList = $backupService->listBackups($backupPage, 7);
        $backups = $backupList['items'];
        $backupsAll = $backupList['all'];
        $backupPagination = $backupList['pagination'];
        
        // Datos del usuario actual
        $userId = $_SESSION['tenant_user_id'] ?? 0;
        $currentUser = null;
        if ($userId > 0) {
            $currentUser = $this->query("SELECT id, name, email, role, last_login_at FROM users WHERE id = ?", [$userId])->fetch();
        }

        $this->ensureUsersSchema();
        $teamUsers = [];
        $isAdmin = \SoftNova\Core\TenantMiddleware::getRole() === 'admin';
        $isManagement = \SoftNova\Core\TenantMiddleware::isManagement();
        $jobsPending = 0;
        if ($isAdmin) {
            try {
                $jobsPending = (new \SoftNova\Services\JobQueue($this->db))->pendingCount();
            } catch (\Throwable $e) {
                $jobsPending = 0;
            }
            try {
                $teamUsers = $this->query(
                    "SELECT id, name, email, role, permissions, status, last_login_at, created_at
                     FROM users
                     WHERE role IN ('user', 'pos', 'gerencia')
                     ORDER BY FIELD(role, 'gerencia', 'user', 'pos'), name ASC"
                )->fetchAll();
            } catch (\Throwable $e) {
                $teamUsers = $this->query(
                    "SELECT id, name, email, role, status, last_login_at, created_at
                     FROM users
                     WHERE role IN ('user', 'pos', 'gerencia')
                     ORDER BY name ASC"
                )->fetchAll();
            }
            foreach ($teamUsers as &$tu) {
                $tu['permissions_map'] = \SoftNova\Core\TenantMiddleware::normalizePermissions($tu['permissions'] ?? null);
            }
            unset($tu);
        }

        $auditLogs = [];
        $auditPagination = ['page' => 1, 'perPage' => 25, 'total' => 0, 'totalPages' => 1];
        $auditFilterOptions = ['modules' => [], 'actions' => [], 'users' => []];
        $auditAnomalies = [];
        $auditFilters = [
            'module' => trim((string)$this->request->get('audit_module', '')),
            'action' => trim((string)$this->request->get('audit_action', '')),
            'user_id' => (int)$this->request->get('audit_user', 0),
            'q' => trim((string)$this->request->get('audit_q', '')),
            'from' => trim((string)$this->request->get('audit_from', '')),
            'to' => trim((string)$this->request->get('audit_to', '')),
            'severity' => trim((string)$this->request->get('audit_severity', '')),
        ];
        $auditModuleOptions = [];
        $auditActionOptions = [];
        if ($isManagement) {
            try {
                $auditSvcClass = \SoftNova\Services\TenantAuditService::class;
                if (class_exists($auditSvcClass) && method_exists($auditSvcClass, 'listLogs')) {
                    $auditSvc = new $auditSvcClass($this->db);
                    $auditPage = max(1, (int)$this->request->get('audit_page', 1));
                    $auditResult = $auditSvc->listLogs($auditFilters, $auditPage, 25);
                    $auditLogs = $auditResult['items'];
                    $auditPagination = $auditResult['pagination'];
                    $auditFilterOptions = $auditSvc->filterOptions();
                    $auditAnomalies = $auditSvc->detectAnomalies(
                        $auditFilters['from'] !== '' ? $auditFilters['from'] : null,
                        $auditFilters['to'] !== '' ? $auditFilters['to'] : null,
                        20
                    );
                    foreach ($auditLogs as &$logRow) {
                        $logRow['module_label'] = $auditSvcClass::moduleLabel((string)($logRow['module'] ?? ''));
                        $logRow['action_label'] = $auditSvcClass::actionLabel((string)($logRow['action'] ?? ''));
                        $entityType = isset($logRow['entity_type']) ? (string)$logRow['entity_type'] : '';
                        $entityId = (int)($logRow['entity_id'] ?? 0);
                        $logRow['entity_url'] = $auditSvcClass::entityUrl(
                            $entityType !== '' ? $entityType : null,
                            $entityId > 0 ? $entityId : null
                        );
                    }
                    unset($logRow);
                    foreach ($auditFilterOptions['modules'] as $mod) {
                        $auditModuleOptions[] = [
                            'value' => (string)$mod,
                            'label' => $auditSvcClass::moduleLabel((string)$mod),
                        ];
                    }
                    foreach ($auditFilterOptions['actions'] as $act) {
                        $auditActionOptions[] = [
                            'value' => (string)$act,
                            'label' => $auditSvcClass::actionLabel((string)$act),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                error_log('config audit list: ' . $e->getMessage());
            }
        }
        
        // Info de suscripción desde BD maestra
        $tenantId = $_SESSION['tenant_id'] ?? 0;
        $subscription = null;
        if ($tenantId > 0 && !\SoftNova\Core\is_standalone()) {
            $masterDb = \SoftNova\Core\Database::getInstance();
            $sub = $masterDb->query(
                "SELECT t.status as subscription_status, t.subscription_end_date, sp.name as plan_name, sp.monthly_price, sp.annual_price
                 FROM tenants t
                 JOIN subscription_plans sp ON t.subscription_plan_id = sp.id
                 WHERE t.id = ? LIMIT 1",
                [$tenantId]
            )->fetch();
            if ($sub) {
                $subscription = $sub;
            }
        }
        
        $currencies = [
            'COP' => ['symbol' => '$', 'name' => 'Peso Colombiano (COP)'],
            'USD' => ['symbol' => 'US$', 'name' => 'Dólar Estadounidense (USD)'],
            'EUR' => ['symbol' => '€', 'name' => 'Euro (EUR)'],
            'MXN' => ['symbol' => 'MX$', 'name' => 'Peso Mexicano (MXN)'],
            'ARS' => ['symbol' => 'AR$', 'name' => 'Peso Argentino (ARS)'],
            'PEN' => ['symbol' => 'S/', 'name' => 'Sol Peruano (PEN)'],
            'CLP' => ['symbol' => 'CL$', 'name' => 'Peso Chileno (CLP)'],
        ];
        
        $languages = [
            'es' => ['flag' => '🇪🇸', 'name' => 'Español'],
            'en' => ['flag' => '🇺🇸', 'name' => 'English'],
            'pt' => ['flag' => '🇧🇷', 'name' => 'Português'],
            'fr' => ['flag' => '🇫🇷', 'name' => 'Français'],
            'de' => ['flag' => '🇩🇪', 'name' => 'Deutsch'],
            'it' => ['flag' => '🇮🇹', 'name' => 'Italiano'],
            'zh' => ['flag' => '🇨🇳', 'name' => '中文'],
            'ja' => ['flag' => '🇯🇵', 'name' => '日本語'],
        ];
        
        $expenseCategories = [];
        $expenseSubgroups = [];
        if ($isManagement) {
            try {
                $expCatSvc = new \SoftNova\Services\ExpenseCategoryService($this->db);
                $expenseCategories = $expCatSvc->listAll(true);
                $expenseSubgroups = $expCatSvc->listSubgroups(true);
            } catch (\Throwable $e) {
                $expenseCategories = [];
                $expenseSubgroups = [];
            }
        }

        $this->view('tenant.config', [
            'settings' => $settings,
            'currencies' => $currencies,
            'languages' => $languages,
            'timezones' => \SoftNova\Core\BusinessTimezone::options(),
            'businessClock' => \SoftNova\Core\BusinessTimezone::clientPayload($this->db),
            'currentUser' => $currentUser,
            'subscription' => $subscription,
            'backups' => $backups,
            'backupsAll' => $backupsAll,
            'backupPagination' => $backupPagination,
            'teamUsers' => $teamUsers,
            'isAdmin' => $isAdmin,
            'isManagement' => $isManagement,
            'jobsPending' => $jobsPending,
            'managedModules' => \SoftNova\Core\TenantMiddleware::MANAGED_MODULES,
            'permActions' => \SoftNova\Core\TenantMiddleware::ACTIONS,
            'teamRoleLabels' => \SoftNova\Core\TenantMiddleware::teamRoleLabels(),
            'tenantName' => $_SESSION['tenant_name'] ?? 'Mi Empresa',
            'userName' => $_SESSION['tenant_user_name'] ?? 'Usuario',
            'auditLogs' => $auditLogs,
            'auditPagination' => $auditPagination,
            'auditFilterOptions' => $auditFilterOptions,
            'auditFilters' => $auditFilters,
            'auditModuleOptions' => $auditModuleOptions,
            'auditActionOptions' => $auditActionOptions,
            'auditAnomalies' => $auditAnomalies,
            'expenseCategories' => $expenseCategories,
            'expenseSubgroups' => $expenseSubgroups,
            'sandboxStatus' => \SoftNova\Services\SandboxService::status(),
        ]);
    }

    private function auditDetailJson(): void
    {
        if (!\SoftNova\Core\TenantMiddleware::isManagement()) {
            $this->json(['success' => false, 'message' => 'No autorizado'], 403);
            return;
        }

        $id = (int)$this->request->get('id', 0);
        if ($id <= 0) {
            $this->json(['success' => false, 'message' => 'Registro inválido'], 400);
            return;
        }

        try {
            $detail = (new \SoftNova\Services\TenantAuditService($this->db))->getDetail($id);
            if (!$detail || empty($detail['log'])) {
                $this->json(['success' => false, 'message' => 'Registro no encontrado'], 404);
                return;
            }
            $this->json([
                'success' => true,
                'log' => $detail['log'],
                'related' => $detail['related'] ?? [],
                'anomalies' => $detail['anomalies'] ?? [],
            ]);
        } catch (\Throwable $e) {
            error_log('auditDetail #' . $id . ': ' . $e->getMessage());
            $this->json(['success' => false, 'message' => 'Error al cargar el registro'], 500);
        }
    }

    private function ensureUsersSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $col = $this->db->query("SHOW COLUMNS FROM users LIKE 'permissions'")->fetch();
            if (!$col) {
                $this->db->exec("ALTER TABLE users ADD COLUMN permissions LONGTEXT NULL AFTER role");
            }
        } catch (\Throwable $e) {
            error_log('ensureUsersSchema permissions: ' . $e->getMessage());
        }

        // Solo ALTER role si faltan valores (evitar MODIFY en cada guardado: puede bloquear MySQL).
        try {
            $roleCol = $this->db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
            $type = strtolower((string)($roleCol['Type'] ?? ''));
            $needs = str_starts_with($type, 'enum(')
                && (!str_contains($type, "'user'") || !str_contains($type, "'pos'") || !str_contains($type, "'gerencia'"));
            if ($needs) {
                $this->db->exec(
                    "ALTER TABLE users
                     MODIFY COLUMN role ENUM('admin','manager','cashier','viewer','auxiliar','user','pos','mesero','gerencia') DEFAULT 'admin'"
                );
            }
        } catch (\Throwable $e) {
            error_log('ensureUsersSchema role: ' . $e->getMessage());
        }
    }

    private function assertLocalAdmin(): bool
    {
        if (\SoftNova\Core\TenantMiddleware::getRole() !== 'admin') {
            $this->respond(false, 'Solo el administrador puede gestionar el equipo', '/app/configuracion');
            return false;
        }
        return true;
    }

    private const MAX_ACTIVE_GERENCIA = 2;

    private function activeGerenciaCount(int $excludeId = 0): int
    {
        return (int)$this->query(
            "SELECT COUNT(*) FROM users WHERE role = 'gerencia' AND status = 'active' AND id <> ?",
            [$excludeId]
        )->fetchColumn();
    }

    /**
     * Asignar rol Gerencia exige la contraseña del administrador y respeta el cupo.
     * @return string Mensaje de error ('' si procede)
     */
    private function gerenciaAssignmentError(int $excludeId = 0): string
    {
        if (!$this->verifyAdminPassword((string)$this->request->post('admin_password', ''))) {
            return 'Contraseña del administrador incorrecta. Es obligatoria para asignar el rol Gerencia.';
        }
        if ($this->activeGerenciaCount($excludeId) >= self::MAX_ACTIVE_GERENCIA) {
            return 'No es posible asignar más usuarios con rol Gerencia. Contacte al proveedor del sistema.';
        }
        return '';
    }

    private function buildPermissionsFromPost(string $role): string
    {
        if ($role === 'pos') {
            return json_encode(\SoftNova\Core\TenantMiddleware::posDefaultPermissions(), JSON_UNESCAPED_UNICODE);
        }
        $raw = $this->request->post('permissions', []);
        $map = \SoftNova\Core\TenantMiddleware::normalizePermissions(is_array($raw) ? $raw : []);
        return json_encode($map, JSON_UNESCAPED_UNICODE);
    }

    private function createTeamUser(): void
    {
        if (!$this->assertLocalAdmin() || !$this->validateCsrfOrFail('/app/configuracion')) {
            return;
        }

        try {
            $this->ensureUsersSchema();

            $name = trim((string)$this->request->post('name', ''));
            $email = trim((string)$this->request->post('email', ''));
            $password = (string)$this->request->post('password', '');
            $role = \SoftNova\Core\TenantMiddleware::normalizeRole((string)$this->request->post('role', 'user'));

            if (!isset(\SoftNova\Core\TenantMiddleware::teamRoleLabels()[$role])) {
                $this->respond(false, 'Rol no permitido. Solo User, POS o Gerencia.', '/app/configuracion#usuarios');
                return;
            }
            if ($name === '' || $email === '' || $password === '') {
                $this->respond(false, 'Nombre, email y contraseña son obligatorios', '/app/configuracion#usuarios');
                return;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->respond(false, 'Email inválido', '/app/configuracion#usuarios');
                return;
            }
            if (strlen($password) < 6) {
                $this->respond(false, 'La contraseña debe tener al menos 6 caracteres', '/app/configuracion#usuarios');
                return;
            }

            $exists = $this->query("SELECT id FROM users WHERE email = ? LIMIT 1", [$email])->fetch();
            if ($exists) {
                $this->respond(false, 'Ya existe un usuario con ese email', '/app/configuracion#usuarios');
                return;
            }
            if ($role === 'gerencia') {
                $gerenciaError = $this->gerenciaAssignmentError();
                if ($gerenciaError !== '') {
                    $this->respond(false, $gerenciaError, '/app/configuracion#usuarios');
                    return;
                }
            }

            $hash = Security::hashPassword($password);
            $perms = $this->buildPermissionsFromPost($role);

            $this->query(
                "INSERT INTO users (name, email, password, role, permissions, status) VALUES (?, ?, ?, ?, ?, 'active')",
                [$name, $email, $hash, $role, $perms]
            );
            $newId = (int)$this->db->lastInsertId();
            $this->syncTeamUserToMaster($email, $name, $role, $perms, $hash);
            (new \SoftNova\Services\TenantAuditService($this->db))->log(
                'create',
                'usuarios',
                "Usuario creado: {$name} ({$email}) rol={$role}",
                'user',
                $newId
            );

            $this->respond(true, 'Usuario creado: ' . $name, '/app/configuracion#usuarios');
        } catch (\Throwable $e) {
            error_log('createTeamUser: ' . $e->getMessage());
            $this->respond(false, 'No se pudo crear el usuario: ' . $e->getMessage(), '/app/configuracion#usuarios');
        }
    }

    private function processJobs(): void
    {
        if (!$this->assertLocalAdmin() || !$this->validateCsrfOrFail('/app/configuracion')) {
            return;
        }
        $queue = new \SoftNova\Services\JobQueue($this->db);
        $result = $queue->process(15);
        $msg = sprintf(
            'Jobs: %d procesados, %d fallidos, %d pendientes',
            $result['processed'],
            $result['failed'],
            $queue->pendingCount()
        );
        $this->respond(true, $msg, '/app/configuracion');
    }

    private function editTeamUser(): void
    {
        if (!$this->assertLocalAdmin() || !$this->validateCsrfOrFail('/app/configuracion')) {
            return;
        }

        try {
            $this->ensureUsersSchema();

            $id = (int)$this->request->post('id', 0);
            $name = trim((string)$this->request->post('name', ''));
            $email = trim((string)$this->request->post('email', ''));
            $password = (string)$this->request->post('password', '');
            $role = \SoftNova\Core\TenantMiddleware::normalizeRole((string)$this->request->post('role', 'user'));

            $user = $this->query("SELECT * FROM users WHERE id = ? LIMIT 1", [$id])->fetch();
            if (!$user || !in_array($user['role'], ['user', 'pos', 'gerencia'], true)) {
                $this->respond(false, 'Usuario no encontrado o no editable', '/app/configuracion#usuarios');
                return;
            }
            if (!isset(\SoftNova\Core\TenantMiddleware::teamRoleLabels()[$role])) {
                $this->respond(false, 'Rol no permitido', '/app/configuracion#usuarios');
                return;
            }
            if ($role === 'gerencia' && $user['role'] !== 'gerencia') {
                $gerenciaError = $this->gerenciaAssignmentError(
                    ($user['status'] ?? '') === 'active' ? $id : 0
                );
                if ($gerenciaError !== '') {
                    $this->respond(false, $gerenciaError, '/app/configuracion#usuarios');
                    return;
                }
            }
            if ($name === '' || $email === '') {
                $this->respond(false, 'Nombre y email son obligatorios', '/app/configuracion#usuarios');
                return;
            }

            $dup = $this->query("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1", [$email, $id])->fetch();
            if ($dup) {
                $this->respond(false, 'Email ya usado por otro usuario', '/app/configuracion#usuarios');
                return;
            }

            $perms = $this->buildPermissionsFromPost($role);
            $passwordHash = null;
            if ($password !== '') {
                if (strlen($password) < 6) {
                    $this->respond(false, 'La contraseña debe tener al menos 6 caracteres', '/app/configuracion#usuarios');
                    return;
                }
                $passwordHash = Security::hashPassword($password);
                $this->query(
                    "UPDATE users SET name = ?, email = ?, role = ?, permissions = ?, password = ? WHERE id = ?",
                    [$name, $email, $role, $perms, $passwordHash, $id]
                );
            } else {
                $this->query(
                    "UPDATE users SET name = ?, email = ?, role = ?, permissions = ? WHERE id = ?",
                    [$name, $email, $role, $perms, $id]
                );
            }

            // Si se edita el usuario logueado (raro), rehidratar
            if ((int)($_SESSION['tenant_user_id'] ?? 0) === $id) {
                \SoftNova\Core\TenantMiddleware::hydrateSessionPermissions($role, $perms);
            }

            $this->syncTeamUserToMaster($email, $name, $role, $perms, $passwordHash);

            (new \SoftNova\Services\TenantAuditService($this->db))->log(
                'update',
                'usuarios',
                "Usuario editado: {$name} ({$email}) rol={$role}",
                'user',
                $id
            );

            $this->respond(true, 'Usuario actualizado', '/app/configuracion#usuarios');
        } catch (\Throwable $e) {
            error_log('editTeamUser: ' . $e->getMessage());
            $this->respond(false, 'No se pudo guardar el usuario: ' . $e->getMessage(), '/app/configuracion#usuarios');
        }
    }

    /**
     * Sincroniza usuario externo con tenant_users (login SaaS) cuando no es standalone.
     */
    private function syncTeamUserToMaster(
        string $email,
        string $name,
        string $role,
        string $permissionsJson,
        ?string $passwordHash = null
    ): void {
        if (\SoftNova\Core\is_standalone()) {
            return;
        }

        $tenantId = (int)($_SESSION['tenant_id'] ?? 0);
        if ($tenantId <= 0 || trim($email) === '') {
            return;
        }

        try {
            $masterDb = \SoftNova\Core\Database::getInstance();
            if ($passwordHash !== null) {
                $masterDb->query(
                    "UPDATE tenant_users SET name = ?, role = ?, permissions = ?, password = ?
                     WHERE email = ? AND tenant_id = ?",
                    [$name, $role, $permissionsJson, $passwordHash, $email, $tenantId]
                );
            } else {
                $masterDb->query(
                    "UPDATE tenant_users SET name = ?, role = ?, permissions = ?
                     WHERE email = ? AND tenant_id = ?",
                    [$name, $role, $permissionsJson, $email, $tenantId]
                );
            }
        } catch (\Throwable $e) {
            error_log('syncTeamUserToMaster: ' . $e->getMessage());
        }
    }

    private function toggleTeamUser(): void
    {
        if (!$this->assertLocalAdmin() || !$this->validateCsrfOrFail('/app/configuracion')) {
            return;
        }
        $id = (int)$this->request->post('id', 0);
        $user = $this->query("SELECT id, role, status FROM users WHERE id = ? LIMIT 1", [$id])->fetch();
        if (!$user || !in_array($user['role'], ['user', 'pos', 'gerencia'], true)) {
            $this->respond(false, 'Usuario no encontrado', '/app/configuracion');
            return;
        }
        if ((int)$id === (int)($_SESSION['tenant_user_id'] ?? 0)) {
            $this->respond(false, 'No puede desactivar su propia cuenta', '/app/configuracion');
            return;
        }
        $newStatus = ($user['status'] ?? '') === 'active' ? 'inactive' : 'active';
        if (
            $newStatus === 'active'
            && $user['role'] === 'gerencia'
            && $this->activeGerenciaCount((int)$id) >= self::MAX_ACTIVE_GERENCIA
        ) {
            $this->respond(false, 'No es posible activar más usuarios con rol Gerencia. Contacte al proveedor del sistema.', '/app/configuracion#usuarios');
            return;
        }
        $this->query("UPDATE users SET status = ? WHERE id = ?", [$newStatus, $id]);
        (new \SoftNova\Services\TenantAuditService($this->db))->log(
            'update',
            'usuarios',
            'Usuario ' . ($newStatus === 'active' ? 'activado' : 'desactivado') . ' #' . $id,
            'user',
            $id
        );
        $this->respond(true, $newStatus === 'active' ? 'Usuario activado' : 'Usuario desactivado', '/app/configuracion#usuarios');
    }
    
    /**
     * Guardar configuración
     */
    private function saveSettings(): void
    {
        if (!$this->validateCsrfOrFail('/app/configuracion')) {
            return;
        }
        
        $fields = [
            'currency', 'company_name', 'tax_name', 'tax_enabled', 'product_tax_enabled', 'tax_rate', 'invoice_prefix',
            'low_stock_alert', 'language', 'timezone', 'webhook_sale_url',
            'company_nit', 'company_address', 'company_phone', 'company_email',
            'ean_prefix', 'ean_next', 'ean_length', 'ean_check_digit',
            'sku_prefix', 'sku_next', 'sku_pad',
        ];
        
        foreach ($fields as $field) {
            $value = $this->request->post($field);
            if ($value !== null) {
                if ($field === 'timezone') {
                    $value = \SoftNova\Core\BusinessTimezone::normalize((string)$value);
                    if (!isset(\SoftNova\Core\BusinessTimezone::options()[$value])) {
                        // Acepta IANA válida aunque no esté en la lista corta
                        $value = \SoftNova\Core\BusinessTimezone::normalize($value);
                    }
                }
                if ($field === 'webhook_sale_url') {
                    $value = trim((string)$value);
                    if ($value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                        $this->respond(false, 'URL de webhook inválida', '/app/configuracion');
                        return;
                    }
                }
                if ($field === 'company_email') {
                    $value = trim((string)$value);
                    if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $this->respond(false, 'Correo de empresa inválido', '/app/configuracion#documentos');
                        return;
                    }
                }
                if ($field === 'ean_prefix') {
                    $value = preg_replace('/\D+/', '', (string)$value) ?? '';
                    if ($value === '') {
                        $value = '200';
                    }
                    $value = substr($value, 0, 12);
                }
                if ($field === 'ean_next') {
                    $value = (string)max(1, (int)$value);
                }
                if ($field === 'ean_length') {
                    $value = in_array((int)$value, [8, 13], true) ? (string)(int)$value : '13';
                }
                if ($field === 'ean_check_digit') {
                    $value = ((string)$value === '0') ? '0' : '1';
                }
                if ($field === 'tax_enabled') {
                    $value = in_array(strtolower(trim((string)$value)), ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
                }
                if ($field === 'product_tax_enabled') {
                    $value = in_array(strtolower(trim((string)$value)), ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
                }
                if ($field === 'tax_rate') {
                    $raw = trim((string)$value);
                    if ($raw === '') {
                        $value = '0';
                    } else {
                        $rate = (float)$raw;
                        if ($rate < 0) {
                            $rate = 0.0;
                        }
                        if ($rate > 100) {
                            $rate = 100.0;
                        }
                        $value = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') ?: '0';
                    }
                }
                if ($field === 'tax_name') {
                    $value = trim((string)$value);
                    if ($value === '') {
                        $value = 'IVA';
                    }
                }
                if ($field === 'sku_prefix') {
                    $value = substr(trim((string)$value), 0, 40);
                    if ($value === '') {
                        $value = 'SKU-';
                    }
                }
                if ($field === 'sku_next') {
                    $value = (string)max(1, (int)$value);
                }
                if ($field === 'sku_pad') {
                    $pad = (int)$value;
                    if ($pad < 3) {
                        $pad = 3;
                    }
                    if ($pad > 12) {
                        $pad = 12;
                    }
                    $value = (string)$pad;
                }
                $this->upsertSetting($field, (string)$value);
            }
        }

        // Checkbox: si no viene en POST, queda desactivado
        if ($this->request->post('ean_prefix') !== null && $this->request->post('ean_check_digit') === null) {
            $this->upsertSetting('ean_check_digit', '0');
        }

        $primaryColor = $this->request->post('primary_color');
        if ($primaryColor !== null) {
            $primaryColor = trim((string)$primaryColor);
            if ($primaryColor === '' || preg_match('/^#[0-9A-Fa-f]{6}$/', $primaryColor)) {
                $this->upsertSetting('primary_color', strtoupper($primaryColor));
            }
        }

        \SoftNova\Services\SettingsCache::invalidate();
        // Reaplicar zona horaria al instante si se cambió.
        try {
            \SoftNova\Core\BusinessTimezone::applyFromTenantDb($this->db);
        } catch (\Throwable $e) {
            // ignore
        }
        
        $this->respond(true, 'Configuración guardada exitosamente', '/app/configuracion');
    }

    /** Guarda datos de contacto + layout de ticket 58mm / PDF carta. */
    private function saveDocuments(): void
    {
        if (!$this->validateCsrfOrFail('/app/configuracion')) {
            return;
        }

        $contactFields = ['company_nit', 'company_address', 'company_phone', 'company_email'];
        foreach ($contactFields as $field) {
            $value = $this->request->post($field);
            if ($value === null) {
                continue;
            }
            $value = trim((string)$value);
            if ($field === 'company_email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $this->respond(false, 'Correo de empresa inválido', '/app/configuracion#documentos');
                return;
            }
            $this->upsertSetting($field, $value);
        }

        $posted = $_POST;
        $config = \SoftNova\Services\SalesDocumentConfig::fromRequest($posted);
        $this->upsertSetting(
            \SoftNova\Services\SalesDocumentConfig::SETTING_KEY,
            (string)json_encode($config, JSON_UNESCAPED_UNICODE)
        );

        \SoftNova\Services\SettingsCache::invalidate();
        $this->respond(true, 'Documentos de venta actualizados', '/app/configuracion#documentos');
    }
    
    private function upsertSetting(string $key, string $value): void
    {
        $existing = $this->query(
            "SELECT id FROM settings WHERE setting_key = ?",
            [$key]
        )->fetch();
        
        if ($existing) {
            $this->query(
                "UPDATE settings SET setting_value = ? WHERE setting_key = ?",
                [$value, $key]
            );
        } else {
            $this->query(
                "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)",
                [$key, $value]
            );
        }
        \SoftNova\Services\SettingsCache::invalidate();
    }
    
    private function requireAdmin(): bool
    {
        if (($_SESSION['tenant_user_role'] ?? '') !== 'admin') {
            $this->respond(false, 'Solo el administrador puede gestionar copias de seguridad');
            return false;
        }
        return true;
    }
    
    private function backupDownload(): void
    {
        if (!$this->validateCsrfOrFail('/app/configuracion')) {
            return;
        }
        if (!$this->requireAdmin()) {
            return;
        }
        if (!$this->verifyAdminPassword((string)($this->request->post('confirm_password') ?? ''))) {
            $this->respond(false, 'Contraseña incorrecta. La descarga fue cancelada.');
            return;
        }
        
        $dbName = $this->resolveOperationalDbName();
        if ($dbName === '') {
            $this->respond(false, 'No se encontro la base de datos del tenant');
            return;
        }

        (new \SoftNova\Services\JobQueue($this->db))->push('backup', [
            'database' => $dbName,
            'type' => 'manual',
        ]);
        $this->respond(true, 'Backup encolado. Use “Procesar jobs” o el CLI para generarlo.', '/app/configuracion');
    }
    
    private function backupGetFile(): void
    {
        if (!$this->requireAdmin()) {
            return;
        }
        
        $file = (string)($this->request->method() === 'POST'
            ? ($this->request->post('file') ?? '')
            : ($this->request->get('file') ?? ''));
        
        $allowed = false;
        $token = $_SESSION['backup_dl'] ?? null;
        if (is_array($token)
            && ($token['file'] ?? '') === $file
            && (int)($token['until'] ?? 0) >= time()
        ) {
            $allowed = true;
            unset($_SESSION['backup_dl']);
        }
        
        if (!$allowed) {
            if ($this->request->method() !== 'POST') {
                $this->respond(false, 'Para descargar debe confirmar con su contraseña', '/app/configuracion');
                return;
            }
            if (!$this->validateCsrfOrFail('/app/configuracion')) {
                return;
            }
            if (!$this->verifyAdminPassword((string)($this->request->post('confirm_password') ?? ''))) {
                $this->respond(false, 'Contraseña incorrecta. La descarga fue cancelada.', '/app/configuracion');
                return;
            }
        }
        
        $svc = new TenantBackupService();
        $path = $svc->resolvePath($file);
        if (!$path) {
            $this->respond(false, 'Archivo no encontrado', '/app/configuracion');
            return;
        }
        
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        exit;
    }
    
    private function verifyAdminPassword(string $password): bool
    {
        if ($password === '') {
            return false;
        }
        $userId = (int)($_SESSION['tenant_user_id'] ?? 0);
        $user = $this->query("SELECT password FROM users WHERE id = ?", [$userId])->fetch();
        return $user && Security::verifyPassword($password, $user['password']);
    }

    /**
     * Borrado general: vacía datos operativos y deja solo el admin actual + settings/PUC.
     */
    private function wipeDatabase(): void
    {
        if (!$this->validateCsrfOrFail('/app/configuracion')) {
            return;
        }
        if (!$this->requireAdmin()) {
            return;
        }

        $phrase = strtoupper(trim((string)$this->request->post('confirm_phrase', '')));
        if ($phrase !== 'BORRAR TODO') {
            $this->respond(false, 'Debe escribir exactamente BORRAR TODO para confirmar', '/app/configuracion');
            return;
        }

        if (!$this->verifyAdminPassword((string)($this->request->post('confirm_password') ?? ''))) {
            $this->respond(false, 'Contraseña incorrecta. La limpieza fue cancelada.', '/app/configuracion');
            return;
        }

        $userId = (int)($_SESSION['tenant_user_id'] ?? 0);
        try {
            $result = (new \SoftNova\Services\DatabaseWipeService($this->db))->wipeKeepAdmin($userId);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/configuracion');
            return;
        }

        $sandboxNote = \SoftNova\Services\SandboxService::isActive()
            ? ' (solo afectó la caja de arena, no producción)'
            : '';
        $msg = sprintf(
            'Base de datos limpiada: %d tabla(s) vaciadas, %d usuario(s) eliminado(s). Conservado: %s%s',
            (int)$result['truncated'],
            (int)$result['users_deleted'],
            (string)$result['kept_user_email'],
            $sandboxNote
        );
        $this->respond(true, $msg, '/app/configuracion');
    }

    /** Activa modo caja de arena (clona producción a espejo). */
    private function sandboxEnter(): void
    {
        if (!$this->validateCsrfOrFail('/app/configuracion')) {
            return;
        }
        if (!$this->requireAdmin()) {
            return;
        }
        if (!$this->verifyAdminPassword((string)($this->request->post('confirm_password') ?? ''))) {
            $this->respond(false, 'Contraseña incorrecta. No se activó la caja de arena.', '/app/configuracion');
            return;
        }

        $refresh = (string)$this->request->post('refresh', '1') !== '0';
        try {
            $result = \SoftNova\Services\SandboxService::enter($refresh);
            $this->db = \SoftNova\Core\TenantMiddleware::refreshDb();
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/configuracion');
            return;
        }

        if (empty($result['success'])) {
            $this->respond(false, $result['message'] ?? 'No se pudo activar la caja de arena', '/app/configuracion');
            return;
        }

        $this->respond(true, $result['message'], '/app/configuracion');
    }

    /** Sale de la caja de arena y vuelve a producción. */
    private function sandboxExit(): void
    {
        if (!$this->validateCsrfOrFail('/app/configuracion')) {
            return;
        }
        if (!$this->requireAdmin()) {
            return;
        }

        $drop = (string)$this->request->post('drop_sandbox', '0') === '1';
        if ($drop) {
            if (!$this->verifyAdminPassword((string)($this->request->post('confirm_password') ?? ''))) {
                $this->respond(false, 'Contraseña incorrecta. No se eliminó el espejo.', '/app/configuracion');
                return;
            }
        }

        try {
            $result = \SoftNova\Services\SandboxService::exitMode($drop);
            $this->db = \SoftNova\Core\TenantMiddleware::refreshDb();
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/configuracion');
            return;
        }

        $this->respond(true, $result['message'] ?? 'Volvió a producción', '/app/configuracion');
    }

    private function resolveOperationalDbName(): string
    {
        if (\SoftNova\Services\SandboxService::isActive()) {
            $active = \SoftNova\Services\SandboxService::activeDbName();
            if ($active !== '') {
                return $active;
            }
        }
        $dbName = (string)($_SESSION['tenant_db_name'] ?? '');
        if ($dbName === '') {
            $dbName = (string)\SoftNova\Core\config('database.default.database', '');
        }
        return $dbName;
    }
    
    private function backupDelete(): void
    {
        if (!$this->validateCsrfOrFail('/app/configuracion')) {
            return;
        }
        if (!$this->requireAdmin()) {
            return;
        }
        
        $file = (string)($this->request->post('file') ?? '');
        $svc = new TenantBackupService();
        $result = $svc->deleteBackup($file);
        
        if (empty($result['success'])) {
            $this->respond(false, $result['message'] ?? 'No se pudo eliminar', '/app/configuracion');
            return;
        }
        
        $this->respond(true, 'Copia de seguridad eliminada', '/app/configuracion');
    }
    
    private function backupRestore(): void
    {
        if (!$this->validateCsrfOrFail('/app/configuracion')) {
            return;
        }
        if (!$this->requireAdmin()) {
            return;
        }
        
        $password = (string)($this->request->post('confirm_password') ?? '');
        if (!$this->verifyAdminPassword($password)) {
            $this->respond(false, 'Contraseña incorrecta. La restauracion fue cancelada.');
            return;
        }
        
        $dbName = $this->resolveOperationalDbName();
        if ($dbName === '') {
            $this->respond(false, 'No se encontro la base de datos del tenant');
            return;
        }
        
        $svc = new TenantBackupService();
        
        $path = null;
        $fromFile = (string)($this->request->post('backup_file') ?? '');
        if ($fromFile !== '') {
            $path = $svc->resolvePath($fromFile);
        }
        
        if (!$path && isset($_FILES['backup_upload']) && $_FILES['backup_upload']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['backup_upload']['tmp_name'];
            $name = basename((string)$_FILES['backup_upload']['name']);
            if (!preg_match('/\.sql$/i', $name)) {
                $this->respond(false, 'Solo se aceptan archivos .sql');
                return;
            }
            if ((int)$_FILES['backup_upload']['size'] > 80 * 1024 * 1024) {
                $this->respond(false, 'El archivo supera el limite de 80MB');
                return;
            }
            $dest = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'restore_' . (int)($_SESSION['tenant_user_id'] ?? 0) . '_' . time() . '.sql';
            if (!move_uploaded_file($tmp, $dest)) {
                $this->respond(false, 'No se pudo recibir el archivo');
                return;
            }
            $path = $dest;
        }
        
        if (!$path) {
            $this->respond(false, 'Seleccione un backup existente o suba un archivo .sql');
            return;
        }
        
        $result = $svc->restoreBackup($dbName, $path);
        if (str_starts_with((string)$path, sys_get_temp_dir())) {
            @unlink($path);
        }
        
        if (empty($result['success'])) {
            $this->respond(false, $result['message'] ?? 'Error al restaurar');
            return;
        }
        
        $this->respond(true, 'Restauracion completada.', '/app/configuracion');
    }
    
    private function backupSchedule(): void
    {
        if (!$this->validateCsrfOrFail('/app/configuracion')) {
            return;
        }
        if (!$this->requireAdmin()) {
            return;
        }
        
        $enabled = $this->request->post('backup_enabled') ? '1' : '0';
        $time = (string)($this->request->post('backup_time') ?? '02:00');
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time)) {
            $this->respond(false, 'Hora invalida. Use formato HH:MM');
            return;
        }
        
        $this->upsertSetting('backup_enabled', $enabled);
        $this->upsertSetting('backup_time', $time);
        
        $this->respond(true, 'Programacion de backup guardada', '/app/configuracion');
    }
    
    private function runScheduledBackupIfDue(array &$settings): void
    {
        try {
            // No programar backups automáticos mientras la sesión está en caja de arena.
            if (\SoftNova\Services\SandboxService::isActive()) {
                return;
            }
            $dbName = \SoftNova\Services\SandboxService::productionDbName();
            if ($dbName === '') {
                $dbName = (string)($_SESSION['tenant_db_name'] ?? '');
            }
            if ($dbName === '') {
                return;
            }
            $svc = new TenantBackupService();
            $result = $svc->maybeAutoBackup($dbName, $settings);
            $hints = TenantBackupService::consumeSettingHints();
            foreach ($hints as $k => $v) {
                $this->upsertSetting((string)$k, (string)$v);
                $settings[(string)$k] = (string)$v;
            }
            if ($result && !empty($result['success'])) {
                $now = date('Y-m-d H:i:s');
                $this->upsertSetting('backup_last_run', $now);
                $settings['backup_last_run'] = $now;
            }
        } catch (\Throwable $e) {
            error_log('config auto-backup: ' . $e->getMessage());
        }
    }
    
    /**
     * Cambiar contraseña del usuario
     */
    private function changePassword(): void
    {
        if (!$this->validateCsrfOrFail('/app/configuracion')) {
            return;
        }
        
        $userId = $_SESSION['tenant_user_id'] ?? 0;
        $currentPassword = $this->request->post('current_password') ?? '';
        $newPassword = $this->request->post('new_password') ?? '';
        $confirmPassword = $this->request->post('confirm_password') ?? '';
        
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $this->respond(false, 'Todos los campos son requeridos');
            return;
        }
        
        if ($newPassword !== $confirmPassword) {
            $this->respond(false, 'Las contraseñas nuevas no coinciden');
            return;
        }
        
        if (strlen($newPassword) < 8) {
            $this->respond(false, 'La contraseña debe tener al menos 8 caracteres');
            return;
        }
        
        // Verificar contraseña actual
        $user = $this->query("SELECT password, email FROM users WHERE id = ?", [$userId])->fetch();
        if (!$user || !Security::verifyPassword($currentPassword, $user['password'])) {
            $this->respond(false, 'La contraseña actual es incorrecta');
            return;
        }
        
        // Actualizar contraseña en BD tenant y en master (login usa tenant_users)
        $hashed = Security::hashPassword($newPassword);
        $this->query("UPDATE users SET password = ? WHERE id = ?", [$hashed, $userId]);
        
        try {
            $masterDb = \SoftNova\Core\Database::getInstance();
            $masterUserId = (int)($_SESSION['tenant_master_user_id'] ?? 0);
            $tenantId = (int)($_SESSION['tenant_id'] ?? 0);
            $email = (string)($_SESSION['tenant_user_email'] ?? $user['email'] ?? '');
            
            if ($masterUserId > 0) {
                $masterDb->query(
                    "UPDATE tenant_users SET password = ? WHERE id = ? AND tenant_id = ?",
                    [$hashed, $masterUserId, $tenantId]
                );
            } elseif ($email !== '' && $tenantId > 0) {
                $masterDb->query(
                    "UPDATE tenant_users SET password = ? WHERE email = ? AND tenant_id = ?",
                    [$hashed, $email, $tenantId]
                );
            }
        } catch (\Throwable $e) {
            error_log('changePassword master sync: ' . $e->getMessage());
            $this->respond(false, 'Contraseña local actualizada, pero no se pudo sincronizar el login. Contacte soporte.');
            return;
        }
        
        $this->respond(true, 'Contraseña actualizada exitosamente');
    }
    
    /**
     * Subir avatar/imagen de perfil
     */
    private function uploadAvatar(): void
    {
        if (!$this->validateCsrfOrFail('/app/configuracion')) {
            return;
        }
        
        $userId = $_SESSION['tenant_user_id'] ?? 0;
        
        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            $this->respond(false, 'Error al subir la imagen');
            return;
        }
        
        $file = $_FILES['avatar'];
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 2 * 1024 * 1024; // 2MB
        
        if ($file['size'] > $maxSize) {
            $this->respond(false, 'La imagen no debe superar 2MB');
            return;
        }
        
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']) ?: '';
        if (!in_array($realMime, $allowedMimes, true)) {
            $this->respond(false, 'Formato no permitido. Use JPG, PNG, GIF o WebP');
            return;
        }
        
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        $ext = $mimeToExt[$realMime] ?? strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $this->respond(false, 'Extension de archivo no permitida');
            return;
        }
        
        // Crear directorio si no existe
        $uploadDir = ROOT_PATH . '/public/uploads/avatars/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
        $destPath = $uploadDir . $filename;
        
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $this->respond(false, 'Error al guardar la imagen');
            return;
        }
        
        $avatarUrl = 'uploads/avatars/' . $filename;
        
        $this->upsertSetting('user_avatar', $avatarUrl);
        
        $this->respond(true, 'Imagen de perfil actualizada');
    }

    private function uploadLogo(): void
    {
        if (!$this->validateCsrfOrFail('/app/configuracion#documentos')) {
            return;
        }
        if (!isset($_FILES['logo'])) {
            $this->respond(false, 'No se recibió el archivo. Seleccione una imagen y pulse Subir logo.', '/app/configuracion#documentos');
            return;
        }

        $file = $_FILES['logo'];
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE => 'El archivo supera el límite del servidor (upload_max_filesize)',
                UPLOAD_ERR_FORM_SIZE => 'El archivo supera el límite del formulario',
                UPLOAD_ERR_PARTIAL => 'La subida se interrumpió; intente de nuevo',
                UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo',
                UPLOAD_ERR_NO_TMP_DIR => 'Falta carpeta temporal en el servidor',
                UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo temporal',
                UPLOAD_ERR_EXTENSION => 'Una extensión de PHP bloqueó la subida',
            ];
            $this->respond(
                false,
                $messages[$err] ?? ('Error al subir el logo (código ' . $err . ')'),
                '/app/configuracion#documentos'
            );
            return;
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
            $this->respond(false, 'El logo no debe superar 2MB', '/app/configuracion#documentos');
            return;
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $this->respond(false, 'Archivo temporal inválido', '/app/configuracion#documentos');
            return;
        }

        $realMime = '';
        if (class_exists(\finfo::class)) {
            try {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $realMime = (string)($finfo->file($tmp) ?: '');
            } catch (\Throwable $e) {
                $realMime = '';
            }
        }
        if ($realMime === '' && function_exists('mime_content_type')) {
            $realMime = (string)(@mime_content_type($tmp) ?: '');
        }
        if ($realMime === '' || !in_array($realMime, $allowedMimes, true)) {
            // Respaldo por extensión si el hosting no reporta MIME
            $extGuess = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
            $extToMime = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
            ];
            if (isset($extToMime[$extGuess]) && @getimagesize($tmp) !== false) {
                $realMime = $extToMime[$extGuess];
            }
        }
        if (!in_array($realMime, $allowedMimes, true)) {
            $this->respond(false, 'Formato no permitido. Use JPG, PNG, GIF o WebP', '/app/configuracion#documentos');
            return;
        }

        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        $ext = $mimeToExt[$realMime] ?? 'png';
        $uploadDir = (defined('PUBLIC_PATH') ? PUBLIC_PATH : (ROOT_PATH . '/public')) . '/uploads/branding/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            $this->respond(false, 'No se pudo crear la carpeta de branding', '/app/configuracion#documentos');
            return;
        }
        if (!is_writable($uploadDir)) {
            $this->respond(false, 'La carpeta de branding no tiene permisos de escritura', '/app/configuracion#documentos');
            return;
        }

        // Garantizar acceso público a branding (Apache) si falta el .htaccess
        $brandingHtaccess = $uploadDir . '.htaccess';
        if (!is_file($brandingHtaccess)) {
            @file_put_contents(
                $brandingHtaccess,
                "# Branding público\n"
                . "<IfModule mod_authz_core.c>\n    Require all granted\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Allow from all\n</IfModule>\n"
            );
        }

        $old = trim((string)$this->getSetting('company_logo', ''));
        $filename = 'logo_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        $destPath = $uploadDir . $filename;
        if (!@move_uploaded_file($tmp, $destPath)) {
            // Algunos hostings renombran el tmp; intentar copy como respaldo
            if (!@copy($tmp, $destPath)) {
                $this->respond(false, 'Error al guardar el logo en disco (permisos)', '/app/configuracion#documentos');
                return;
            }
            @unlink($tmp);
        }
        @chmod($destPath, 0644);

        $rel = 'uploads/branding/' . $filename;
        $this->upsertSetting('company_logo', $rel);
        if ($old !== '' && $old !== $rel) {
            $oldPath = (defined('PUBLIC_PATH') ? PUBLIC_PATH : (ROOT_PATH . '/public')) . '/' . ltrim($old, '/');
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }
        \SoftNova\Services\SettingsCache::invalidate();

        $logoUrl = \SoftNova\Core\media_url($rel);
        // Respaldo: si la URL pública no aplica, el front puede usar /app/media
        $mediaUrl = \SoftNova\Core\base_url('/app/media?f=' . rawurlencode($rel));
        $this->respond(true, 'Logo actualizado', '/app/configuracion#documentos', [
            'logo_url' => $logoUrl !== '' ? $logoUrl : $mediaUrl,
            'logo_media_url' => $mediaUrl,
            'company_logo' => $rel,
        ]);
    }

    private function removeLogo(): void
    {
        if (!$this->validateCsrfOrFail('/app/configuracion#documentos')) {
            return;
        }
        $old = trim((string)$this->getSetting('company_logo', ''));
        if ($old !== '') {
            $oldPath = (defined('PUBLIC_PATH') ? PUBLIC_PATH : (ROOT_PATH . '/public')) . '/' . ltrim($old, '/');
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }
        $this->upsertSetting('company_logo', '');
        \SoftNova\Services\SettingsCache::invalidate();
        $this->respond(true, 'Logo eliminado', '/app/configuracion#documentos');
    }

    private function getSetting(string $key, string $default = ''): string
    {
        return \SoftNova\Services\SettingsCache::get($this->db, $key, $default);
    }

    private function requireAdminForExpenseCatalog(): bool
    {
        if (!TenantMiddleware::isManagement()) {
            $this->respond(false, 'Solo administradores pueden gestionar categorías de gasto', '/app/configuracion#gastos-admin');
            return false;
        }
        TenantMiddleware::authorize('configuracion', 'edit');
        return true;
    }

    private function saveExpenseCategory(): void
    {
        if (!$this->requireAdminForExpenseCatalog()) {
            return;
        }
        if (!$this->validateCsrfOrFail('/app/configuracion#gastos-admin')) {
            return;
        }
        try {
            $svc = new \SoftNova\Services\ExpenseCategoryService($this->db);
            $id = $svc->saveCategory([
                'id' => (int)$this->request->post('id', 0),
                'name' => (string)$this->request->post('name', ''),
                'kind' => (string)$this->request->post('kind', 'operational'),
                'account_code' => (string)$this->request->post('account_code', ''),
                'sort_order' => (int)$this->request->post('sort_order', 0),
            ]);
            $msg = (int)$this->request->post('id', 0) > 0 ? 'Categoría actualizada' : 'Categoría creada';
            $this->respond(true, $msg, '/app/configuracion#gastos-admin');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/configuracion#gastos-admin');
        }
    }

    private function deleteExpenseCategory(): void
    {
        if (!$this->requireAdminForExpenseCatalog()) {
            return;
        }
        if (!$this->validateCsrfOrFail('/app/configuracion#gastos-admin')) {
            return;
        }
        $id = (int)$this->request->post('id', 0);
        try {
            (new \SoftNova\Services\ExpenseCategoryService($this->db))->deactivate($id);
            $this->respond(true, 'Categoría eliminada del listado', '/app/configuracion#gastos-admin');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/configuracion#gastos-admin');
        }
    }

    private function moveExpenseCategory(): void
    {
        if (!$this->requireAdminForExpenseCatalog()) {
            return;
        }
        if (!$this->validateCsrfOrFail('/app/configuracion#gastos-admin')) {
            return;
        }
        $id = (int)$this->request->post('id', 0);
        $kind = (string)$this->request->post('kind', '');
        try {
            (new \SoftNova\Services\ExpenseCategoryService($this->db))->setKind($id, $kind);
            $svc = new \SoftNova\Services\ExpenseCategoryService($this->db);
            $label = $svc->resolveKindGroupLabel($kind);
            $this->respond(true, 'Movido a ' . $label, '/app/configuracion#gastos-admin');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/configuracion#gastos-admin');
        }
    }

    private function saveExpenseSubgroup(): void
    {
        if (!$this->requireAdminForExpenseCatalog()) {
            return;
        }
        if (!$this->validateCsrfOrFail('/app/configuracion#gastos-admin')) {
            return;
        }
        try {
            $svc = new \SoftNova\Services\ExpenseCategoryService($this->db);
            $id = $svc->saveSubgroup([
                'id' => (int)$this->request->post('id', 0),
                'name' => (string)$this->request->post('name', ''),
                'account_code' => (string)$this->request->post('account_code', ''),
            ]);
            $msg = (int)$this->request->post('id', 0) > 0 ? 'Sub-grupo actualizado' : 'Sub-grupo contable creado';
            $this->respond(true, $msg, '/app/configuracion#gastos-admin');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/configuracion#gastos-admin');
        }
    }

    private function deleteExpenseSubgroup(): void
    {
        if (!$this->requireAdminForExpenseCatalog()) {
            return;
        }
        if (!$this->validateCsrfOrFail('/app/configuracion#gastos-admin')) {
            return;
        }
        $id = (int)$this->request->post('id', 0);
        try {
            (new \SoftNova\Services\ExpenseCategoryService($this->db))->deactivateSubgroup($id);
            $this->respond(true, 'Sub-grupo ocultado del listado', '/app/configuracion#gastos-admin');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/configuracion#gastos-admin');
        }
    }
}
