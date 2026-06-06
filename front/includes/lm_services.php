<?php

function lm_fetch_all(string $node, string $sql, array $params = []): array
{
    $stmt = lm_pdo($node)->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function lm_fetch_one(string $node, string $sql, array $params = []): ?array
{
    $stmt = lm_pdo($node)->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function lm_execute(string $node, string $sql, array $params = []): int
{
    $stmt = lm_pdo($node)->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function lm_fetch_all_pdo(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function lm_fetch_one_pdo(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function lm_execute_pdo(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function lm_transaction_coordinator(array $nodes, callable $callback)
{
    $nodes = array_values(array_unique(array_filter(array_map([LmDatabase::class, 'canonicalNode'], $nodes))));
    $connections = [];

    try {
        foreach ($nodes as $node) {
            $connections[$node] = lm_pdo($node);
        }

        foreach ($connections as $pdo) {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }
        }

        $result = $callback($connections);

        foreach (array_reverse($connections, true) as $pdo) {
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
        }

        return $result;
    } catch (Throwable $e) {
        foreach (array_reverse($connections, true) as $pdo) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                    // Ignore rollback errors.
                }
            }
        }

        throw $e;
    }
}

function lm_catalogo_productos(bool $soloActivos = true): array
{
    try {
        $sql = 'SELECT id_prod, producto, descripcion, precio, activo FROM productos';
        if ($soloActivos) {
            $sql .= ' WHERE activo = 1';
        }

        $sql .= ' ORDER BY id_prod DESC';
        return lm_fetch_all('central', $sql);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return [];
    }
}

function lm_producto_por_id(int $idProd): ?array
{
    try {
        return lm_fetch_one('central', 'SELECT id_prod, producto, descripcion, precio, activo FROM productos WHERE id_prod = ?', [$idProd]);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return null;
    }
}

function lm_sync_stock_producto(int $idProd, string $nombre, ?float $precio = null): void
{
    foreach (['central', 'sucursal1', 'sucursal2'] as $node) {
        $pdo = lm_pdo($node);
        $row = lm_fetch_one($node, 'SELECT id_stock FROM stock WHERE id_prod = ? LIMIT 1', [$idProd]);

        if ($row) {
            lm_execute($node, 'UPDATE stock SET producto = ? WHERE id_prod = ?', [$nombre, $idProd]);
        } else {
            lm_execute($node, 'INSERT INTO stock (id_prod, producto, cantidad, stock_minimo) VALUES (?, ?, 0, 5)', [$idProd, $nombre]);
        }
    }
}

function lm_upsert_stock_producto_pdo(PDO $pdo, int $idProd, string $nombre): void
{
    $row = lm_fetch_one_pdo($pdo, 'SELECT id_stock FROM stock WHERE id_prod = ? LIMIT 1', [$idProd]);
    if ($row) {
        $stmt = $pdo->prepare('UPDATE stock SET producto = ? WHERE id_prod = ?');
        $stmt->execute([$nombre, $idProd]);
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO stock (id_prod, producto, cantidad, stock_minimo) VALUES (?, ?, 0, 5)');
    $stmt->execute([$idProd, $nombre]);
}

function lm_sucursal_operativa(int $idSuc): bool
{
    return in_array($idSuc, [2, 3], true);
}

function lm_reconciliar_stock_con_catalogo(): void
{
    $productos = lm_catalogo_productos(true);
    $idsActivos = array_map(static fn (array $producto): int => (int) $producto['id_prod'], $productos);

    foreach ($productos as $producto) {
        $idProd = (int) $producto['id_prod'];
        $nombre = (string) $producto['producto'];
        foreach (['sucursal1', 'sucursal2'] as $node) {
            try {
                $pdo = lm_pdo($node);
                lm_upsert_stock_producto_pdo($pdo, $idProd, $nombre);
            } catch (Throwable $e) {
                error_log($e->getMessage());
            }
        }
    }

    foreach (['central', 'sucursal1', 'sucursal2'] as $node) {
        try {
            $pdo = lm_pdo($node);
            if ($node === 'central') {
                $pdo->exec('DELETE FROM stock');
                continue;
            }

            if (empty($idsActivos)) {
                $pdo->exec('DELETE FROM stock');
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($idsActivos), '?'));
            $stmt = $pdo->prepare('DELETE FROM stock WHERE id_prod NOT IN (' . $placeholders . ')');
            $stmt->execute($idsActivos);
        } catch (Throwable $e) {
            error_log($e->getMessage());
        }
    }
}

function lm_guardar_producto(array $data): int
{
    $id = (int) ($data['id_prod'] ?? 0);
    $nombre = trim((string) ($data['producto'] ?? ''));
    $precio = (float) ($data['precio'] ?? 0);
    $descripcion = trim((string) ($data['descripcion'] ?? ''));

    if ($nombre === '' || $precio <= 0) {
        throw new InvalidArgumentException('Nombre y precio son obligatorios.');
    }

    return lm_transaction_coordinator(['central', 'sucursal1', 'sucursal2'], function (array $connections) use ($id, $nombre, $descripcion, $precio): int {
        $pdoCentral = $connections['central'];

        if ($id > 0) {
            $stmt = $pdoCentral->prepare('UPDATE productos SET producto = ?, descripcion = ?, precio = ? WHERE id_prod = ?');
            $stmt->execute([$nombre, $descripcion, $precio, $id]);
        } else {
            $stmt = $pdoCentral->prepare('INSERT INTO productos (producto, descripcion, precio, activo) VALUES (?, ?, ?, 1)');
            $stmt->execute([$nombre, $descripcion, $precio]);
            $id = (int) $pdoCentral->lastInsertId();
        }

        lm_upsert_stock_producto_pdo($connections['sucursal1'], $id, $nombre);
        lm_upsert_stock_producto_pdo($connections['sucursal2'], $id, $nombre);
        $stmt = $connections['central']->prepare('DELETE FROM stock WHERE id_prod = ?');
        $stmt->execute([$id]);

        return $id;
    });
}

function lm_desactivar_producto(int $idProd): void
{
    lm_transaction_coordinator(['central', 'sucursal1', 'sucursal2'], function (array $connections) use ($idProd): void {
        $stmt = $connections['central']->prepare('UPDATE productos SET activo = 0 WHERE id_prod = ?');
        $stmt->execute([$idProd]);

        foreach (['central', 'sucursal1', 'sucursal2'] as $node) {
            $stmt = $connections[$node]->prepare('DELETE FROM stock WHERE id_prod = ?');
            $stmt->execute([$idProd]);
        }
    });
}

function lm_clientes_listar(bool $soloActivos = true): array
{
    try {
        $sql = 'SELECT c.id_cli, c.cliente, c.rut, c.email, c.telefono, c.direccion, c.activo, COALESCE(u.rol, "cliente") AS rol'
             . ' FROM clientes c'
             . ' LEFT JOIN usuarios u ON u.email = c.email';
        if ($soloActivos) {
            $sql .= ' WHERE c.activo = 1';
        }

        return lm_fetch_all('central', $sql . ' ORDER BY c.id_cli DESC');
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return [];
    }
}

function lm_cliente_por_id(int $idCli): ?array
{
    try {
        return lm_fetch_one('central', 'SELECT id_cli, cliente, rut, email, telefono, direccion, activo FROM clientes WHERE id_cli = ?', [$idCli]);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return null;
    }
}

function lm_guardar_cliente(array $data): int
{
    $id = (int) ($data['id_cli'] ?? 0);
    $nombre = trim((string) ($data['cliente'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $telefono = trim((string) ($data['telefono'] ?? ''));
    $direccion = trim((string) ($data['direccion'] ?? ''));
    $rut = trim((string) ($data['rut'] ?? ''));
    $rol = (string) ($data['rol'] ?? 'cliente');
    $password = (string) ($data['password'] ?? '');

    if ($nombre === '' || $email === '') {
        throw new InvalidArgumentException('Nombre y correo son obligatorios.');
    }

    $pdo = lm_pdo('central');
    $pdo->beginTransaction();

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE clientes SET cliente = ?, rut = ?, email = ?, telefono = ?, direccion = ? WHERE id_cli = ?');
            $stmt->execute([$nombre, $rut, $email, $telefono, $direccion, $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO clientes (cliente, rut, email, telefono, direccion, activo) VALUES (?, ?, ?, ?, ?, 1)');
            $stmt->execute([$nombre, $rut, $email, $telefono, $direccion]);
            $id = (int) $pdo->lastInsertId();
        }

        if ($password !== '') {
            lm_guardar_usuario([
                'id_usuario' => 0,
                'nombre' => $nombre,
                'email' => $email,
                'password' => $password,
                'rol' => $rol,
                'activo' => 1,
            ], $pdo);
        } else {
            $usuario = lm_fetch_one_pdo($pdo, 'SELECT id_usuario FROM usuarios WHERE email = ? LIMIT 1', [$email]);
            if ($usuario) {
                $stmt = $pdo->prepare('UPDATE usuarios SET nombre = ?, rol = ?, activo = 1 WHERE email = ?');
                $stmt->execute([$nombre, $rol, $email]);
            }
        }

        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function lm_desactivar_cliente(int $idCli): void
{
    lm_execute('central', 'UPDATE clientes SET activo = 0 WHERE id_cli = ?', [$idCli]);
}

function lm_usuarios_listar(bool $soloActivos = true): array
{
    try {
        $sql = 'SELECT id_usuario, nombre, email, rol, activo FROM usuarios';
        if ($soloActivos) {
            $sql .= ' WHERE activo = 1';
        }

        return lm_fetch_all('central', $sql . ' ORDER BY id_usuario DESC');
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return [];
    }
}

function lm_usuario_por_email(string $email): ?array
{
    try {
        return lm_fetch_one('central', 'SELECT id_usuario, nombre, email, password, rol, activo FROM usuarios WHERE email = ? LIMIT 1', [$email]);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return null;
    }
}

function lm_guardar_usuario(array $data, ?PDO $pdo = null): int
{
    $id = (int) ($data['id_usuario'] ?? 0);
    $nombre = trim((string) ($data['nombre'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $rol = (string) ($data['rol'] ?? 'cliente');
    $activo = (int) ($data['activo'] ?? 1);

    if ($nombre === '' || $email === '') {
        throw new InvalidArgumentException('Nombre y correo son obligatorios.');
    }

    $hash = password_hash($password !== '' ? $password : 'LibreMercado123', PASSWORD_DEFAULT);
    $pdo = $pdo ?? lm_pdo('central');

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE usuarios SET nombre = ?, email = ?, rol = ?, activo = ?' . ($password !== '' ? ', password = ?' : '') . ' WHERE id_usuario = ?');
        $params = [$nombre, $email, $rol, $activo];
        if ($password !== '') {
            $params[] = $hash;
        }
        $params[] = $id;
        $stmt->execute($params);
        return $id;
    }

    $stmt = $pdo->prepare('INSERT INTO usuarios (nombre, email, password, rol, activo) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$nombre, $email, $hash, $rol, $activo]);
    return (int) $pdo->lastInsertId();
}

function lm_desactivar_usuario(int $idUsuario): void
{
    lm_execute('central', 'UPDATE usuarios SET activo = 0 WHERE id_usuario = ?', [$idUsuario]);
}

function lm_sucursales_listar(bool $soloActivas = true): array
{
    try {
        $sql = 'SELECT id_suc, sucursal, direccion, activo FROM sucursales';
        if ($soloActivas) {
            $sql .= ' WHERE activo = 1';
        }

        return lm_fetch_all('central', $sql . ' ORDER BY id_suc ASC');
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return [];
    }
}

function lm_sucursales_operativas(): array
{
    return array_values(array_filter(lm_sucursales_listar(true), static fn (array $sucursal): bool => lm_sucursal_operativa((int) $sucursal['id_suc'])));
}

function lm_sucursal_por_id(int $idSuc): ?array
{
    try {
        return lm_fetch_one('central', 'SELECT id_suc, sucursal, direccion, activo FROM sucursales WHERE id_suc = ?', [$idSuc]);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return null;
    }
}

function lm_guardar_sucursal(array $data): int
{
    $id = (int) ($data['id_suc'] ?? 0);
    $nombre = trim((string) ($data['sucursal'] ?? ''));
    $direccion = trim((string) ($data['direccion'] ?? ''));

    if ($nombre === '') {
        throw new InvalidArgumentException('El nombre de la sucursal es obligatorio.');
    }

    $pdo = lm_pdo('central');
    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE sucursales SET sucursal = ?, direccion = ? WHERE id_suc = ?');
        $stmt->execute([$nombre, $direccion, $id]);
        return $id;
    }

    $stmt = $pdo->prepare('INSERT INTO sucursales (sucursal, direccion, activo) VALUES (?, ?, 1)');
    $stmt->execute([$nombre, $direccion]);
    return (int) $pdo->lastInsertId();
}

function lm_desactivar_sucursal(int $idSuc): void
{
    lm_execute('central', 'UPDATE sucursales SET activo = 0 WHERE id_suc = ?', [$idSuc]);
}

function lm_proveedores_listar(bool $soloActivos = true): array
{
    try {
        $sql = 'SELECT id_proveedor, proveedor, email, telefono AS contacto, direccion, activo FROM proveedores';
        if ($soloActivos) {
            $sql .= ' WHERE activo = 1';
        }

        return lm_fetch_all('central', $sql . ' ORDER BY id_proveedor DESC');
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return [];
    }
}

function lm_proveedor_por_id(int $idProveedor): ?array
{
    try {
        return lm_fetch_one('central', 'SELECT id_proveedor, proveedor, email, telefono AS contacto, direccion, activo FROM proveedores WHERE id_proveedor = ?', [$idProveedor]);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return null;
    }
}

function lm_guardar_proveedor(array $data): int
{
    $id = (int) ($data['id_prov'] ?? 0);
    $nombre = trim((string) ($data['proveedor'] ?? ''));
    $contacto = trim((string) ($data['contacto'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $direccion = trim((string) ($data['direccion'] ?? ''));

    if ($nombre === '') {
        throw new InvalidArgumentException('El nombre del proveedor es obligatorio.');
    }

    $pdo = lm_pdo('central');
    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE proveedores SET proveedor = ?, email = ?, telefono = ?, direccion = ? WHERE id_proveedor = ?');
        $stmt->execute([$nombre, $email, $contacto, $direccion, $id]);
        return $id;
    }

    $stmt = $pdo->prepare('INSERT INTO proveedores (proveedor, email, telefono, direccion, activo) VALUES (?, ?, ?, ?, 1)');
    $stmt->execute([$nombre, $email, $contacto, $direccion]);
    return (int) $pdo->lastInsertId();
}

function lm_desactivar_proveedor(int $idProveedor): void
{
    lm_execute('central', 'UPDATE proveedores SET activo = 0 WHERE id_proveedor = ?', [$idProveedor]);
}

function lm_stock_todos(): array
{
    $mapa = [
        'sucursal1' => 'Sucursal La Serena',
        'sucursal2' => 'Sucursal Coquimbo',
    ];
    $resultados = [];

    foreach ($mapa as $node => $nombreSucursal) {
        try {
            $rows = lm_fetch_all($node, 'SELECT id_stock, id_prod, producto, cantidad, stock_minimo, actualizado_en FROM stock ORDER BY id_stock ASC');
        } catch (Throwable $e) {
            error_log($e->getMessage());
            continue;
        }
        foreach ($rows as $row) {
            $producto = lm_producto_por_id((int) $row['id_prod']);
            if (!$producto || (int) ($producto['activo'] ?? 0) !== 1) {
                continue;
            }

            $row['sucursal'] = $nombreSucursal;
            $row['producto'] = $producto['producto'];
            $row['precio'] = (float) ($producto['precio'] ?? 0);
            $row['nodo'] = $node;
            $row['alerta'] = (int) $row['cantidad'] <= (int) $row['stock_minimo'];
            $resultados[] = $row;
        }
    }

    return $resultados;
}

function lm_stock_por_nodo(string $node): array
{
    try {
        if (!in_array($node, ['sucursal1', 'sucursal2'], true)) {
            return [];
        }

        $rows = lm_fetch_all($node, 'SELECT id_stock, id_prod, producto, cantidad, stock_minimo, actualizado_en FROM stock ORDER BY id_stock ASC');
        $resultados = [];

        foreach ($rows as $row) {
            $producto = lm_producto_por_id((int) $row['id_prod']);
            if (!$producto || (int) ($producto['activo'] ?? 0) !== 1) {
                continue;
            }

            $row['producto'] = $producto['producto'];
            $resultados[] = $row;
        }

        return $resultados;
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return [];
    }
}

function lm_stock_actualizar(int $idSuc, int $idProd, int $cantidad, string $motivo = 'Ajuste manual'): void
{
    if (!lm_sucursal_operativa($idSuc)) {
        throw new RuntimeException('La sede central no administra stock operativo.');
    }

    $node = LmDatabase::stockNodeForSucursal($idSuc);
    $pdo = lm_pdo($node);
    $producto = lm_producto_por_id($idProd);

    if (!$producto) {
        throw new RuntimeException('El producto no existe.');
    }

    $pdo->beginTransaction();
    try {
        $row = lm_fetch_one_pdo($pdo, 'SELECT id_stock, cantidad FROM stock WHERE id_prod = ? FOR UPDATE', [$idProd]);
        if ($row) {
            $stmt = $pdo->prepare('UPDATE stock SET cantidad = ? , producto = ? WHERE id_prod = ?');
            $stmt->execute([$cantidad, $producto['producto'], $idProd]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO stock (id_prod, producto, cantidad, stock_minimo) VALUES (?, ?, ?, 5)');
            $stmt->execute([$idProd, $producto['producto'], $cantidad]);
        }

        $stmt = $pdo->prepare('INSERT INTO movimientos_stock (id_prod, tipo, cantidad, motivo) VALUES (?, "ajuste", ?, ?)');
        $stmt->execute([$idProd, abs($cantidad), $motivo]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function lm_carrito_activo(int $idCli): array
{
    $carrito = lm_fetch_one('central', "SELECT id_carrito, id_cli, fecha_creacion, estado FROM carrito WHERE id_cli = ? AND estado = 'activo' ORDER BY id_carrito DESC LIMIT 1", [$idCli]);

    if (!$carrito) {
        $pdo = lm_pdo('central');
        $stmt = $pdo->prepare("INSERT INTO carrito (id_cli, estado) VALUES (?, 'activo')");
        $stmt->execute([$idCli]);
        $carrito = [
            'id_carrito' => (int) $pdo->lastInsertId(),
            'id_cli' => $idCli,
            'fecha_creacion' => date('Y-m-d H:i:s'),
            'estado' => 'activo',
        ];
    }

    $carrito['items'] = lm_fetch_all('central', 'SELECT dc.id_detalle_carrito, dc.id_prod, dc.cantidad, dc.precio_unitario, p.producto FROM detalle_carrito dc INNER JOIN productos p ON p.id_prod = dc.id_prod WHERE dc.id_carrito = ? ORDER BY dc.id_detalle_carrito ASC', [$carrito['id_carrito']]);
    $carrito['total'] = 0;

    foreach ($carrito['items'] as $item) {
        $carrito['total'] += ((float) $item['precio_unitario']) * ((int) $item['cantidad']);
    }

    return $carrito;
}

function lm_carrito_agregar_item(int $idCli, int $idProd, int $cantidad): array
{
    $cantidad = max(1, $cantidad);
    $producto = lm_producto_por_id($idProd);

    if (!$producto || (int) ($producto['activo'] ?? 0) !== 1) {
        throw new RuntimeException('El producto no está disponible.');
    }

    $pdo = lm_pdo('central');
    $pdo->beginTransaction();

    try {
        $carrito = lm_fetch_one_pdo($pdo, "SELECT id_carrito FROM carrito WHERE id_cli = ? AND estado = 'activo' ORDER BY id_carrito DESC LIMIT 1", [$idCli]);
        if (!$carrito) {
            $stmt = $pdo->prepare("INSERT INTO carrito (id_cli, estado) VALUES (?, 'activo')");
            $stmt->execute([$idCli]);
            $idCarrito = (int) $pdo->lastInsertId();
        } else {
            $idCarrito = (int) $carrito['id_carrito'];
        }

        $detalle = lm_fetch_one_pdo($pdo, 'SELECT id_detalle_carrito, cantidad FROM detalle_carrito WHERE id_carrito = ? AND id_prod = ? LIMIT 1', [$idCarrito, $idProd]);
        if ($detalle) {
            $stmt = $pdo->prepare('UPDATE detalle_carrito SET cantidad = ?, precio_unitario = ? WHERE id_detalle_carrito = ?');
            $stmt->execute([((int) $detalle['cantidad']) + $cantidad, $producto['precio'], $detalle['id_detalle_carrito']]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO detalle_carrito (id_carrito, id_prod, cantidad, precio_unitario) VALUES (?, ?, ?, ?)');
            $stmt->execute([$idCarrito, $idProd, $cantidad, $producto['precio']]);
        }

        $pdo->commit();
        return lm_carrito_activo($idCli);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function lm_carrito_eliminar_item(int $idCli, int $idProd): array
{
    $carrito = lm_carrito_activo($idCli);
    lm_execute('central', 'DELETE FROM detalle_carrito WHERE id_carrito = ? AND id_prod = ?', [$carrito['id_carrito'], $idProd]);
    return lm_carrito_activo($idCli);
}

function lm_carrito_vaciar(int $idCli): void
{
    $carrito = lm_carrito_activo($idCli);
    $pdo = lm_pdo('central');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM detalle_carrito WHERE id_carrito = ?');
        $stmt->execute([$carrito['id_carrito']]);
        $stmt = $pdo->prepare("UPDATE carrito SET estado = 'cancelado' WHERE id_carrito = ?");
        $stmt->execute([$carrito['id_carrito']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function lm_venta_por_id(int $idVenta): ?array
{
    try {
        return lm_fetch_one('central', 'SELECT v.id_venta, v.id_cli, v.id_suc, v.fecha_venta, v.total, v.estado, c.cliente, s.sucursal FROM ventas v INNER JOIN clientes c ON c.id_cli = v.id_cli INNER JOIN sucursales s ON s.id_suc = v.id_suc WHERE v.id_venta = ?', [$idVenta]);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return null;
    }
}

function lm_venta_detalle(int $idVenta): array
{
    try {
        return lm_fetch_all('central', 'SELECT dv.id_detalle_venta, dv.id_prod, p.producto, dv.cantidad, dv.precio_unitario, dv.subtotal FROM detalle_ventas dv INNER JOIN productos p ON p.id_prod = dv.id_prod WHERE dv.id_venta = ? ORDER BY dv.id_detalle_venta ASC', [$idVenta]);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return [];
    }
}

function lm_ventas_listar(): array
{
    try {
        return lm_fetch_all('central', 'SELECT v.id_venta, c.cliente, s.sucursal, v.total, v.fecha_venta AS fecha, v.estado FROM ventas v INNER JOIN clientes c ON c.id_cli = v.id_cli INNER JOIN sucursales s ON s.id_suc = v.id_suc ORDER BY v.fecha_venta DESC');
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return [];
    }
}

function lm_ventas_listar_por_cliente(int $idCli): array
{
    try {
        return lm_fetch_all('central', 'SELECT v.id_venta, c.cliente, s.sucursal, v.total, v.fecha_venta AS fecha, v.estado FROM ventas v INNER JOIN clientes c ON c.id_cli = v.id_cli INNER JOIN sucursales s ON s.id_suc = v.id_suc WHERE v.id_cli = ? ORDER BY v.fecha_venta DESC', [$idCli]);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return [];
    }
}

function lm_registrar_venta(int $idCli, int $idSuc, array $items, ?int $idCarrito = null): array
{
    if (empty($items)) {
        throw new RuntimeException('El carrito está vacío.');
    }

    if (!lm_sucursal_operativa($idSuc)) {
        throw new RuntimeException('La sede central no puede registrar ventas operativas.');
    }

    $productoIds = [];
    $total = 0.0;
    foreach ($items as $item) {
        $cantidad = max(1, (int) ($item['cantidad'] ?? 0));
        $precio = (float) ($item['precio_unitario'] ?? $item['precio'] ?? 0);
        $total += $cantidad * $precio;
        $productoIds[] = (int) ($item['id_prod'] ?? 0);
    }

    $stockNode = LmDatabase::stockNodeForSucursal($idSuc);
    $nodes = array_unique(['central', $stockNode]);

    return lm_transaction_coordinator($nodes, function (array $connections) use ($idCli, $idSuc, $items, $idCarrito, $stockNode, $total) {
        $pdoCentral = $connections['central'];
        $pdoStock = $connections[$stockNode] ?? $pdoCentral;

        $cliente = lm_fetch_one_pdo($pdoCentral, 'SELECT id_cli FROM clientes WHERE id_cli = ? AND activo = 1', [$idCli]);
        $sucursal = lm_fetch_one_pdo($pdoCentral, 'SELECT id_suc FROM sucursales WHERE id_suc = ? AND activo = 1', [$idSuc]);
        if (!$cliente || !$sucursal) {
            throw new RuntimeException('Cliente o sucursal inválidos.');
        }

        $stmt = $pdoCentral->prepare('INSERT INTO ventas (id_cli, id_suc, total, estado) VALUES (?, ?, ?, "pendiente")');
        $stmt->execute([$idCli, $idSuc, $total]);
        $idVenta = (int) $pdoCentral->lastInsertId();

        foreach ($items as $item) {
            $idProd = (int) ($item['id_prod'] ?? 0);
            $cantidad = max(1, (int) ($item['cantidad'] ?? 0));
            $precioUnitario = (float) ($item['precio_unitario'] ?? $item['precio'] ?? 0);
            $subtotal = $cantidad * $precioUnitario;

            $producto = lm_producto_por_id($idProd);
            if (!$producto || (int) ($producto['activo'] ?? 0) !== 1) {
                throw new RuntimeException('Uno de los productos ya no está disponible.');
            }

            $stmt = $pdoCentral->prepare('INSERT INTO detalle_ventas (id_venta, id_prod, cantidad, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$idVenta, $idProd, $cantidad, $precioUnitario, $subtotal]);

            $stockRow = lm_fetch_one_pdo($pdoStock, 'SELECT id_stock, cantidad FROM stock WHERE id_prod = ? FOR UPDATE', [$idProd]);
            if (!$stockRow) {
                throw new RuntimeException('No existe stock para el producto ' . $producto['producto']);
            }

            if ((int) $stockRow['cantidad'] < $cantidad) {
                throw new RuntimeException('Stock insuficiente para ' . $producto['producto']);
            }

            $stmt = $pdoStock->prepare('UPDATE stock SET cantidad = cantidad - ?, producto = ? WHERE id_prod = ?');
            $stmt->execute([$cantidad, $producto['producto'], $idProd]);

            $stmt = $pdoStock->prepare('INSERT INTO movimientos_stock (id_prod, tipo, cantidad, motivo) VALUES (?, "salida", ?, ?)');
            $stmt->execute([$idProd, $cantidad, 'Venta #' . $idVenta]);
        }

        if ($idCarrito) {
            $stmt = $pdoCentral->prepare("UPDATE carrito SET estado = 'pagado' WHERE id_carrito = ?");
            $stmt->execute([$idCarrito]);
        }

        $stmt = $pdoCentral->prepare("UPDATE ventas SET estado = 'confirmada' WHERE id_venta = ?");
        $stmt->execute([$idVenta]);

        return [
            'id_venta' => $idVenta,
            'total' => $total,
        ];
    });
}

function lm_registrar_compra(int $idProveedor, int $idSuc, int $idProd, int $cantidad, float $precioUnitario): array
{
    if ($cantidad <= 0 || $precioUnitario < 0) {
        throw new RuntimeException('Cantidad o precio inválidos.');
    }

    if (!lm_sucursal_operativa($idSuc)) {
        throw new RuntimeException('La sede central no puede registrar compras operativas.');
    }

    $stockNode = LmDatabase::stockNodeForSucursal($idSuc);
    $nodes = array_unique(['central', $stockNode]);

    return lm_transaction_coordinator($nodes, function (array $connections) use ($idProveedor, $idSuc, $idProd, $cantidad, $precioUnitario, $stockNode) {
        $pdoCentral = $connections['central'];
        $pdoStock = $connections[$stockNode] ?? $pdoCentral;

        $proveedor = lm_fetch_one_pdo($pdoCentral, 'SELECT id_proveedor FROM proveedores WHERE id_proveedor = ? AND activo = 1', [$idProveedor]);
        $sucursal = lm_fetch_one_pdo($pdoCentral, 'SELECT id_suc FROM sucursales WHERE id_suc = ? AND activo = 1', [$idSuc]);
        $producto = lm_producto_por_id($idProd);

        if (!$proveedor || !$sucursal || !$producto || (int) ($producto['activo'] ?? 0) !== 1) {
            throw new RuntimeException('Proveedor, sucursal o producto inválido.');
        }

        $total = $cantidad * $precioUnitario;
        $stmt = $pdoCentral->prepare('INSERT INTO compras (id_proveedor, id_suc, total) VALUES (?, ?, ?)');
        $stmt->execute([$idProveedor, $idSuc, $total]);
        $idCompra = (int) $pdoCentral->lastInsertId();

        $stmt = $pdoCentral->prepare('INSERT INTO detalle_compras (id_compra, id_prod, cantidad, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$idCompra, $idProd, $cantidad, $precioUnitario, $total]);

        $row = lm_fetch_one_pdo($pdoStock, 'SELECT id_stock, cantidad FROM stock WHERE id_prod = ? FOR UPDATE', [$idProd]);
        if ($row) {
            $stmt = $pdoStock->prepare('UPDATE stock SET cantidad = cantidad + ?, producto = ? WHERE id_prod = ?');
            $stmt->execute([$cantidad, $producto['producto'], $idProd]);
        } else {
            $stmt = $pdoStock->prepare('INSERT INTO stock (id_prod, producto, cantidad, stock_minimo) VALUES (?, ?, ?, 5)');
            $stmt->execute([$idProd, $producto['producto'], $cantidad]);
        }

        $stmt = $pdoStock->prepare('INSERT INTO movimientos_stock (id_prod, tipo, cantidad, motivo) VALUES (?, "entrada", ?, ?)');
        $stmt->execute([$idProd, $cantidad, 'Compra #' . $idCompra]);

        return [
            'id_compra' => $idCompra,
            'total' => $total,
        ];
    });
}

function lm_compras_listar(): array
{
    try {
        return lm_fetch_all(
            'central',
            'SELECT c.id_compra, p.proveedor, COALESCE(GROUP_CONCAT(DISTINCT pr.producto ORDER BY pr.producto SEPARATOR ", "), "Sin producto") AS producto, SUM(dc.cantidad) AS cantidad, s.sucursal, c.total, c.fecha_compra AS fecha '
            . 'FROM compras c '
            . 'INNER JOIN proveedores p ON p.id_proveedor = c.id_proveedor '
            . 'INNER JOIN sucursales s ON s.id_suc = c.id_suc '
            . 'LEFT JOIN detalle_compras dc ON dc.id_compra = c.id_compra '
            . 'LEFT JOIN productos pr ON pr.id_prod = dc.id_prod '
            . 'GROUP BY c.id_compra, p.proveedor, s.sucursal, c.total, c.fecha_compra '
            . 'ORDER BY c.fecha_compra DESC'
        );
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return [];
    }
}

function lm_compra_detalle(int $idCompra): array
{
    try {
        return lm_fetch_all('central', 'SELECT dc.id_detalle_compra, dc.id_prod, p.producto, dc.cantidad, dc.precio_unitario, dc.subtotal FROM detalle_compras dc INNER JOIN productos p ON p.id_prod = dc.id_prod WHERE dc.id_compra = ? ORDER BY dc.id_detalle_compra ASC', [$idCompra]);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return [];
    }
}

function lm_dashboard_stats(): array
{
    try {
        $hoy = date('Y-m-d');
        $productos = lm_fetch_one('central', 'SELECT COUNT(*) AS total FROM productos WHERE activo = 1');
        $clientes = lm_fetch_one('central', 'SELECT COUNT(*) AS total FROM clientes WHERE activo = 1');
        $ventasHoy = lm_fetch_one('central', 'SELECT COUNT(*) AS total FROM ventas WHERE DATE(fecha_venta) = ?', [$hoy]);
        $sucursales = lm_fetch_one('central', 'SELECT COUNT(*) AS total FROM sucursales WHERE activo = 1');

        return [
            'productos' => (int) ($productos['total'] ?? 0),
            'clientes' => (int) ($clientes['total'] ?? 0),
            'ventas_hoy' => (int) ($ventasHoy['total'] ?? 0),
            'sucursales' => (int) ($sucursales['total'] ?? 0),
        ];
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return ['productos' => 0, 'clientes' => 0, 'ventas_hoy' => 0, 'sucursales' => 0];
    }
}

function lm_dashboard_ventas_recientes(int $limite = 5): array
{
    try {
        $limite = max(1, $limite);
        return lm_fetch_all('central', 'SELECT v.id_venta, c.cliente, s.sucursal, v.total, v.estado, v.fecha_venta AS fecha FROM ventas v INNER JOIN clientes c ON c.id_cli = v.id_cli INNER JOIN sucursales s ON s.id_suc = v.id_suc ORDER BY v.fecha_venta DESC LIMIT ' . $limite);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return [];
    }
}

function lm_dashboard_stock_bajo(): array
{
    try {
        $rows = lm_stock_todos();
        return array_values(array_filter($rows, static fn (array $row): bool => (int) $row['cantidad'] <= (int) $row['stock_minimo']));
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return [];
    }
}
