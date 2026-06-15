<?php
require_once 'includes/auth.php';
lm_requiere_roles(['admin', 'vendedor']);
require_once 'includes/header.php';

/**
 * SUCURSALES — sucursales.php
 * ─────────────────────────────────────────────────────────────────────────────
 * TODO BD (Nodo: sucursales):
 *   $pdo = conectarNodo('sucursales');
 *   $sucursales = $pdo->query("SELECT * FROM sucursales")->fetchAll();
 *   $stock = $pdo->query("SELECT s.*, p.producto, p.precio
 *                          FROM stock s JOIN productos p ON s.id_prod = p.id_prod")->fetchAll();
 *   INSERT INTO sucursales (sucursal, direccion, ciudad) VALUES (?,?,?)
 *   UPDATE stock SET cantidad = ? WHERE id_suc = ? AND id_prod = ?
 */

$mensaje = ''; $tipoMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion   = $_POST['accion'] ?? '';
    $nombre   = htmlspecialchars(trim($_POST['sucursal'] ?? ''));
    $dir      = htmlspecialchars(trim($_POST['direccion'] ?? ''));
    $ciudad   = htmlspecialchars(trim($_POST['ciudad'] ?? ''));
    $id       = intval($_POST['id_suc'] ?? 0);

    try {
        if ($accion === 'crear' && $nombre) {
            lm_guardar_sucursal([
                'id_suc' => 0,
                'sucursal' => $nombre,
                'direccion' => $dir,
            ]);
            $mensaje = "Sucursal '$nombre' creada."; $tipoMsg = 'success';
        } elseif ($accion === 'editar' && $id > 0) {
            lm_guardar_sucursal([
                'id_suc' => $id,
                'sucursal' => $nombre,
                'direccion' => $dir,
            ]);
            $mensaje = "Sucursal actualizada."; $tipoMsg = 'success';
        } elseif ($accion === 'stock') {
            $id_suc  = intval($_POST['id_suc_stock'] ?? 0);
            $id_prod = intval($_POST['id_prod_stock'] ?? 0);
            $cant    = intval($_POST['cantidad'] ?? 0);
            lm_stock_actualizar($id_suc, $id_prod, $cant, 'Ajuste manual desde sucursales');
            $mensaje = "Stock actualizado."; $tipoMsg = 'success';
        }
    } catch (Throwable $e) {
        $mensaje = $e->getMessage(); $tipoMsg = 'danger';
    }
}

if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    try {
        lm_desactivar_sucursal($id);
        $mensaje = "Sucursal desactivada (borrado lógico)."; $tipoMsg = 'warning';
    } catch (Throwable $e) {
        $mensaje = $e->getMessage(); $tipoMsg = 'danger';
    }
}

$sucursales = lm_sucursales_listar(false);
$stock      = lm_stock_todos();
$productos  = lm_catalogo_productos(true);
$sucursalesOperativas = lm_sucursales_operativas();
?>

