<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;
use SoftNova\Core\Database;
use SoftNova\Core\TenantDatabase;
use function SoftNova\Core\redirect;

/**
 * Controlador de autenticación para usuarios de tenants
 */
class TenantAuthController extends Controller
{
    /**
     * Mostrar formulario de login para tenant
     */
    public function login(): void
    {
        $this->view('tenant.login');
    }
    
    /**
     * Procesar login de usuario tenant
     */
    public function authenticate(): void
    {
        if (!$this->checkRateLimit()) {
            $_SESSION['tenant_error'] = 'Demasiados intentos. Intente de nuevo en 15 minutos.';
            redirect('/app/login');
            return;
        }
        
        if (!$this->validateCsrf()) {
            $_SESSION['tenant_error'] = 'Token de seguridad invalido o expirado';
            redirect('/app/login');
            return;
        }
        
        $email = $this->request->post('email');
        $password = $this->request->post('password');
        
        if (empty($email) || empty($password)) {
            $_SESSION['tenant_error'] = 'Por favor ingrese email y contraseña';
            redirect('/app/login');
            return;
        }

        if (\SoftNova\Core\is_standalone()) {
            $this->authenticateStandalone((string)$email, (string)$password);
            return;
        }
        
        // Buscar el usuario en la tabla tenant_users de la BD maestra
        $masterDb = Database::getInstance();
        
        $user = $masterDb->query(
            "SELECT tu.*, t.company_name, t.database_name, t.database_user, t.database_password,
                    t.status as tenant_status, t.id as tenant_id, t.company_name as tenant_name,
                    sp.modules as plan_modules
             FROM tenant_users tu
             JOIN tenants t ON tu.tenant_id = t.id
             LEFT JOIN subscription_plans sp ON t.subscription_plan_id = sp.id
             WHERE tu.email = ? AND tu.status = 'active' AND t.status = 'active'
             LIMIT 1",
            [$email]
        )->fetch();
        
        if (!$user || !password_verify($password, $user['password'])) {
            $this->incrementRateLimit();
            $_SESSION['tenant_error'] = 'Credenciales incorrectas o cuenta suspendida';
            redirect('/app/login');
            return;
        }
        
        $this->clearRateLimit();
        
        // Verificar que la BD del tenant existe y sincronizar usuario
        try {
            $dbPassStored = (string)($user['database_password'] ?? '');
            $dbPassPlain = \SoftNova\Core\Security::revealSecret($dbPassStored);
            // Migración lazy: cifrar passwords legados en claro.
            if ($dbPassPlain !== '' && !\SoftNova\Core\Security::isSealed($dbPassStored)) {
                try {
                    $masterDb->query(
                        "UPDATE tenants SET database_password = ? WHERE id = ?",
                        [\SoftNova\Core\Security::sealSecret($dbPassPlain), (int)$user['tenant_id']]
                    );
                } catch (\Throwable $e) {
                    error_log('tenant password seal: ' . $e->getMessage());
                }
            }

            $tenantDb = TenantDatabase::getInstance();
            $tenantConn = $tenantDb->getTenantConnection(
                $user['database_name'],
                $user['database_user'],
                $dbPassPlain
            );
            
            // Sincronizar usuario en la BD del tenant (para FKs de cash_sessions, sales, etc.)
            $masterRole = (string)($user['role'] ?? 'admin');
            $localRole = \SoftNova\Core\TenantMiddleware::normalizeRole($masterRole);
            // En BD local legacy, 'user' y 'pos' deben existir en ENUM
            $this->ensureTenantUserSchema($tenantConn);
            $permissionsJson = is_string($user['permissions'] ?? null)
                ? (string)$user['permissions']
                : json_encode($user['permissions'] ?? []);
            if ($localRole === 'pos') {
                $permissionsJson = json_encode(\SoftNova\Core\TenantMiddleware::posDefaultPermissions());
            }
            
            $stmt = $tenantConn->prepare(
                "SELECT id, role, permissions FROM users WHERE email = ? LIMIT 1"
            );
            $stmt->execute([$user['email']]);
            $localUser = $stmt->fetch();
            
            if ($localUser) {
                $localUserId = (int)$localUser['id'];
                $preserveTeamProfile = in_array(
                    \SoftNova\Core\TenantMiddleware::normalizeRole((string)($localUser['role'] ?? '')),
                    ['user', 'pos'],
                    true
                );
                try {
                    if ($preserveTeamProfile) {
                        // Usuarios gestionados en Config: no pisar rol/permisos al iniciar sesión.
                        $tenantConn->prepare(
                            "UPDATE users SET name = ?, password = ?, status = 'active' WHERE id = ?"
                        )->execute([$user['name'], $user['password'], $localUserId]);
                    } else {
                        $tenantConn->prepare(
                            "UPDATE users SET name = ?, role = ?, password = ?, permissions = ?, status = 'active' WHERE id = ?"
                        )->execute([$user['name'], $localRole, $user['password'], $permissionsJson, $localUserId]);
                    }
                } catch (\Throwable $e) {
                    $tenantConn->prepare(
                        "UPDATE users SET name = ?, password = ?, status = 'active' WHERE id = ?"
                    )->execute([$user['name'], $user['password'], $localUserId]);
                }
            } else {
                try {
                    $tenantConn->prepare(
                        "INSERT INTO users (name, email, password, role, permissions, status) VALUES (?, ?, ?, ?, ?, 'active')"
                    )->execute([$user['name'], $user['email'], $user['password'], $localRole, $permissionsJson]);
                } catch (\Throwable $e) {
                    $tenantConn->prepare(
                        "INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, ?, 'active')"
                    )->execute([$user['name'], $user['email'], $user['password'], $localRole]);
                }
                $localUserId = $tenantConn->lastInsertId();
            }
        } catch (\Exception $e) {
            $_SESSION['tenant_error'] = 'Error de conexión con la base de datos del sistema';
            redirect('/app/login');
            return;
        }
        
        session_regenerate_id(true);
        
        // Guardar sesión del tenant (usar ID local de la BD del tenant)
        $_SESSION['tenant_user_id'] = $localUserId;
        $_SESSION['tenant_master_user_id'] = (int)$user['id'];
        $_SESSION['tenant_user_name'] = $user['name'];
        $_SESSION['tenant_user_email'] = $user['email'];
        $_SESSION['tenant_id'] = $user['tenant_id'];
        $_SESSION['tenant_name'] = $user['company_name'];
        $_SESSION['tenant_db_name'] = $user['database_name'];
        $_SESSION['tenant_authenticated'] = true;
        
        // Guardar módulos del plan para filtrar sidebar
        $planModules = json_decode($user['plan_modules'] ?? '[]', true) ?: [];
        $_SESSION['tenant_modules'] = $planModules;

        $sessionRole = $localRole;
        $sessionPermissions = $user['permissions'] ?? null;
        try {
            $localStmt = $tenantConn->prepare('SELECT role, permissions FROM users WHERE id = ? LIMIT 1');
            $localStmt->execute([(int)$localUserId]);
            $localRow = $localStmt->fetch();
            if ($localRow) {
                $sessionRole = (string)($localRow['role'] ?? $localRole);
                $sessionPermissions = $localRow['permissions'] ?? $sessionPermissions;
            }
        } catch (\Throwable $e) {
            error_log('login local permissions: ' . $e->getMessage());
        }

        \SoftNova\Core\TenantMiddleware::hydrateSessionPermissions($sessionRole, $sessionPermissions);
        
        // Actualizar último login
        $masterDb->query(
            "UPDATE tenant_users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?",
            [$this->request->ip(), $user['id']]
        );
        
        redirect(\SoftNova\Core\TenantMiddleware::homePath());
    }

