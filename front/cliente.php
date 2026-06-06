<?php
require_once 'includes/auth.php';
lm_requiere_roles(['cliente']);
require_once 'includes/header.php';

$usuario = lm_usuario_actual() ?? ['cliente' => 'Cliente', 'rol' => 'cliente'];
$mensaje = '';
$tipoMsg = 'info';

$productos = lm_catalogo_productos(true);
$sucursales = lm_sucursales_operativas();
$stock = lm_stock_todos();

if (!isset($_SESSION['carrito_cliente'])) {
    $_SESSION['carrito_cliente'] = [];
}
$carrito =& $_SESSION['carrito_cliente'];
$ventas = !empty($usuario['id_cli']) ? lm_ventas_listar_por_cliente((int) $usuario['id_cli']) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'agregar_carrito') {
        $idProd = (int) ($_POST['id_prod'] ?? 0);
        $cantidad = max(1, (int) ($_POST['cantidad'] ?? 1));
            $producto = lm_producto_por_id($idProd);

        if ($producto) {
            if (!isset($carrito[$idProd])) {
                $carrito[$idProd] = [
                    'id_prod' => $producto['id_prod'],
                    'producto' => $producto['producto'],
                    'precio' => $producto['precio'],
                    'cantidad' => 0,
                    'subtotal' => 0,
                ];
            }

            $carrito[$idProd]['cantidad'] += $cantidad;
            $carrito[$idProd]['subtotal'] = $carrito[$idProd]['cantidad'] * $carrito[$idProd]['precio'];
            $mensaje = 'Producto agregado al carrito.';
            $tipoMsg = 'success';
        } else {
            $mensaje = 'El producto no existe.';
            $tipoMsg = 'danger';
        }
    } elseif ($accion === 'eliminar_item') {
        $idProd = (int) ($_POST['id_prod'] ?? 0);
        if (isset($carrito[$idProd])) {
            unset($carrito[$idProd]);
            $mensaje = 'Item eliminado del carrito.';
            $tipoMsg = 'warning';
        }
    } elseif ($accion === 'vaciar_carrito') {
        $carrito = [];
        $mensaje = 'Carrito vaciado.';
        $tipoMsg = 'warning';
    } elseif ($accion === 'finalizar_compra') {
        $idSuc = (int) ($_POST['id_suc'] ?? 0);
        $sucursal = lm_indexar_por_id($sucursales, 'id_suc', $idSuc);

        if (!$carrito) {
            $mensaje = 'El carrito esta vacio.';
            $tipoMsg = 'danger';
        } elseif (!$sucursal) {
            $mensaje = 'Selecciona una sucursal valida.';
            $tipoMsg = 'danger';
        } else {
            $items = array_map(static function (array $item): array {
                return [
                    'id_prod' => (int) $item['id_prod'],
                    'cantidad' => (int) $item['cantidad'],
                    'precio_unitario' => (float) $item['precio'],
                ];
            }, array_values($carrito));

            try {
                lm_registrar_venta((int) $usuario['id_cli'], $idSuc, $items);
                $carrito = [];
                $mensaje = 'Compra procesada con transacción ACID real.';
                $tipoMsg = 'success';
            } catch (Throwable $e) {
                $mensaje = $e->getMessage();
                $tipoMsg = 'danger';
            }
        }
    }
}

$busqueda = trim($_GET['q'] ?? '');
$productosVisibles = array_values(array_filter($productos, function ($producto) use ($busqueda) {
    if ($busqueda === '') {
        return true;
    }

    $texto = strtolower(($producto['producto'] ?? '') . ' ' . ($producto['descripcion'] ?? ''));
    return str_contains($texto, strtolower($busqueda));
}));

$totalProductos = count($productos);
$itemsCarrito = array_sum(array_column($carrito, 'cantidad'));
$totalCarrito = array_sum(array_column($carrito, 'subtotal'));
$ventasRecientes = array_slice(array_reverse(array_values($ventas)), 0, 4);
?>

