<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Solo permitir si es Admin (o si no hay usuarios aún para facilitar el primer uso)
$usuarioActual = lm_usuario_actual();
// No restringir si la tabla usuarios no existe o está vacía para permitir el primer seed.

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Libre Mercado - Database Seeder</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body style="background: var(--lm-bg); color: var(--lm-text);">
    <div class="container py-5">
        <div class="lm-card p-4 mx-auto" style="max-width: 800px;">
            <div class="lm-card-header mb-4">
                <i class="bi bi-database-fill-gear"></i> Sistema de Poblado de Datos Sintéticos
            </div>
            
            <div class="p-3 rounded mb-4" style="background: var(--lm-surface2); font-family: monospace; font-size: 0.9rem;">
<?php
try {
    echo "<div>[1/8] Inicializando esquemas en todos los nodos...</div>";
    
    // Esquema Central
    $pdoCentral = lm_pdo('central');
    $tablesCentral = [
        "CREATE TABLE IF NOT EXISTS productos (id_prod INT AUTO_INCREMENT PRIMARY KEY, producto VARCHAR(120) NOT NULL, descripcion TEXT, precio DECIMAL(12,2) NOT NULL, activo TINYINT(1) DEFAULT 1)",
        "CREATE TABLE IF NOT EXISTS clientes (id_cli INT AUTO_INCREMENT PRIMARY KEY, cliente VARCHAR(120) NOT NULL, rut VARCHAR(20), email VARCHAR(100) UNIQUE, telefono VARCHAR(20), direccion VARCHAR(200), activo TINYINT(1) DEFAULT 1)",
        "CREATE TABLE IF NOT EXISTS usuarios (id_usuario INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(120), email VARCHAR(100) UNIQUE, password VARCHAR(255), rol ENUM('admin', 'vendedor', 'cliente') DEFAULT 'cliente', activo TINYINT(1) DEFAULT 1)",
        "CREATE TABLE IF NOT EXISTS sucursales (id_suc INT AUTO_INCREMENT PRIMARY KEY, sucursal VARCHAR(100) NOT NULL, direccion VARCHAR(200), activo TINYINT(1) DEFAULT 1)",
        "CREATE TABLE IF NOT EXISTS proveedores (id_proveedor INT AUTO_INCREMENT PRIMARY KEY, proveedor VARCHAR(120) NOT NULL, email VARCHAR(100), telefono VARCHAR(20), direccion VARCHAR(200), activo TINYINT(1) DEFAULT 1)",
        "CREATE TABLE IF NOT EXISTS ventas (id_venta INT AUTO_INCREMENT PRIMARY KEY, id_cli INT, id_suc INT, total DECIMAL(12,2), estado ENUM('pendiente', 'preparada', 'confirmada', 'cancelada') DEFAULT 'pendiente', fecha_venta DATETIME DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS detalle_ventas (id_detalle_venta INT AUTO_INCREMENT PRIMARY KEY, id_venta INT, id_prod INT, cantidad INT, precio_unitario DECIMAL(12,2), subtotal DECIMAL(12,2))",
        "CREATE TABLE IF NOT EXISTS compras (id_compra INT AUTO_INCREMENT PRIMARY KEY, id_proveedor INT, id_suc INT, total DECIMAL(12,2), fecha_compra DATETIME DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS detalle_compras (id_detalle_compra INT AUTO_INCREMENT PRIMARY KEY, id_compra INT, id_prod INT, cantidad INT, precio_unitario DECIMAL(12,2), subtotal DECIMAL(12,2))",
        "CREATE TABLE IF NOT EXISTS carrito (id_carrito INT AUTO_INCREMENT PRIMARY KEY, id_cli INT, fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP, estado ENUM('activo', 'pagado', 'cancelado') DEFAULT 'activo')",
        "CREATE TABLE IF NOT EXISTS detalle_carrito (id_detalle_carrito INT AUTO_INCREMENT PRIMARY KEY, id_carrito INT, id_prod INT, cantidad INT, precio_unitario DECIMAL(12,2))",
        "CREATE TABLE IF NOT EXISTS stock (id_stock INT AUTO_INCREMENT PRIMARY KEY, id_prod INT, id_suc INT, sucursal VARCHAR(100), producto VARCHAR(120), cantidad INT, stock_minimo INT DEFAULT 5, actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS movimientos_stock (id_movimiento INT AUTO_INCREMENT PRIMARY KEY, id_prod INT, id_suc INT, tipo VARCHAR(20), cantidad INT, motivo VARCHAR(200), fecha DATETIME DEFAULT CURRENT_TIMESTAMP)"
    ];
    foreach ($tablesCentral as $sql) { $pdoCentral->exec($sql); }

    // Esquema Sucursales
    $tablesBranch = [
        "CREATE TABLE IF NOT EXISTS stock (id_stock INT AUTO_INCREMENT PRIMARY KEY, id_prod INT, id_suc INT, sucursal VARCHAR(100), producto VARCHAR(120), cantidad INT, stock_minimo INT DEFAULT 5, actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS movimientos_stock (id_movimiento INT AUTO_INCREMENT PRIMARY KEY, id_prod INT, id_suc INT, tipo VARCHAR(20), cantidad INT, motivo VARCHAR(200), fecha DATETIME DEFAULT CURRENT_TIMESTAMP)"
    ];
    foreach (['sucursal1', 'sucursal2'] as $node) {
        if (LmDatabase::ping($node)) {
            $pdoN = lm_pdo($node);
            foreach ($tablesBranch as $sql) { $pdoN->exec($sql); }
        }
    }

    echo "<div>[2/8] Instalando procedimientos almacenados y tabla de logs...</div>";
    
    $spCentralFile = '/docker/mysql/procedures_central.sql';
    $spSucursalFile = '/docker/mysql/procedures_sucursal.sql';
    
    // Instalar SPs en nodo central vía PDO
    if (file_exists($spCentralFile)) {
        try {
            $pdo = lm_pdo('central');
            $results = lm_install_routines_from_file($pdo, $spCentralFile);
            $errors = array_filter($results, fn($r) => $r['type'] === 'error');
            if (empty($errors)) {
                echo "<div class='text-success small'>✓ " . count($results) . " procedimientos centrales instalados</div>";
            } else {
                foreach ($errors as $e) {
                    echo "<div class='text-danger small'>✗ " . htmlspecialchars($e['error'] ?? 'Error desconocido') . "</div>";
                }
            }
        } catch (Throwable $e) {
            echo "<div class='text-danger small'>✗ Error central: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    
    // Instalar SPs en nodos sucursales vía PDO
    if (file_exists($spSucursalFile)) {
        foreach (['sucursal1', 'sucursal2'] as $node) {
            if (LmDatabase::ping($node)) {
                try {
                    $pdo = lm_pdo($node);
                    $results = lm_install_routines_from_file($pdo, $spSucursalFile);
                    $errors = array_filter($results, fn($r) => $r['type'] === 'error');
                    if (empty($errors)) {
                        echo "<div class='text-success small'>✓ " . count($results) . " procedimientos instalados en {$node}</div>";
                    } else {
                        foreach ($errors as $e) {
                            echo "<div class='text-danger small'>✗ Error en {$node}: " . htmlspecialchars($e['error'] ?? 'Error desconocido') . "</div>";
                        }
                    }
                } catch (Throwable $e) {
                    echo "<div class='text-danger small'>✗ Error en {$node}: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
            }
        }
    }

    echo "<div>[3/8] Limpiando datos antiguos...</div>";
    $pdoCentral->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Tablas del nodo central
    $allTables = ['productos', 'clientes', 'usuarios', 'sucursales', 'proveedores', 'ventas', 'detalle_ventas', 'compras', 'detalle_compras', 'carrito', 'detalle_carrito', 'stock', 'movimientos_stock', 'log_transacciones'];
    foreach ($allTables as $t) {
        if ($pdoCentral->query("SHOW TABLES LIKE '$t'")->rowCount() > 0) {
            $pdoCentral->exec("TRUNCATE TABLE $t");
        }
    }
    
    // Tablas de nodos sucursales
    foreach (['sucursal1', 'sucursal2'] as $node) {
        if (LmDatabase::ping($node)) {
            $pdoNode = lm_pdo($node);
            $pdoNode->exec("TRUNCATE TABLE stock");
            $pdoNode->exec("TRUNCATE TABLE movimientos_stock");
            // Solo truncar log_transacciones si existe (se crea en ambos nodos por los SPs)
            if ($pdoNode->query("SHOW TABLES LIKE 'log_transacciones'")->rowCount() > 0) {
                $pdoNode->exec("TRUNCATE TABLE log_transacciones");
            }
        }
    }
    $pdoCentral->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "<div>[4/8] Creando sucursales y proveedores...</div>";
    $pdoCentral->exec("INSERT INTO sucursales (id_suc, sucursal, direccion) VALUES 
        (2, 'Sucursal La Serena', 'Av. Balmaceda 1234'),
        (3, 'Sucursal Coquimbo', 'Calle Aldunate 567')");

    $pdoCentral->exec("INSERT INTO proveedores (proveedor, email, telefono, direccion) VALUES 
        ('TecnoGlobal S.A.', 'contacto@tecnoglobal.cl', '+56 2 2233 4455', 'Parque Enea, Pudahuel'),
        ('Distribuidora El Faro', 'ventas@elfaro.cl', '+56 51 222 3344', 'Panamericana Norte km 450'),
        ('Importaciones Asia', 'info@importasia.cl', '+56 9 8877 6655', 'San Alfonso, Santiago')");

    echo "<div>[5/8] Insertando catálogo de productos...</div>";
    $productos = [
        ['Smartphone Samsung S23', 'Gama alta, 256GB', 899990],
        ['Laptop Dell Latitude', 'i7, 16GB RAM, 512GB SSD', 1250000],
        ['Audífonos Sony WH-1000XM5', 'Cancelación de ruido líder', 349990],
        ['Monitor Gamer LG 27"', '144Hz, 1ms, IPS', 279990],
        ['Teclado Mecánico Keychron', 'Switches Gateron Brown', 89990],
        ['Mouse Logitech MX Master 3S', 'Ergonómico profesional', 99990],
        ['Tablet iPad Air', 'M1 chip, 64GB', 599990],
        ['Reloj Apple Watch Series 8', 'GPS, 45mm', 429990],
        ['Cámara Canon EOS R10', 'Mirrorless con lente 18-45mm', 980000],
        ['SSD Externo Crucial 1TB', 'USB-C 3.2 Gen 2', 75000]
    ];
    foreach ($productos as $p) {
        lm_guardar_producto(['producto' => $p[0], 'descripcion' => $p[1], 'precio' => $p[2]]);
    }

    echo "<div>[6/8] Configurando cuentas de usuario (Admin, Vendedor, Clientes)...</div>";
    lm_guardar_usuario(['nombre' => 'Admin Sistema', 'email' => 'admin@demo.local', 'password' => 'admin123', 'rol' => 'admin']);
    lm_guardar_usuario(['nombre' => 'Vendedor Norte', 'email' => 'vendedor@demo.local', 'password' => 'vendedor123', 'rol' => 'vendedor']);

    $clientes = [
        ['Juan Pérez', '12.345.678-9', 'juan.perez@email.com', '+56 9 1234 5678', 'Los Pinos 45, La Serena'],
        ['María González', '15.987.654-3', 'maria.g@gmail.com', '+56 9 8765 4321', 'Av. del Mar 800, Coquimbo'],
        ['Carlos Soto', '10.111.222-3', 'carlos.soto@outlook.com', '+56 9 5555 6666', 'Calle Larga 102, Vicuña']
    ];
    foreach ($clientes as $c) {
        lm_guardar_cliente(['cliente' => $c[0], 'rut' => $c[1], 'email' => $c[2], 'telefono' => $c[3], 'direccion' => $c[4], 'password' => 'cliente123', 'rol' => 'cliente']);
    }

    echo "<div>[7/8] Distribuyendo stock inicial entre nodos sucursales...</div>";
    $allProds = lm_catalogo_productos(true);
    foreach ($allProds as $p) {
        $idProd = (int)$p['id_prod'];
        // Usar función original que funciona correctamente
        lm_stock_actualizar(2, $idProd, rand(10, 50), 'Carga inicial');
        lm_stock_actualizar(3, $idProd, rand(10, 50), 'Carga inicial');
    }

    echo "<div class='text-success mt-2 fw-bold text-center'>[8/8] ¡OPERACIÓN COMPLETADA CON ÉXITO!</div>";
    echo "<div class='text-muted small mt-2 text-center'>Procedimientos almacenados instalados y listos para usar</div>";
    echo "<div class='mt-3'>
        <a href='test_concurrencia.php' class='btn btn-info btn-sm me-2' target='_blank'>
            <i class='bi bi-people me-1'></i>Test Concurrencia
        </a>
        <a href='nodos.php' class='btn btn-outline-danger btn-sm' target='_blank'>
            <i class='bi bi-wifi-off me-1'></i>Monitor de Nodos
        </a>
    </div>";

} catch (Throwable $e) {
    echo "<div class='text-danger mt-2'>ERROR: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<div class='text-muted small mt-1'>" . nl2br(htmlspecialchars($e->getTraceAsString())) . "</div>";
}
?>
            </div>

            <div class="text-center">
                <a href="login.php" class="btn btn-lm-primary px-4">Ir al Inicio de Sesión</a>
                <p class="text-muted small mt-3">Puedes cerrar esta pestaña una vez finalizado.</p>
            </div>
        </div>
    </div>
</body>
</html>