<div class="lm-page">
<div class="container-fluid">

    <div class="lm-page-header lm-fade-up">
        <div>
            <h1><span>Sucursales</span> y Stock</h1>
            <p>Control de inventario por ubicación &mdash; Nodo sucursales</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn-lm-ghost btn" data-bs-toggle="modal" data-bs-target="#modalStock">
                <i class="bi bi-boxes me-1"></i>Actualizar Stock
            </button>
            <button class="btn-lm-primary btn" data-bs-toggle="modal" data-bs-target="#modalSucursal">
                <i class="bi bi-plus-lg me-1"></i>Nueva Sucursal
            </button>
        </div>
    </div>

    <?php if ($mensaje): ?>
    <div class="alert alert-<?= $tipoMsg ?> alert-auto alert-dismissible fade show mb-4">
        <?= $mensaje ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Sucursales -->
    <div class="lm-card lm-fade-up mb-4">
        <div class="lm-card-header"><i class="bi bi-geo-alt"></i> Sucursales</div>
        <div class="table-responsive">
            <table class="lm-table">
                <thead>
                    <tr><th>#ID</th><th>Sucursal</th><th>Dirección</th><th>Estado</th><th>Acciones</th></tr>
                </thead>
                <tbody>
                <?php if (empty($sucursales)): ?>
                    <tr><td colspan="5" class="text-center py-4 text-muted">
                        <i class="bi bi-geo-alt d-block fs-2 mb-2"></i>Sin sucursales
                    </td></tr>
                <?php else: foreach ($sucursales as $s): ?>
                    <tr>
                        <td class="text-muted">#<?= $s['id_suc'] ?></td>
                        <td><strong><?= htmlspecialchars($s['sucursal']) ?></strong></td>
                        <td><?= htmlspecialchars($s['direccion']) ?></td>
                        <td><span class="lm-badge <?= (int) ($s['activo'] ?? 1) === 1 ? 'badge-activo' : 'badge-inactivo' ?>"><?= (int) ($s['activo'] ?? 1) === 1 ? 'Activa' : 'Inactiva' ?></span></td>
                        <td>
                            <div class="d-flex gap-1">
                                <button class="btn-lm-edit btn btn-sm btn-editar-suc"
                                    data-id="<?= $s['id_suc'] ?>"
                                    data-nombre="<?= htmlspecialchars($s['sucursal']) ?>"
                                    data-dir="<?= htmlspecialchars($s['direccion']) ?>"
                                    data-ciudad="<?= htmlspecialchars($s['direccion']) ?>"
                                    data-bs-toggle="modal" data-bs-target="#modalSucursal">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <a href="?eliminar=<?= $s['id_suc'] ?>" class="btn-lm-danger btn btn-sm btn-eliminar">
                                    <i class="bi bi-trash3"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Stock -->
    <div class="lm-card lm-fade-up">
        <div class="lm-card-header"><i class="bi bi-boxes"></i> Inventario por Sucursal</div>
        <div class="table-responsive">
            <table class="lm-table">
                <thead>
                    <tr><th>Sucursal</th><th>Producto</th><th>Precio Unit.</th><th>Stock</th><th>Alerta</th></tr>
                </thead>
                <tbody>
                <?php if (empty($stock)): ?>
                    <tr><td colspan="5" class="text-center py-4 text-muted">
                        <i class="bi bi-boxes d-block fs-2 mb-2"></i>Sin registros de stock
                    </td></tr>
                <?php else: foreach ($stock as $s): ?>
                    <?php $alerta = $s['cantidad'] <= 5; ?>
                    <tr>
                        <td><?= htmlspecialchars($s['sucursal']) ?></td>
                        <td><strong><?= htmlspecialchars($s['producto']) ?></strong></td>
                        <td>$<?= number_format($s['precio'], 2) ?></td>
                        <td>
                            <span class="lm-badge <?= $alerta ? 'badge-inactivo' : 'badge-activo' ?>">
                                <?= $s['cantidad'] ?> un.
                            </span>
                        </td>
                        <td><?php if ($alerta): ?>
                            <span class="text-warning small"><i class="bi bi-exclamation-triangle-fill"></i> Stock crítico</span>
                        <?php endif; ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</div>

<!-- Modal Sucursal -->
<div class="modal fade" id="modalSucursal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="tituloSuc"><i class="bi bi-geo-alt me-2"></i>Nueva Sucursal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="accion" id="accionSuc" value="crear">
                <input type="hidden" name="id_suc" id="idSuc" value="0">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="lm-form-label">Nombre de la sucursal <span class="text-danger">*</span></label>
                        <input type="text" name="sucursal" id="inpSucursal" class="lm-input form-control" placeholder="Ej: Sucursal Centro" required>
                    </div>
                    <div class="mb-3">
                        <label class="lm-form-label">Dirección</label>
                        <input type="text" name="direccion" id="inpDir" class="lm-input form-control" placeholder="Calle y número">
                    </div>
                    <div class="mb-3">
                        <label class="lm-form-label">Ciudad</label>
                        <input type="text" name="ciudad" id="inpCiudad" class="lm-input form-control" placeholder="La Serena">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-lm-ghost btn" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn-lm-primary btn"><i class="bi bi-check2 me-1"></i>Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Stock -->
<div class="modal fade" id="modalStock" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-boxes me-2"></i>Actualizar Stock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="accion" value="stock">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="lm-form-label">Sucursal <span class="text-danger">*</span></label>
                        <select name="id_suc_stock" class="lm-input form-select" required>
                            <option value="">Seleccionar...</option>
                            <?php foreach ($sucursalesOperativas as $s): ?>
                                <option value="<?= $s['id_suc'] ?>"><?= htmlspecialchars($s['sucursal']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="lm-form-label">Producto <span class="text-danger">*</span></label>
                        <select name="id_prod_stock" class="lm-input form-select" required>
                            <option value="">Seleccionar...</option>
                            <?php foreach ($productos as $p): ?>
                                <option value="<?= $p['id_prod'] ?>"><?= htmlspecialchars($p['producto']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="lm-form-label">Cantidad <span class="text-danger">*</span></label>
                        <input type="number" name="cantidad" class="lm-input form-control" min="0" placeholder="0" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-lm-ghost btn" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn-lm-primary btn"><i class="bi bi-check2 me-1"></i>Actualizar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.btn-editar-suc').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('tituloSuc').innerHTML  = '<i class="bi bi-pencil me-2"></i>Editar Sucursal';
        document.getElementById('accionSuc').value = 'editar';
        document.getElementById('idSuc').value     = btn.dataset.id;
        document.getElementById('inpSucursal').value = btn.dataset.nombre;
        document.getElementById('inpDir').value    = btn.dataset.dir;
        document.getElementById('inpCiudad').value = btn.dataset.ciudad;
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
