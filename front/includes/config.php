<?php

// --- Configuración de Sesión Multitab ---
// Permitir múltiples sesiones en el mismo navegador usando un parámetro 'token' en la URL.
// Si no hay token, usa la sesión por defecto.
$sessionToken = $_GET['token'] ?? ($_POST['token'] ?? 'DEFAULT');
session_name('LM_SESS_' . preg_replace('/[^a-zA-Z0-9]/', '', $sessionToken));

// Configurar cookies para que expiren al cerrar el navegador
session_set_cookie_params([
    'lifetime' => 0, 
    'path' => '/',
    'domain' => '',
    'secure' => false, // Cambiar a true si usas HTTPS
    'httponly' => true,
    'samesite' => 'Lax'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Agrega el token de sesión actual a una URL para mantener el contexto multitab.
 */
function lm_url(string $url): string {
    global $sessionToken;
    if ($sessionToken === 'DEFAULT') return $url;
    
    $query = parse_url($url, PHP_URL_QUERY);
    $sep = $query ? '&' : '?';
    return $url . $sep . 'token=' . urlencode($sessionToken);
}

// Detectar si estamos en el entorno Docker de producción/desarrollo
$isDocker = file_exists('/.dockerenv');

define('LM_DB_HOST_CENTRAL', getenv('LM_DB_HOST_CENTRAL') ?: ($isDocker ? 'mysql-central' : '127.0.0.1'));
define('LM_DB_PORT_CENTRAL', getenv('LM_DB_PORT_CENTRAL') ?: ($isDocker ? '3306' : '3307'));
define('LM_DB_NAME_CENTRAL', getenv('LM_DB_NAME_CENTRAL') ?: 'libremercado_central');

define('LM_DB_HOST_SUCURSAL1', getenv('LM_DB_HOST_SUCURSAL1') ?: ($isDocker ? 'mysql-sucursal1' : '127.0.0.1'));
define('LM_DB_PORT_SUCURSAL1', getenv('LM_DB_PORT_SUCURSAL1') ?: ($isDocker ? '3306' : '3308'));
define('LM_DB_NAME_SUCURSAL1', getenv('LM_DB_NAME_SUCURSAL1') ?: 'libremercado_sucursal1');

define('LM_DB_HOST_SUCURSAL2', getenv('LM_DB_HOST_SUCURSAL2') ?: ($isDocker ? 'mysql-sucursal2' : '127.0.0.1'));
define('LM_DB_PORT_SUCURSAL2', getenv('LM_DB_PORT_SUCURSAL2') ?: ($isDocker ? '3306' : '3309'));
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
