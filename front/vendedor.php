<?php
require_once 'includes/auth.php';
lm_requiere_roles(['vendedor']);
require_once 'includes/header.php';

$usuario = lm_usuario_actual() ?? ['cliente' => 'Vendedor', 'rol' => 'vendedor'];
$mensaje = '';
$tipoMsg = 'info';

$productos = lm_catalogo_productos(false);
$sucursales = lm_sucursales_operativas();
$stock = lm_stock_todos();
$proveedores = lm_proveedores_listar(true);
$compras = lm_compras_listar();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear_producto') {
        $nombre = trim($_POST['producto'] ?? '');
        $precio = (float) ($_POST['precio'] ?? 0);
        $descripcion = trim($_POST['descripcion'] ?? '');

        try {
            if ($nombre !== '' && $precio > 0) {
                lm_guardar_producto([
                    'producto' => $nombre,
                    'precio' => $precio,
                    'descripcion' => $descripcion,
                ]);
                $mensaje = 'Producto creado.';
                $tipoMsg = 'success';
            } else {
                $mensaje = 'Completa nombre y precio.';
                $tipoMsg = 'danger';
            }
        } catch (Throwable $e) {
            $mensaje = $e->getMessage();
            $tipoMsg = 'danger';
        }
    } elseif ($accion === 'registrar_compra') {
        $idProv = (int) ($_POST['id_prov'] ?? 0);
        $idProd = (int) ($_POST['id_prod'] ?? 0);
        $idSuc = (int) ($_POST['id_suc'] ?? 0);
        $cantidad = max(1, (int) ($_POST['cantidad'] ?? 1));
        $costo = (float) ($_POST['costo'] ?? 0);
        try {
            if ($idProv && $idProd && $idSuc && $costo >= 0) {
                lm_registrar_compra($idProv, $idSuc, $idProd, $cantidad, $costo);
                $mensaje = 'Compra registrada y stock actualizado.';
                $tipoMsg = 'success';
                // Recargar stock después de la compra
                $stock = lm_stock_todos();
            } else {
                $mensaje = 'Verifica proveedor, producto, sucursal y costo.';
                $tipoMsg = 'danger';
            }
        } catch (Throwable $e) {
            $mensaje = $e->getMessage();
            $tipoMsg = 'danger';
        }
    }
}

$productosRecientes = array_slice(array_reverse($productos), 0, 5);
$comprasRecientes = array_slice(array_reverse($compras), 0, 5);
$stockResumen = array_slice($stock, 0, 6);
$valorInventario = 0;
foreach ($stock as $item) {
    $producto = lm_producto_por_id((int) ($item['id_prod'] ?? 0));
    if ($producto) {
        $valorInventario += ((float) $producto['precio']) * (int) ($item['cantidad'] ?? 0);
    }
}
?>

