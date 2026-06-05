<?php
/**
 * Libre Mercado - Configuración de Base de Datos
 * Sistemas Distribuidos
 * -----------------------------------------------
 * Modifica los valores de cada nodo según tu entorno Apache/MySQL.
 */

// ─── NODO PRINCIPAL (Ventas / Productos) ────────────────────────────────────
define('DB_HOST_PRINCIPAL', 'localhost');
define('DB_PORT_PRINCIPAL', '3306');
define('DB_NAME_PRINCIPAL', 'libre_mercado_principal');
define('DB_USER_PRINCIPAL', 'root');
define('DB_PASS_PRINCIPAL', '');

// ─── NODO SECUNDARIO (Stock / Sucursales) ────────────────────────────────────
define('DB_HOST_SUCURSALES', 'localhost');
define('DB_PORT_SUCURSALES', '3307');
define('DB_NAME_SUCURSALES', 'libre_mercado_sucursales');
define('DB_USER_SUCURSALES', 'root');
define('DB_PASS_SUCURSALES', '');

// ─── NODO TERCIARIO (Proveedores / Compras) ─────────────────────────────────
define('DB_HOST_PROVEEDORES', 'localhost');
define('DB_PORT_PROVEEDORES', '3308');
define('DB_NAME_PROVEEDORES', 'libre_mercado_proveedores');
define('DB_USER_PROVEEDORES', 'root');
define('DB_PASS_PROVEEDORES', '');

// ─── FUNCIÓN DE CONEXIÓN PDO ─────────────────────────────────────────────────
/**
 * Crea una conexión PDO al nodo especificado.
 * @param string $nodo 'principal' | 'sucursales' | 'proveedores'
 * @return PDO|null
 */
function conectarNodo(string $nodo = 'principal'): ?PDO {
    $configs = [
        'principal'   => [DB_HOST_PRINCIPAL,   DB_PORT_PRINCIPAL,   DB_NAME_PRINCIPAL,   DB_USER_PRINCIPAL,   DB_PASS_PRINCIPAL],
        'sucursales'  => [DB_HOST_SUCURSALES,  DB_PORT_SUCURSALES,  DB_NAME_SUCURSALES,  DB_USER_SUCURSALES,  DB_PASS_SUCURSALES],
        'proveedores' => [DB_HOST_PROVEEDORES, DB_PORT_PROVEEDORES, DB_NAME_PROVEEDORES, DB_USER_PROVEEDORES, DB_PASS_PROVEEDORES],
    ];

    if (!isset($configs[$nodo])) return null;

    [$host, $port, $dbname, $user, $pass] = $configs[$nodo];

    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        // En producción: registrar en log en lugar de mostrar el error.
        error_log("Error nodo [$nodo]: " . $e->getMessage());
        return null;
    }
}

// ─── VERIFICAR ESTADO DE NODOS ───────────────────────────────────────────────
function estadoNodos(): array {
    $nodos = ['principal', 'sucursales', 'proveedores'];
    $estado = [];
    foreach ($nodos as $n) {
        $pdo = conectarNodo($n);
        $estado[$n] = ($pdo !== null) ? 'online' : 'offline';
    }
    return $estado;
}

// ─── SESIÓN ──────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
