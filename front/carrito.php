<?php
require_once 'includes/header.php';

/**
 * CARRITO Y VENTAS — carrito.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Aquí ocurre la transacción ACID distribuida más importante:
 *   1. Crear registro en 'ventas' (nodo principal)
 *   2. Crear 'detalle_ventas' por cada ítem (nodo principal)
 *   3. Descontar stock en 'stock' (nodo sucursales)
 *   Si cualquier paso falla → ROLLBACK en ambos nodos (2PC simulado)
 *
 * TODO BD - Transacción ACID:
 *   $pdoPrincipal  = conectarNodo('principal');
 *   $pdoSucursales = conectarNodo('sucursales');
 *
 *   try {
 *       $pdoPrincipal->beginTransaction();
 *       $pdoSucursales->beginTransaction();
 *
 *       // Fase 1: Insertar venta
 *       $stmt = $pdoPrincipal->prepare("INSERT INTO ventas (id_cli, id_suc, total, fecha) VALUES (?,?,?,NOW())");
 *       $stmt->execute([$id_cli, $id_suc, $total]);
 *       $id_venta = $pdoPrincipal->lastInsertId();
 *
 *       // Fase 2: Insertar detalle
 *       foreach ($carrito as $item) {
 *           $pdoPrincipal->prepare("INSERT INTO detalle_ventas (id_venta, id_prod, cantidad, precio_unit)
 *                                   VALUES (?,?,?,?)")->execute([...]);
 *       }
 *
 *       // Fase 3: Descontar stock (nodo sucursales)
 *       foreach ($carrito as $item) {
 *           $stmt = $pdoSucursales->prepare("UPDATE stock SET cantidad = cantidad - ?
 *                                            WHERE id_suc = ? AND id_prod = ? AND cantidad >= ?");
 *           $stmt->execute([$item['cantidad'], $id_suc, $item['id_prod'], $item['cantidad']]);
 *           if ($stmt->rowCount() === 0) throw new Exception("Stock insuficiente: " . $item['producto']);
 *       }
 *
 *       // Commit ambos nodos
 *       $pdoPrincipal->commit();
 *       $pdoSucursales->commit();
 *
 *   } catch (Exception $e) {
 *       $pdoPrincipal->rollBack();
 *       $pdoSucursales->rollBack();
 *       // Mostrar error al usuario
 *   }
 */

$mensaje = ''; $tipoMsg = '';
$usuario = lm_usuario_actual();
$usaDbCarrito = $usuario && (($usuario['rol'] ?? '') === 'cliente') && !empty($usuario['id_cli']);