<div class="lm-page">
    <div class="container-fluid">
        <div class="lm-page-header lm-fade-up">
            <div>
                <h1>Panel de <span>Vendedor</span></h1>
                <p>Gestión de inventario y reposición con proveedores.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="productos.php" class="btn-lm-ghost btn"><i class="bi bi-box-seam me-1"></i>Catálogo completo</a>
                <a href="sucursales.php" class="btn-lm-primary btn"><i class="bi bi-geo-alt me-1"></i>Ver Sucursales</a>
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
                    <div class="lm-stat-label">Reposiciones realizadas</div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="lm-stat">
                    <div class="lm-stat-icon bg-succ-lm"><i class="bi bi-geo-alt"></i></div>
                    <div class="lm-stat-value"><?= count($sucursales) ?></div>
                    <div class="lm-stat-label">Sucursales operativas</div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="lm-stat">
                    <div class="lm-stat-icon bg-warn-lm"><i class="bi bi-currency-dollar"></i></div>
                    <div class="lm-stat-value"><?= number_format($valorInventario / 1000000, 1) ?>M</div>
                    <div class="lm-stat-label">Valor total inventario</div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-7">
                <div class="lm-card lm-fade-up mb-3">
                    <div class="lm-card-header">
                        <i class="bi bi-plus-circle text-accent"></i> Registro de Nuevo Producto
                    </div>
                    <div class="lm-card-body">
                        <form method="POST" class="row g-3">
                            <input type="hidden" name="accion" value="crear_producto">
                            <div class="col-md-6">
                                <label class="lm-form-label">Nombre del Producto</label>
                                <input type="text" name="producto" class="lm-input form-control" placeholder="Ej: Smartphone Galaxy S24" required>
                            </div>
                            <div class="col-md-3">
                                <label class="lm-form-label">Precio Venta</label>
                                <input type="number" name="precio" class="lm-input form-control" min="0" step="0.01" placeholder="0.00" required>
                            </div>
                            <div class="col-md-3">
                                <label class="lm-form-label">Categoría/Ref</label>
                                <input type="text" name="descripcion" class="lm-input form-control" placeholder="Ej: Electrónica">
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn-lm-primary btn px-4">
                                    <i class="bi bi-check2 me-1"></i>Crear y Sincronizar Nodos
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="lm-card lm-fade-up">
                    <div class="lm-card-header">
                        <i class="bi bi-clock-history"></i> Catálogo Reciente
                        <a href="productos.php" class="btn-lm-ghost btn btn-sm ms-auto">Administrar</a>
                    </div>
                    <div class="table-responsive">
                        <table class="lm-table">
                            <thead>
                                <tr>
                                    <th>#ID</th>
                                    <th>Producto</th>
                                    <th>Precio</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($productosRecientes as $producto): ?>
                                    <tr>
                                        <td class="text-muted">#<?= (int) $producto['id_prod'] ?></td>
                                        <td><strong><?= htmlspecialchars($producto['producto']) ?></strong></td>
                                        <td>$<?= number_format((float) $producto['precio'], 0, ',', '.') ?></td>
                                        <td><span class="lm-badge badge-activo">Activo</span></td>
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
                        <i class="bi bi-truck text-warning"></i> Reponer Stock (Compra a Proveedor)
                    </div>
                    <div class="lm-card-body">
                        <form method="POST" class="row g-3">
                            <input type="hidden" name="accion" value="registrar_compra">
                            <div class="col-12">
                                <label class="lm-form-label">Proveedor</label>
                                <select name="id_prov" class="lm-input form-select" required>
                                    <option value="">Seleccionar proveedor...</option>
                                    <?php foreach ($proveedores as $proveedor): ?>
                                        <option value="<?= (int) $proveedor['id_proveedor'] ?>"><?= htmlspecialchars($proveedor['proveedor']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="lm-form-label">Producto a Reponer</label>
                                <select name="id_prod" class="lm-input form-select" required>
                                    <option value="">Seleccionar producto...</option>
                                    <?php foreach ($productos as $producto): ?>
                                        <option value="<?= (int) $producto['id_prod'] ?>"><?= htmlspecialchars($producto['producto']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="lm-form-label">Sucursal Destino</label>
                                <select name="id_suc" class="lm-input form-select" required>
                                    <option value="">Seleccionar sucursal...</option>
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
                                <label class="lm-form-label">Costo Unitario</label>
                                <input type="number" name="costo" class="lm-input form-control" min="0" step="0.01" placeholder="0.00" required>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn-lm-primary btn w-100">
                                    <i class="bi bi-check2-circle me-1"></i>Confirmar Reposición
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="lm-card lm-fade-up">
                    <div class="lm-card-header">
                        <i class="bi bi-boxes text-info"></i> Resumen de Inventario
                    </div>
                    <div class="table-responsive">
                        <table class="lm-table">
                            <thead>
                                <tr>
                                    <th>Sucursal</th>
                                    <th>Producto</th>
                                    <th>Stock</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($stockResumen)): ?>
                                    <tr><td colspan="3" class="text-center py-3 text-muted">Sin stock registrado</td></tr>
                                <?php else: foreach ($stockResumen as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['sucursal'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($item['producto'] ?? 'N/A') ?></td>
                                        <td><span class="lm-badge <?= (int) $item['cantidad'] > 0 ? 'badge-activo' : 'badge-inactivo' ?>"><?= (int) $item['cantidad'] ?> uds</span></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