<div class="lm-page">
    <div class="container-fluid">
        <div class="lm-page-header lm-fade-up">
            <div>
                <h1>Vista de <span>Cliente</span></h1>
                <p><?= htmlspecialchars($usuario['cliente']) ?> compra items y gestiona su carrito desde el catálogo.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="ventas.php" class="btn-lm-ghost btn"><i class="bi bi-receipt me-1"></i>Mis compras</a>
                <a href="logout.php" class="btn-lm-primary btn"><i class="bi bi-box-arrow-right me-1"></i>Salir</a>
            </div>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?= $tipoMsg ?> alert-auto alert-dismissible fade show mb-4" role="alert">
                <?= htmlspecialchars($mensaje) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4 lm-fade-up">
            <div class="col-6 col-xl-3">
                <div class="lm-stat">
                    <div class="lm-stat-icon bg-accent"><i class="bi bi-box-seam"></i></div>
                    <div class="lm-stat-value"><?= $totalProductos ?></div>
                    <div class="lm-stat-label">Productos disponibles</div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="lm-stat">
                    <div class="lm-stat-icon bg-info-lm"><i class="bi bi-cart3"></i></div>
                    <div class="lm-stat-value"><?= $itemsCarrito ?></div>
                    <div class="lm-stat-label">Items en carrito</div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="lm-stat">
                    <div class="lm-stat-icon bg-succ-lm"><i class="bi bi-receipt"></i></div>
                    <div class="lm-stat-value"><?= count($ventas) ?></div>
                    <div class="lm-stat-label">Compras registradas</div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="lm-stat">
                    <div class="lm-stat-icon bg-warn-lm"><i class="bi bi-cash-coin"></i></div>
                    <div class="lm-stat-value">$<?= number_format($totalCarrito, 0, ',', '.') ?></div>
                    <div class="lm-stat-label">Total estimado</div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="lm-card lm-fade-up">
                    <div class="lm-card-header">
                        <i class="bi bi-boxes"></i> Catálogo de productos
                        <form class="ms-auto" method="GET">
                            <div class="lm-search-wrap" style="width:260px">
                                <i class="bi bi-search"></i>
                                <input type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>" class="lm-input form-control" placeholder="Buscar producto">
                            </div>
                        </form>
                    </div>
                    <div class="table-responsive">
                        <table class="lm-table" id="tablaClienteProductos">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Precio</th>
                                    <th>Descripcion</th>
                                    <th>Stock</th>
                                    <th>Agregar</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($productosVisibles)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-5 text-muted">
                                            <i class="bi bi-search d-block fs-2 mb-2"></i>
                                            Sin resultados
                                        </td>
                                    </tr>
                                <?php else: foreach ($productosVisibles as $producto): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($producto['producto']) ?></strong></td>
                                        <td>$<?= number_format((float) $producto['precio'], 0, ',', '.') ?></td>
                                        <td class="text-muted"><?= htmlspecialchars($producto['descripcion']) ?></td>
                                        <td>
                                            <?php $stockTotal = array_sum(array_map(static fn($item) => (int) $item['cantidad'], array_filter($stock, static fn($item) => (int) $item['id_prod'] === (int) $producto['id_prod']))); ?>
                                            <span class="lm-badge <?= $stockTotal > 0 ? 'badge-activo' : 'badge-inactivo' ?>">
                                                <?= $stockTotal ?> uds
                                            </span>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-flex align-items-center gap-2">
                                                <input type="hidden" name="accion" value="agregar_carrito">
                                                <input type="hidden" name="id_prod" value="<?= (int) $producto['id_prod'] ?>">
                                                <input type="number" name="cantidad" class="lm-input form-control" min="1" value="1" style="max-width:86px">
                                                <button type="submit" class="btn-lm-primary btn btn-sm">
                                                    <i class="bi bi-cart-plus"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="lm-card lm-fade-up mb-3">
                    <div class="lm-card-header">
                        <i class="bi bi-cart3"></i> Carrito
                        <span class="ms-auto lm-badge badge-activo"><?= $itemsCarrito ?> item(s)</span>
                    </div>
                    <div class="lm-card-body">
                        <?php if (empty($carrito)): ?>
                            <div class="text-muted text-center py-3">
                                <i class="bi bi-bag-dash d-block fs-2 mb-2"></i>
                                Sin productos agregados
                            </div>
                        <?php else: ?>
                            <div class="d-grid gap-2">
                                <?php foreach ($carrito as $item): ?>
                                    <div class="d-flex justify-content-between align-items-center p-2 rounded-3" style="background: var(--lm-surface2); border: 1px solid var(--lm-border);">
                                        <div>
                                            <div class="fw-semibold"><?= htmlspecialchars($item['producto']) ?></div>
                                            <div class="small text-muted"><?= (int) $item['cantidad'] ?> x $<?= number_format((float) $item['precio'], 0, ',', '.') ?></div>
                                        </div>
                                        <form method="POST">
                                            <input type="hidden" name="accion" value="eliminar_item">
                                            <input type="hidden" name="id_prod" value="<?= (int) $item['id_prod'] ?>">
                                            <button class="btn-lm-danger btn btn-sm" type="submit"><i class="bi bi-x-lg"></i></button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="border-top mt-3 pt-3" style="border-color: var(--lm-border) !important;">
                                <div class="d-flex justify-content-between mb-3">
                                    <span class="text-muted">Total</span>
                                    <strong>$<?= number_format($totalCarrito, 0, ',', '.') ?></strong>
                                </div>
                                <form method="POST">
                                    <input type="hidden" name="accion" value="finalizar_compra">
                                    <div class="mb-3">
                                        <label class="lm-form-label">Sucursal de retiro</label>
                                        <select name="id_suc" class="lm-input form-select" required>
                                            <option value="">Seleccionar...</option>
                                            <?php foreach ($sucursales as $sucursal): ?>
                                                <option value="<?= (int) $sucursal['id_suc'] ?>"><?= htmlspecialchars($sucursal['sucursal']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn-lm-primary btn flex-grow-1">
                                            <i class="bi bi-check2-circle me-1"></i>Finalizar compra
                                        </button>
                                        <button type="submit" name="accion" value="vaciar_carrito" class="btn-lm-ghost btn">
                                            <i class="bi bi-trash3 me-1"></i>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="lm-card lm-fade-up">
                    <div class="lm-card-header">
                        <i class="bi bi-clock-history"></i> Compras recientes
                    </div>
                    <div class="lm-card-body">
                        <?php if (empty($ventasRecientes)): ?>
                            <div class="text-muted text-center py-3">Sin historial</div>
                        <?php else: ?>
                            <div class="d-grid gap-2">
                                <?php foreach ($ventasRecientes as $venta): ?>
                                    <div class="p-2 rounded-3" style="background: var(--lm-surface2); border: 1px solid var(--lm-border);">
                                        <div class="d-flex justify-content-between">
                                            <strong>#<?= (int) $venta['id_venta'] ?></strong>
                                            <span class="lm-badge badge-pagado">$<?= number_format((float) $venta['total'], 0, ',', '.') ?></span>
                                        </div>
                                        <div class="small text-muted mt-1"><?= htmlspecialchars($venta['sucursal']) ?> - <?= htmlspecialchars($venta['fecha']) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