    /**
     * Login directo contra tabla users (instalación de un solo cliente).
     */
    private function authenticateStandalone(string $email, string $password): void
    {
        try {
            $db = Database::getInstance()->getConnection();
            $this->ensureTenantUserSchema($db);
            $stmt = $db->prepare(
                "SELECT id, name, email, password, role, status, permissions
                 FROM users WHERE email = ? AND status = 'active' LIMIT 1"
            );
            try {
                $stmt->execute([$email]);
                $user = $stmt->fetch();
            } catch (\Throwable $e) {
                $stmt = $db->prepare(
                    "SELECT id, name, email, password, role, status
                     FROM users WHERE email = ? AND status = 'active' LIMIT 1"
                );
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                if ($user) {
                    $user['permissions'] = null;
                }
            }
        } catch (\Throwable $e) {
            error_log('Standalone login DB: ' . $e->getMessage());
            $_SESSION['tenant_error'] = 'Error de conexión con la base de datos';
            redirect('/app/login');
            return;
        }

        if (!$user || !password_verify($password, (string)$user['password'])) {
            $this->incrementRateLimit();
            $_SESSION['tenant_error'] = 'Credenciales incorrectas o cuenta suspendida';
            redirect('/app/login');
            return;
        }

        $this->clearRateLimit();
        session_regenerate_id(true);

        $company = (string)\SoftNova\Core\config('app.company_name', \SoftNova\Core\config('app.name', 'Seri ERP'));
        $dbName = (string)\SoftNova\Core\config('database.default.database', '');

        $_SESSION['tenant_user_id'] = (int)$user['id'];
        $_SESSION['tenant_master_user_id'] = (int)$user['id'];
        $_SESSION['tenant_user_name'] = $user['name'];
        $_SESSION['tenant_user_email'] = $user['email'];
        $_SESSION['tenant_id'] = 1;
        $_SESSION['tenant_name'] = $company;
        $_SESSION['tenant_db_name'] = $dbName;
        $_SESSION['tenant_authenticated'] = true;
        $_SESSION['tenant_modules'] = [];

        \SoftNova\Core\TenantMiddleware::hydrateSessionPermissions(
            $user['role'] ?: 'admin',
            $user['permissions'] ?? null
        );

        try {
            $db->prepare('UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?')
                ->execute([$this->request->ip(), (int)$user['id']]);
        } catch (\Throwable $e) {
            // ignore
        }

        redirect(\SoftNova\Core\TenantMiddleware::homePath());
    }
    
