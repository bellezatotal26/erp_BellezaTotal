<?php
/**
 * Test CLI: canAccess / canDo para admin, User POS y User con permisos custom.
 *
 * Uso: php database/test_permissions.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

use SoftNova\Core\TenantMiddleware;

$failed = 0;
$passed = 0;

function assertTrue(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) {
        echo "PASS  {$msg}\n";
        $passed++;
    } else {
        echo "FAIL  {$msg}\n";
        $failed++;
    }
}

// --- Admin ---
$_SESSION = [];
TenantMiddleware::hydrateSessionPermissions('admin', null);
assertTrue(TenantMiddleware::canAccess('configuracion'), 'admin accede a configuracion');
assertTrue(TenantMiddleware::canAccess('ventas'), 'admin accede a ventas');
assertTrue(TenantMiddleware::canDo('delete', 'ventas'), 'admin puede delete ventas');
assertTrue(TenantMiddleware::canDo('export', 'inventario'), 'admin puede export inventario');

// --- User POS ---
$_SESSION = [];
TenantMiddleware::hydrateSessionPermissions('pos', null);
assertTrue(TenantMiddleware::canAccess('caja'), 'pos accede a caja');
assertTrue(TenantMiddleware::canAccess('ventas'), 'pos accede a ventas');
assertTrue(TenantMiddleware::canDo('create', 'ventas'), 'pos puede create ventas');
assertTrue(!TenantMiddleware::canAccess('configuracion'), 'pos NO accede a configuracion');
assertTrue(!TenantMiddleware::canAccess('contabilidad'), 'pos NO accede a contabilidad');
assertTrue(!TenantMiddleware::canAccess('gastos'), 'pos NO accede a gastos');
assertTrue(!TenantMiddleware::canAccess('compras'), 'pos NO accede a compras');
assertTrue(!TenantMiddleware::canAccess('clientes'), 'pos NO accede a clientes');
assertTrue(!TenantMiddleware::canDo('delete', 'ventas'), 'pos NO puede delete ventas');
assertTrue(TenantMiddleware::homePath() === '/app/caja', 'pos home = /app/caja');
assertTrue(TenantMiddleware::roleDisplayLabel('admin') === 'Administrativo', 'label admin = Administrativo');
assertTrue(TenantMiddleware::roleDisplayLabel('pos') === 'POS', 'label pos = POS');

// --- User custom ---
$_SESSION = [];
$perms = [
    'ventas' => ['view' => true, 'create' => true, 'edit' => false, 'delete' => false, 'export' => true],
    'inventario' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false, 'export' => false],
];
TenantMiddleware::hydrateSessionPermissions('user', $perms);
assertTrue(TenantMiddleware::canAccess('ventas'), 'user custom: ventas view');
assertTrue(TenantMiddleware::canDo('create', 'ventas'), 'user custom: ventas create');
assertTrue(TenantMiddleware::canDo('export', 'ventas'), 'user custom: ventas export');
assertTrue(!TenantMiddleware::canDo('delete', 'ventas'), 'user custom: NO delete ventas');
assertTrue(TenantMiddleware::canAccess('inventario'), 'user custom: inventario view');
assertTrue(!TenantMiddleware::canDo('create', 'inventario'), 'user custom: NO create inventario');
assertTrue(!TenantMiddleware::canAccess('caja'), 'user custom: NO caja');
assertTrue(!TenantMiddleware::canAccess('configuracion'), 'user custom: NO configuracion');

echo "\nResultado: {$passed} pass, {$failed} fail\n";
exit($failed > 0 ? 1 : 0);