if (!isset($_SESSION['carrito'])) {
    $_SESSION['carrito'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'agregar') {
    $id_prod  = intval($_POST['id_prod'] ?? 0);
    $cantidad = intval($_POST['cantidad'] ?? 1);

    try {
        if ($usaDbCarrito) {
            lm_carrito_agregar_item((int) $usuario['id_cli'], $id_prod, $cantidad);
            $mensaje = 'Producto agregado al carrito.';
            $tipoMsg = 'success';
        } elseif ($id_prod && $cantidad > 0) {
            if (isset($_SESSION['carrito'][$id_prod])) {
                $_SESSION['carrito'][$id_prod]['cantidad'] += $cantidad;
            } else {
                $producto = lm_producto_por_id($id_prod);
                $_SESSION['carrito'][$id_prod] = [
                    'id_prod' => $id_prod,
                    'nombre' => $producto['producto'] ?? ($_POST['nombre_prod'] ?? ''),
                    'precio' => (float) ($producto['precio'] ?? ($_POST['precio_prod'] ?? 0)),
                    'cantidad' => $cantidad,
                ];
            }
            $mensaje = 'Producto agregado al carrito.';
            $tipoMsg = 'success';
        }
    } catch (Throwable $e) {
        $mensaje = $e->getMessage();
        $tipoMsg = 'danger';
    }
}

if (isset($_GET['quitar'])) {
    try {
        if ($usaDbCarrito) {
            lm_carrito_eliminar_item((int) $usuario['id_cli'], intval($_GET['quitar']));
        } else {
            unset($_SESSION['carrito'][intval($_GET['quitar'])]);
        }
        $mensaje = 'Producto removido.';
        $tipoMsg = 'warning';
    } catch (Throwable $e) {
        $mensaje = $e->getMessage();
        $tipoMsg = 'danger';
    }
}

if (isset($_GET['vaciar'])) {
    try {
        if ($usaDbCarrito) {
            lm_carrito_vaciar((int) $usuario['id_cli']);
        } else {
            $_SESSION['carrito'] = [];
        }
        $mensaje = 'Carrito vaciado.';
        $tipoMsg = 'warning';
    } catch (Throwable $e) {
        $mensaje = $e->getMessage();
        $tipoMsg = 'danger';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'confirmar') {
    $id_cli = intval($_POST['id_cli'] ?? ($usuario['id_cli'] ?? 0));
    $id_suc = intval($_POST['id_suc'] ?? 0);

    try {
        if ($usaDbCarrito) {
            $carritoDb = lm_carrito_activo((int) $usuario['id_cli']);
            if (empty($carritoDb['items'])) {
                throw new RuntimeException('El carrito está vacío.');
            }
            lm_registrar_venta((int) $usuario['id_cli'], $id_suc, $carritoDb['items'], (int) $carritoDb['id_carrito']);
        } else {
            $carritoSesion = array_values($_SESSION['carrito'] ?? []);
            $items = array_map(static function (array $item): array {
                return [
                    'id_prod' => (int) $item['id_prod'],
                    'cantidad' => (int) $item['cantidad'],
                    'precio_unitario' => (float) $item['precio'],
                ];
            }, $carritoSesion);
            if ($id_cli && $id_suc && !empty($items)) {
                lm_registrar_venta($id_cli, $id_suc, $items);
                $_SESSION['carrito'] = [];
            } else {
                throw new RuntimeException('Selecciona cliente, sucursal y al menos un producto.');
            }
        }

        $mensaje = '¡Venta registrada exitosamente! Transacción ACID completada.';
        $tipoMsg = 'success';
    } catch (Throwable $e) {
        $mensaje = $e->getMessage();
        $tipoMsg = 'danger';
    }
}

$productos  = lm_catalogo_productos(true);
$clientes   = lm_clientes_listar(true);
$sucursales = lm_sucursales_listar(true); // Listar todas para mostrar estado

if ($usaDbCarrito) {
    $carritoDb = lm_carrito_activo((int) $usuario['id_cli']);
    $carrito = $carritoDb['items'];
    $total = (float) $carritoDb['total'];
} else {
    $carrito = array_values($_SESSION['carrito']);
    $total = array_sum(array_map(static fn($i) => ((float) $i['precio']) * ((int) $i['cantidad']), $carrito));
}
?>

<div class="lm-page">
<div class="container-fluid">

    <div class="lm-page-header lm-fade-up">
        <div>
            <h1><span>Carrito</span> y Ventas</h1>
            <p>Proceso de venta con transacción ACID distribuida</p>
        </div>
        <a href="ventas.php" class="btn-lm-ghost btn"><i class="bi bi-receipt me-1"></i>Historial de Ventas</a>
    </div>

    <!-- Banner ACID -->
    <div class="alert alert-info mb-4 lm-fade-up">
        <i class="bi bi-shield-check me-2"></i>
        <strong>Transacción ACID:</strong> Al confirmar, el sistema realiza un commit en 2 fases —
        registra la venta en el nodo principal y descuenta el stock en el nodo de sucursales de forma atómica.
        Si algún nodo falla, se ejecuta <strong>rollback automático</strong>.
    </div>

    <?php if ($mensaje): ?>
    <div class="alert alert-<?= $tipoMsg ?> alert-auto alert-dismissible fade show mb-4">
        <?= $mensaje ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Agregar productos al carrito -->
        <div class="col-lg-5">
            <div class="lm-card lm-fade-up">
                <div class="lm-card-header"><i class="bi bi-search"></i> Seleccionar Producto</div>
                <div class="lm-card-body">
                    <form method="POST">
                        <input type="hidden" name="accion" value="agregar">
                        <div class="mb-3">
                            <label class="lm-form-label">Producto</label>
                            <select name="id_prod" id="selProducto" class="lm-input form-select" required>
                                <option value="">Seleccionar producto...</option>
                                <?php foreach ($productos as $p): ?>
                                    <option value="<?= $p['id_prod'] ?>"
                                        data-nombre="<?= htmlspecialchars($p['producto']) ?>"
                                        data-precio="<?= $p['precio'] ?>">
                                        <?= htmlspecialchars($p['producto']) ?> — $<?= number_format($p['precio'],2) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="nombre_prod" id="inpNombreProd">
                            <input type="hidden" name="precio_prod" id="inpPrecioProd">
                        </div>
                        <div class="mb-3">
                            <label class="lm-form-label">Cantidad</label>
                            <input type="number" name="cantidad" class="lm-input form-control" value="1" min="1">
                        </div>
                        <button type="submit" class="btn-lm-primary btn w-100">
                            <i class="bi bi-cart-plus me-1"></i>Agregar al Carrito
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Carrito actual + confirmación -->
        <div class="col-lg-7">
            <div class="lm-card lm-fade-up">
                <div class="lm-card-header">
                    <i class="bi bi-cart3"></i> Carrito
                    <span class="ms-2 lm-badge badge-activo"><?= count($carrito) ?> ítem(s)</span>
                    <?php if (!empty($carrito)): ?>
                        <a href="?vaciar=1" class="btn-lm-danger btn btn-sm ms-auto btn-eliminar">
                            <i class="bi bi-trash3 me-1"></i>Vaciar
                        </a>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="lm-table">
                        <thead>
                            <tr><th>Producto</th><th>Precio</th><th>Cant.</th><th>Subtotal</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($carrito)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">
                                <i class="bi bi-cart d-block fs-2 mb-2"></i>El carrito está vacío
                            </td></tr>
                        <?php else: foreach ($carrito as $item): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($item['producto'] ?? $item['nombre']) ?></strong></td>
                                <td>$<?= number_format((float) ($item['precio_unitario'] ?? $item['precio']), 2) ?></td>
                                <td><?= (int) $item['cantidad'] ?></td>
                                <td>$<?= number_format(((float) ($item['precio_unitario'] ?? $item['precio'])) * ((int) $item['cantidad']), 2) ?></td>
                                <td>
                                    <a href="?quitar=<?= $item['id_prod'] ?>" class="btn-lm-danger btn btn-sm">
                                        <i class="bi bi-x"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Total + Confirmación -->
                <?php if (!empty($carrito)): ?>
                <div class="lm-card-body border-top" style="border-color: var(--lm-border) !important;">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <span class="text-muted">Total:</span>
                        <span style="font-family:var(--lm-font-head); font-size:1.8rem; font-weight:800; color:var(--lm-accent)">
                            $<?= number_format($total, 2) ?>
                        </span>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="accion" value="confirmar">
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="lm-form-label">Cliente</label>
                                <select name="id_cli" class="lm-input form-select" required>
                                    <option value="">Seleccionar...</option>
                                    <?php foreach ($clientes as $c): ?>
                                        <option value="<?= $c['id_cli'] ?>" <?= $usaDbCarrito && (int) ($usuario['id_cli'] ?? 0) === (int) $c['id_cli'] ? 'selected' : '' ?>><?= htmlspecialchars($c['cliente']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="lm-form-label">Sucursal</label>
                                <select name="id_suc" class="lm-input form-select" required>
                                    <option value="">Seleccionar...</option>
                                    <?php foreach ($sucursales as $s): 
                                        $isOperative = lm_sucursal_operativa((int)$s['id_suc']);
                                    ?>
                                        <option value="<?= $s['id_suc'] ?>" <?= !$isOperative ? 'disabled' : '' ?>>
                                            <?= htmlspecialchars($s['sucursal']) ?> <?= !$isOperative ? '(OFFLINE)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn-lm-primary btn w-100">
                            <i class="bi bi-shield-check me-2"></i>Confirmar Venta (Transacción ACID)
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>
</div>

<script>
// Rellenar campos ocultos al seleccionar producto
document.getElementById('selProducto')?.addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    document.getElementById('inpNombreProd').value = opt.dataset.nombre || '';
    document.getElementById('inpPrecioProd').value = opt.dataset.precio || '';
});
</script>

<?php require_once 'includes/footer.php'; ?>