    /**
     * Asegura ENUM de roles + columna permissions en users
     */
    private function ensureTenantUserSchema(\PDO $pdo): void
    {
        try {
            $pdo->exec(
                "ALTER TABLE users
                 MODIFY COLUMN role ENUM('admin','manager','cashier','viewer','auxiliar','user','pos','mesero') DEFAULT 'admin'"
            );
        } catch (\Throwable $e) {
            // Ya actualizado o sin permisos ALTER
        }
        try {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'permissions'")->fetch();
            if (!$col) {
                $pdo->exec("ALTER TABLE users ADD COLUMN permissions LONGTEXT NULL AFTER role");
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /** @deprecated use ensureTenantUserSchema */
    private function ensureTenantUserRoleEnum(\PDO $pdo): void
    {
        $this->ensureTenantUserSchema($pdo);
    }
    
    private function checkRateLimit(): bool
    {
        $ip = $this->request->ip();
        $data = $this->readLoginRateLimit($ip);
        if ($data === null) {
            return true;
        }
        if (time() - (int)$data['first_attempt'] > 900) {
            $this->clearRateLimit();
            return true;
        }
        return (int)$data['count'] < 5;
    }

    private function incrementRateLimit(): void
    {
        $ip = $this->request->ip();
        $data = $this->readLoginRateLimit($ip) ?? ['count' => 0, 'first_attempt' => time()];
        if (time() - (int)$data['first_attempt'] > 900) {
            $data = ['count' => 0, 'first_attempt' => time()];
        }
        $data['count'] = (int)$data['count'] + 1;
        $this->writeLoginRateLimit($ip, $data);
        // Mantener mirror en sesión por compatibilidad.
        $_SESSION['tenant_rate_limit_' . md5($ip)] = $data;
    }

    private function clearRateLimit(): void
    {
        $ip = $this->request->ip();
        unset($_SESSION['tenant_rate_limit_' . md5($ip)]);
        $file = $this->loginRateLimitPath($ip);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** @return array{count:int,first_attempt:int}|null */
    private function readLoginRateLimit(string $ip): ?array
    {
        $file = $this->loginRateLimitPath($ip);
        if (is_readable($file)) {
            $raw = @file_get_contents($file);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded) && isset($decoded['count'], $decoded['first_attempt'])) {
                return [
                    'count' => (int)$decoded['count'],
                    'first_attempt' => (int)$decoded['first_attempt'],
                ];
            }
        }
        $sessionKey = 'tenant_rate_limit_' . md5($ip);
        $fromSession = $_SESSION[$sessionKey] ?? null;
        if (is_array($fromSession) && isset($fromSession['count'], $fromSession['first_attempt'])) {
            return [
                'count' => (int)$fromSession['count'],
                'first_attempt' => (int)$fromSession['first_attempt'],
            ];
        }
        return null;
    }

    /** @param array{count:int,first_attempt:int} $data */
    private function writeLoginRateLimit(string $ip, array $data): void
    {
        $dir = $this->loginRateLimitDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $this->loginRateLimitPath($ip);
        @file_put_contents($file, json_encode([
            'count' => (int)$data['count'],
            'first_attempt' => (int)$data['first_attempt'],
            'ip' => $ip,
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function loginRateLimitDir(): string
    {
        $base = defined('STORAGE_PATH') ? STORAGE_PATH : (dirname(__DIR__, 2) . '/storage');
        return $base . '/cache/login_rate';
    }

    private function loginRateLimitPath(string $ip): string
    {
        return $this->loginRateLimitDir() . '/' . hash('sha256', $ip) . '.json';
    }
    
    /**
     * Cerrar sesión del tenant
     */
    public function logout(): void
    {
        unset(
            $_SESSION['tenant_user_id'],
            $_SESSION['tenant_master_user_id'],
            $_SESSION['tenant_user_name'],
            $_SESSION['tenant_user_email'],
            $_SESSION['tenant_user_role'],
            $_SESSION['tenant_permissions'],
            $_SESSION['tenant_permissions_map'],
            $_SESSION['tenant_id'],
            $_SESSION['tenant_name'],
            $_SESSION['tenant_db_name'],
            $_SESSION['tenant_db_user'],
            $_SESSION['tenant_db_pass'],
            $_SESSION['tenant_db_name_production'],
            $_SESSION['tenant_authenticated'],
            $_SESSION['csrf_token'],
            $_SESSION['sandbox_mode'],
            $_SESSION['sandbox_db_name']
        );

        $loginJs = json_encode(\SoftNova\Core\base_url('/app/login'), JSON_UNESCAPED_SLASHES);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Cerrando sesión…</title></head><body style="font-family:system-ui,sans-serif;padding:2rem;text-align:center;color:#334155">'
            . '<p>Cerrando sesión y limpiando datos locales…</p>'
            . '<script>(function(){'
            . 'var go=function(){location.replace(' . $loginJs . ');};'
            . 'var keys=["seri_pos_pending_sales","seri_pos_conflict_sales","seri_pos_offline_meta","eva_cart"];'
            . 'try{keys.forEach(function(k){localStorage.removeItem(k);});}catch(e){}'
            . 'var done=function(){go();};'
            . 'var tasks=[];'
            . 'if(window.indexedDB&&indexedDB.deleteDatabase){'
            . 'tasks.push(new Promise(function(res){try{var r=indexedDB.deleteDatabase("seri_pos_offline");r.onsuccess=r.onerror=r.onblocked=function(){res();};}catch(e){res();}}));'
            . '}'
            . 'if(window.caches&&caches.keys){'
            . 'tasks.push(caches.keys().then(function(ks){return Promise.all(ks.filter(function(k){return k.indexOf("seri-pos-")===0;}).map(function(k){return caches.delete(k);}));}).catch(function(){}));'
            . '}'
            . 'if(navigator.serviceWorker&&navigator.serviceWorker.controller){'
            . 'try{navigator.serviceWorker.controller.postMessage({type:"SERI_CLEAR_SENSITIVE"});}catch(e){}'
            . '}'
            . 'Promise.all(tasks).then(done).catch(done);'
            . 'setTimeout(go,2500);'
            . '})();</script></body></html>';
        exit;
    }
}
