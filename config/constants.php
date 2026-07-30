<?php

/**
 * Constantes del sistema
 */

// Estados de usuario
define('USER_STATUS_ACTIVE', 'active');
define('USER_STATUS_INACTIVE', 'inactive');
define('USER_STATUS_SUSPENDED', 'suspended');
define('USER_STATUS_BLOCKED', 'blocked');

// Estados de tenant
define('TENANT_STATUS_ACTIVE', 'active');
define('TENANT_STATUS_SUSPENDED', 'suspended');
define('TENANT_STATUS_CANCELLED', 'cancelled');

// Estados de suscripción
define('SUBSCRIPTION_STATUS_ACTIVE', 'active');
define('SUBSCRIPTION_STATUS_EXPIRED', 'expired');
define('SUBSCRIPTION_STATUS_CANCELLED', 'cancelled');
define('SUBSCRIPTION_STATUS_PENDING', 'pending');

// Roles
define('ROLE_SUPER_ADMIN', 'super_admin');
define('ROLE_ADMIN', 'admin');
define('ROLE_MANAGER', 'manager');
define('ROLE_USER', 'user');

// Módulos del sistema
define('MODULE_DASHBOARD', 'dashboard');
define('MODULE_CAJA', 'caja');
define('MODULE_VENTAS', 'ventas');
define('MODULE_INVENTARIO', 'inventario');
define('MODULE_CLIENTES', 'clientes');
define('MODULE_PROVEEDORES', 'proveedores');
define('MODULE_COTIZACIONES', 'cotizaciones');
define('MODULE_GASTOS', 'gastos');
define('MODULE_CONTABILIDAD', 'contabilidad');
define('MODULE_NOMINA', 'nomina');
define('MODULE_REPORTES', 'reportes');
