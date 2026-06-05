<?php
require_once 'includes/auth.php';
lm_requiere_roles(['vendedor']);
require_once 'includes/header.php';

function lm_nombre_por_id(array $items, string $campoId, string $campoNombre, int $id, string $fallback = 'N/A'): string {
    foreach ($items as $item) {
        if ((int) ($item[$campoId] ?? 0) === $id) {
            return (string) ($item[$campoNombre] ?? $fallback);
        }
    }

    return $fallback;
}

function lm_indice_stock_sucursal(array $stock, int $idSuc, int $idProd): int {
    foreach ($stock as $idx => $item) {
        if ((int) ($item['id_suc'] ?? 0) === $idSuc && (int) ($item['id_prod'] ?? 0) === $idProd) {
            return $idx;
        }
    }

    return -1;
}

$usuario = lm_usuario_actual() ?? ['cliente' => 'Vendedor', 'rol' => 'vendedor'];
$mensaje = '';
$tipoMsg = 'info';

$productos =& lm_demo_bucket('productos');
$sucursales =& lm_demo_bucket('sucursales');
$stock =& lm_demo_bucket('stock');
$proveedores =& lm_demo_bucket('proveedores');
$compras =& lm_demo_bucket('compras');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear_producto') {
        $nombre = trim($_POST['producto'] ?? '');
        $precio = (float) ($_POST['precio'] ?? 0);
        $descripcion = trim($_POST['descripcion'] ?? '');

        if ($nombre !== '' && $precio > 0) {
            $nuevoId = lm_siguiente_id($productos, 'id_prod');
            $productos[] = [
                'id_prod' => $nuevoId,
                'producto' => $nombre,
                'precio' => $precio,
                'descripcion' => $descripcion,
            ];

            foreach ($sucursales as $sucursal) {
                $stock[] = [
                    'id_suc' => (int) $sucursal['id_suc'],
                    'id_prod' => $nuevoId,
                    'cantidad' => 0,
                ];
            }

            $mensaje = 'Producto creado. El flujo queda listo para reemplazar el array por INSERT real.';
            $tipoMsg = 'success';
        } else {
            $mensaje = 'Completa nombre y precio.';
            $tipoMsg = 'danger';
        }
    } elseif ($accion === 'registrar_compra') {
        $idProv = (int) ($_POST['id_prov'] ?? 0);
        $idProd = (int) ($_POST['id_prod'] ?? 0);
        $idSuc = (int) ($_POST['id_suc'] ?? 0);
        $cantidad = max(1, (int) ($_POST['cantidad'] ?? 1));
        $costo = (float) ($_POST['costo'] ?? 0);

        $proveedor = lm_indexar_por_id($proveedores, 'id_prov', $idProv);
        $producto = lm_indexar_por_id($productos, 'id_prod', $idProd);
        $sucursal = lm_indexar_por_id($sucursales, 'id_suc', $idSuc);

        if ($proveedor && $producto && $sucursal && $costo >= 0) {
            $idxStock = lm_indice_stock_sucursal($stock, $idSuc, $idProd);
            if ($idxStock >= 0) {
                $stock[$idxStock]['cantidad'] = (int) $stock[$idxStock]['cantidad'] + $cantidad;
            } else {
                $stock[] = [
                    'id_suc' => $idSuc,
                    'id_prod' => $idProd,
                    'cantidad' => $cantidad,
                ];
            }

            $compras[] = [
                'id_compra' => lm_siguiente_id($compras, 'id_compra'),
                'proveedor' => $proveedor['proveedor'],
                'producto' => $producto['producto'],
                'sucursal' => $sucursal['sucursal'],
                'cantidad' => $cantidad,
                'costo' => $costo * $cantidad,
                'fecha' => date('Y-m-d H:i'),
            ];

            $mensaje = 'Compra registrada y stock actualizado.';
            $tipoMsg = 'success';
        } else {
            $mensaje = 'Verifica proveedor, producto, sucursal y costo.';
            $tipoMsg = 'danger';
        }
    }
}

