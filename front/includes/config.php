<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('LM_DB_HOST_CENTRAL', getenv('LM_DB_HOST_CENTRAL') ?: (PHP_SAPI === 'cli' ? '127.0.0.1' : 'mysql-central'));
define('LM_DB_PORT_CENTRAL', getenv('LM_DB_PORT_CENTRAL') ?: (PHP_SAPI === 'cli' ? '3307' : '3306'));
define('LM_DB_NAME_CENTRAL', getenv('LM_DB_NAME_CENTRAL') ?: 'libremercado_central');

define('LM_DB_HOST_SUCURSAL1', getenv('LM_DB_HOST_SUCURSAL1') ?: (PHP_SAPI === 'cli' ? '127.0.0.1' : 'mysql-sucursal1'));
define('LM_DB_PORT_SUCURSAL1', getenv('LM_DB_PORT_SUCURSAL1') ?: (PHP_SAPI === 'cli' ? '3308' : '3306'));
define('LM_DB_NAME_SUCURSAL1', getenv('LM_DB_NAME_SUCURSAL1') ?: 'libremercado_sucursal1');

define('LM_DB_HOST_SUCURSAL2', getenv('LM_DB_HOST_SUCURSAL2') ?: (PHP_SAPI === 'cli' ? '127.0.0.1' : 'mysql-sucursal2'));
define('LM_DB_PORT_SUCURSAL2', getenv('LM_DB_PORT_SUCURSAL2') ?: (PHP_SAPI === 'cli' ? '3309' : '3306'));
define('LM_DB_NAME_SUCURSAL2', getenv('LM_DB_NAME_SUCURSAL2') ?: 'libremercado_sucursal2');

define('LM_DB_USER', getenv('LM_DB_USER') ?: 'root');
define('LM_DB_PASS', getenv('LM_DB_PASS') ?: 'root');

// Compatibilidad con la nomenclatura previa.
define('DB_HOST_PRINCIPAL', LM_DB_HOST_CENTRAL);
define('DB_PORT_PRINCIPAL', LM_DB_PORT_CENTRAL);
define('DB_NAME_PRINCIPAL', LM_DB_NAME_CENTRAL);
define('DB_USER_PRINCIPAL', LM_DB_USER);
define('DB_PASS_PRINCIPAL', LM_DB_PASS);

define('DB_HOST_SUCURSALES', LM_DB_HOST_SUCURSAL1);
define('DB_PORT_SUCURSALES', LM_DB_PORT_SUCURSAL1);
define('DB_NAME_SUCURSALES', LM_DB_NAME_SUCURSAL1);
define('DB_USER_SUCURSALES', LM_DB_USER);
define('DB_PASS_SUCURSALES', LM_DB_PASS);

define('DB_HOST_PROVEEDORES', LM_DB_HOST_SUCURSAL2);
define('DB_PORT_PROVEEDORES', LM_DB_PORT_SUCURSAL2);
define('DB_NAME_PROVEEDORES', LM_DB_NAME_SUCURSAL2);
define('DB_USER_PROVEEDORES', LM_DB_USER);
define('DB_PASS_PROVEEDORES', LM_DB_PASS);

require_once __DIR__ . '/lm_database.php';
require_once __DIR__ . '/lm_services.php';
