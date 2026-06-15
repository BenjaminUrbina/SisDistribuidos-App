<?php
require_once 'includes/auth.php';

$usuario = lm_usuario_actual();
$isGuest = !$usuario;
$mensaje = '';
$tipoMsg = 'info';

// Manejo de acciones POST (Antes de cualquier salida HTML)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if (!isset($_SESSION['carrito_cliente'])) {
        $_SESSION['carrito_cliente'] = [];
    }
    $carrito =& $_SESSION['carrito_cliente'];

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
        if ($isGuest) {
            header('Location: ' . lm_url('login.php?msg=Debes iniciar sesión para comprar'));
            exit;
        }

        $idSuc = (int) ($_POST['id_suc'] ?? 0);
        $sucursales = lm_sucursales_operativas();
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

// Ahora que procesamos el POST, podemos cargar el header
require_once 'includes/header.php';

$productos = lm_catalogo_productos(true);
$sucursales = lm_sucursales_listar(true); // Listar todas para mostrar estado
$stock = lm_stock_todos();

if (!isset($_SESSION['carrito_cliente'])) {
    $_SESSION['carrito_cliente'] = [];
}
$carrito =& $_SESSION['carrito_cliente'];
$ventas = ($usuario && !empty($usuario['id_cli'])) ? lm_ventas_listar_por_cliente((int) $usuario['id_cli']) : [];

$busqueda = trim($_GET['q'] ?? '');
$productosVisibles = array_values(array_filter($productos, function ($producto) use ($busqueda) {
    if ($busqueda === '') return true;
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
        
        <?php if ($isGuest): ?>
        <!-- Banner Invitado -->
        <div class="alert alert-info border-0 shadow-sm mb-4 d-flex align-items-center justify-content-between p-3 lm-fade-up">
            <div class="d-flex align-items-center gap-3">
                <i class="bi bi-person-circle fs-3 text-primary"></i>
                <div>
                    <h6 class="mb-0 fw-bold">¡Bienvenido a Libre Mercado!</h6>
                    <p class="small mb-0 text-muted">Puedes navegar y agregar productos al carrito, pero para comprar necesitas una cuenta.</p>
                </div>
            </div>
            <a href="login.php" class="btn btn-primary btn-sm px-4 fw-bold">Ingresar / Registrarme</a>
        </div>
        <?php endif; ?>

        <div class="lm-page-header lm-fade-up">
            <div>
                <h1>Catálogo de <span>Productos</span></h1>
                <p>Explora nuestra selección distribuida en múltiples nodos.</p>
            </div>
            <?php if (!$isGuest): ?>
            <div class="d-flex gap-2 flex-wrap">
                <a href="ventas.php" class="btn-lm-ghost btn"><i class="bi bi-receipt me-1"></i>Mis compras</a>
            </div>
            <?php endif; ?>
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
                        <i class="bi bi-boxes"></i> Catálogo actual
                        <form class="ms-auto" method="GET">
                            <div class="lm-search-wrap" style="width:260px">
                                <i class="bi bi-search"></i>
                                <input type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>" class="lm-input form-control" placeholder="Buscar producto">
                                <?php if (($sessionToken ?? 'DEFAULT') !== 'DEFAULT'): ?>
                                    <input type="hidden" name="token" value="<?= htmlspecialchars($sessionToken) ?>">
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                    <div class="lm-card-body p-4">
                        <div class="row g-4" id="catalogoProductos">
                            <?php if (empty($productosVisibles)): ?>
                                <div class="col-12 text-center py-5 text-muted">
                                    <i class="bi bi-search d-block fs-2 mb-2"></i>
                                    Sin resultados para "<?= htmlspecialchars($busqueda) ?>"
                                </div>
                            <?php else: foreach ($productosVisibles as $producto): ?>
                                <div class="col-md-6 col-xl-4">
                                    <div class="lm-product-card h-100">
                                        <div class="lm-product-img">
                                            <i class="bi bi-box-seam"></i>
                                        </div>
                                        <div class="lm-product-body">
                                            <div class="lm-product-price">$<?= number_format((float) $producto['precio'], 0, ',', '.') ?></div>
                                            <h3 class="lm-product-title"><?= htmlspecialchars($producto['producto']) ?></h3>
                                            <p class="lm-product-desc"><?= htmlspecialchars($producto['descripcion']) ?></p>

                                            <?php
                                                $stockTotal = 0;
                                                foreach ($stock as $sItem) {
                                                    if ((int)$sItem['id_prod'] === (int)$producto['id_prod']) {
                                                        // Solo sumar stock si el nodo está ONLINE (real o simulado)
                                                        if (LmDatabase::ping($sItem['nodo'])) {
                                                            $stockTotal += (int)$sItem['cantidad'];
                                                        }
                                                    }
                                                }
                                            ?>
                                            <div class="d-flex align-items-center justify-content-between mt-auto pt-3">
                                                <span class="lm-badge <?= $stockTotal > 0 ? 'badge-activo' : 'badge-inactivo' ?>">
                                                    <?= $stockTotal > 0 ? $stockTotal . ' disponibles' : 'Sin stock/Nodo OFF' ?>
                                                </span>

                                                <form method="POST" class="d-flex align-items-center gap-1">
                                                    <input type="hidden" name="accion" value="agregar_carrito">
                                                    <input type="hidden" name="id_prod" value="<?= (int) $producto['id_prod'] ?>">
                                                    <input type="number" name="cantidad" class="lm-input form-control form-control-sm" min="1" value="1" style="width:60px">
                                                    <button type="submit" class="btn-lm-primary btn btn-sm" <?= $stockTotal <= 0 ? 'disabled' : '' ?>>
                                                        <i class="bi bi-cart-plus"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
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
                                            <?php foreach ($sucursales as $sucursal): 
                                                $isOperative = lm_sucursal_operativa((int)$sucursal['id_suc']);
                                            ?>
                                                <option value="<?= (int) $sucursal['id_suc'] ?>" <?= !$isOperative ? 'disabled' : '' ?>>
                                                    <?= htmlspecialchars($sucursal['sucursal']) ?> <?= !$isOperative ? '(FUERA DE LÍNEA)' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php 
                                        $anyOperative = false;
                                        foreach ($sucursales as $s) {
                                            if (lm_sucursal_operativa((int)$s['id_suc'])) {
                                                $anyOperative = true;
                                                break;
                                            }
                                        }
                                        if (!$anyOperative): ?>
                                            <div class="text-danger small mt-1"><i class="bi bi-exclamation-triangle"></i> Ninguna sucursal disponible para retiro (Nodo de stock caído).</div>
                                        <?php endif; ?>
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

                <?php if (!$isGuest && !empty($ventasRecientes)): ?>
                <div class="lm-card lm-fade-up">
                    <div class="lm-card-header">
                        <i class="bi bi-clock-history"></i> Compras recientes
                    </div>
                    <div class="lm-card-body">
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
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