$productosRecientes = array_slice(array_reverse($productos), 0, 5);
$comprasRecientes = array_slice(array_reverse($compras), 0, 5);
$stockResumen = array_slice($stock, 0, 6);
$valorInventario = 0;
foreach ($stock as $item) {
    $producto = lm_indexar_por_id($productos, 'id_prod', (int) ($item['id_prod'] ?? 0));
    if ($producto) {
        $valorInventario += ((float) $producto['precio']) * (int) ($item['cantidad'] ?? 0);
    }
}
?>

<div class="lm-page">
    <div class="container-fluid">
        <div class="lm-page-header lm-fade-up">
            <div>
                <h1>Vista de <span>Vendedor</span></h1>
                <p><?= htmlspecialchars($usuario['cliente']) ?> crea productos y registra reposicion con proveedores.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="productos.php" class="btn-lm-ghost btn"><i class="bi bi-box-seam me-1"></i>Catalogo</a>
                <a href="compras.php" class="btn-lm-primary btn"><i class="bi bi-truck me-1"></i>Compras</a>
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
                    <div class="lm-stat-value"><?= count($productos) ?></div>
                    <div class="lm-stat-label">Productos cargados</div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="lm-stat">
                    <div class="lm-stat-icon bg-info-lm"><i class="bi bi-truck"></i></div>
                    <div class="lm-stat-value"><?= count($compras) ?></div>
                    <div class="lm-stat-label">Compras a proveedores</div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="lm-stat">
                    <div class="lm-stat-icon bg-succ-lm"><i class="bi bi-geo-alt"></i></div>
                    <div class="lm-stat-value"><?= count($sucursales) ?></div>
                    <div class="lm-stat-label">Sucursales activas</div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="lm-stat">
                    <div class="lm-stat-icon bg-warn-lm"><i class="bi bi-cash-stack"></i></div>
                    <div class="lm-stat-value">$<?= number_format($valorInventario, 0, ',', '.') ?></div>
                    <div class="lm-stat-label">Valor estimado del inventario</div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-7">
                <div class="lm-card lm-fade-up mb-3">
                    <div class="lm-card-header">
                        <i class="bi bi-plus-circle"></i> Crear producto
                    </div>
                    <div class="lm-card-body">
                        <form method="POST" class="row g-3">
                            <input type="hidden" name="accion" value="crear_producto">
                            <div class="col-md-5">
                                <label class="lm-form-label">Producto</label>
                                <input type="text" name="producto" class="lm-input form-control" placeholder="Nombre del item" required>
                            </div>
                            <div class="col-md-3">
                                <label class="lm-form-label">Precio</label>
                                <input type="number" name="precio" class="lm-input form-control" min="0" step="0.01" placeholder="0.00" required>
                            </div>
                            <div class="col-md-4">
                                <label class="lm-form-label">Descripcion</label>
                                <input type="text" name="descripcion" class="lm-input form-control" placeholder="Detalle breve">
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn-lm-primary btn">
                                    <i class="bi bi-check2 me-1"></i>Guardar producto
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="lm-card lm-fade-up">
                    <div class="lm-card-header">
                        <i class="bi bi-box-seam"></i> Catalogo actual
                    </div>
                    <div class="table-responsive">
                        <table class="lm-table">
                            <thead>
                                <tr>
                                    <th>#ID</th>
                                    <th>Producto</th>
                                    <th>Precio</th>
                                    <th>Descripcion</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($productosRecientes as $producto): ?>
                                    <tr>
                                        <td class="text-muted">#<?= (int) $producto['id_prod'] ?></td>
                                        <td><strong><?= htmlspecialchars($producto['producto']) ?></strong></td>
                                        <td>$<?= number_format((float) $producto['precio'], 0, ',', '.') ?></td>
                                        <td class="text-muted"><?= htmlspecialchars($producto['descripcion']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="lm-card lm-fade-up mb-3">
                    <div class="lm-card-header">
                        <i class="bi bi-truck"></i> Reponer stock
                    </div>
                    <div class="lm-card-body">
                        <form method="POST" class="row g-3">
                            <input type="hidden" name="accion" value="registrar_compra">
                            <div class="col-12">
                                <label class="lm-form-label">Proveedor</label>
                                <select name="id_prov" class="lm-input form-select" required>
                                    <option value="">Seleccionar...</option>
                                    <?php foreach ($proveedores as $proveedor): ?>
                                        <option value="<?= (int) $proveedor['id_prov'] ?>"><?= htmlspecialchars($proveedor['proveedor']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="lm-form-label">Producto</label>
                                <select name="id_prod" class="lm-input form-select" required>
                                    <option value="">Seleccionar...</option>
                                    <?php foreach ($productos as $producto): ?>
                                        <option value="<?= (int) $producto['id_prod'] ?>"><?= htmlspecialchars($producto['producto']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="lm-form-label">Sucursal destino</label>
                                <select name="id_suc" class="lm-input form-select" required>
                                    <option value="">Seleccionar...</option>
                                    <?php foreach ($sucursales as $sucursal): ?>
                                        <option value="<?= (int) $sucursal['id_suc'] ?>"><?= htmlspecialchars($sucursal['sucursal']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="lm-form-label">Cantidad</label>
                                <input type="number" name="cantidad" class="lm-input form-control" min="1" value="1" required>
                            </div>
                            <div class="col-6">
                                <label class="lm-form-label">Costo unitario</label>
                                <input type="number" name="costo" class="lm-input form-control" min="0" step="0.01" placeholder="0.00" required>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn-lm-primary btn w-100">
                                    <i class="bi bi-check2-circle me-1"></i>Registrar compra
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="lm-card lm-fade-up mb-3">
                    <div class="lm-card-header">
                        <i class="bi bi-boxes"></i> Inventario por ubicacion
                    </div>
                    <div class="table-responsive">
                        <table class="lm-table">
                            <thead>
                                <tr>
                                    <th>Sucursal</th>
                                    <th>Producto</th>
                                    <th>Cantidad</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stockResumen as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(lm_nombre_por_id($sucursales, 'id_suc', 'sucursal', (int) $item['id_suc'])) ?></td>
                                        <td><?= htmlspecialchars(lm_nombre_por_id($productos, 'id_prod', 'producto', (int) $item['id_prod'])) ?></td>
                                        <td><span class="lm-badge <?= (int) $item['cantidad'] > 0 ? 'badge-activo' : 'badge-inactivo' ?>"><?= (int) $item['cantidad'] ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="lm-card lm-fade-up">
                    <div class="lm-card-header">
                        <i class="bi bi-clock-history"></i> Compras recientes
                    </div>
                    <div class="lm-card-body">
                        <?php if (empty($comprasRecientes)): ?>
                            <div class="text-muted text-center py-3">Sin compras registradas</div>
                        <?php else: ?>
                            <div class="d-grid gap-2">
                                <?php foreach ($comprasRecientes as $compra): ?>
                                    <div class="p-2 rounded-3" style="background: var(--lm-surface2); border: 1px solid var(--lm-border);">
                                        <div class="d-flex justify-content-between">
                                            <strong>#<?= (int) $compra['id_compra'] ?></strong>
                                            <span class="lm-badge badge-pagado">$<?= number_format((float) $compra['costo'], 0, ',', '.') ?></span>
                                        </div>
                                        <div class="small text-muted mt-1">
                                            <?= htmlspecialchars($compra['proveedor']) ?> - <?= htmlspecialchars($compra['producto']) ?>
                                        </div>
                                        <div class="small text-muted"><?= htmlspecialchars($compra['sucursal']) ?> - <?= htmlspecialchars($compra['fecha']) ?></div>
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
