<?php
require_once 'includes/auth.php';
lm_requiere_roles(['admin', 'vendedor']);
require_once 'includes/header.php';

/**
 * PRODUCTOS — productos.php
 * ─────────────────────────────────────────────────────────────────────────────
 * TODO: Al conectar la BD, reemplaza los bloques marcados con /* BD *:
 *
 *   $pdo = conectarNodo('principal');
 *
 *   // CREAR
 *   $stmt = $pdo->prepare("INSERT INTO productos (producto, precio, descripcion) VALUES (?,?,?)");
 *   $stmt->execute([$nombre, $precio, $desc]);
 *
 *   // LISTAR
 *   $productos = $pdo->query("SELECT * FROM productos WHERE activo = 1")->fetchAll();
 *
 *   // ACTUALIZAR
 *   $stmt = $pdo->prepare("UPDATE productos SET producto=?, precio=?, descripcion=? WHERE id_prod=?");
 *   $stmt->execute([$nombre, $precio, $desc, $id]);
 *
 *   // BORRADO LÓGICO
 *   $pdo->prepare("UPDATE productos SET activo = 0 WHERE id_prod = ?")->execute([$id]);
 */

$mensaje = '';
$tipoMsg = '';

// ── POST: Crear / Editar producto ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $nombre = htmlspecialchars(trim($_POST['producto'] ?? ''));
    $precio = floatval($_POST['precio'] ?? 0);
    $desc   = htmlspecialchars(trim($_POST['descripcion'] ?? ''));
    $id     = intval($_POST['id_prod'] ?? 0);

    if ($nombre && $precio > 0) {
        try {
            if ($accion === 'crear' || $accion === 'editar') {
                lm_guardar_producto([
                    'id_prod' => $id,
                    'producto' => $nombre,
                    'precio' => $precio,
                    'descripcion' => $desc,
                ]);
                $mensaje = $accion === 'crear' ? "Producto '$nombre' creado exitosamente." : 'Producto actualizado.';
                $tipoMsg = 'success';
            }
        } catch (Throwable $e) {
            $mensaje = $e->getMessage();
            $tipoMsg = 'danger';
        }
    } else {
        $mensaje = "Completa todos los campos obligatorios."; $tipoMsg = 'danger';
    }
}

// ── GET: Borrado lógico ───────────────────────────────────────────────────
if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    try {
        lm_desactivar_producto($id);
        $mensaje = "Producto desactivado (borrado lógico).";
        $tipoMsg = 'warning';
    } catch (Throwable $e) {
        $mensaje = $e->getMessage();
        $tipoMsg = 'danger';
    }
}

// ── LISTAR productos ──────────────────────────────────────────────────────
$productos = lm_catalogo_productos(false);
?>

<div class="lm-page">
<div class="container-fluid">

    <div class="lm-page-header lm-fade-up">
        <div>
            <h1><span>Productos</span></h1>
            <p>Gestión del catálogo &mdash; Nodo principal</p>
        </div>
        <button class="btn-lm-primary btn" data-bs-toggle="modal" data-bs-target="#modalProducto">
            <i class="bi bi-plus-lg me-1"></i>Nuevo Producto
        </button>
    </div>

    <?php if ($mensaje): ?>
    <div class="alert alert-<?= $tipoMsg ?> alert-auto alert-dismissible fade show mb-4">
        <?= $mensaje ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Buscador -->
    <div class="lm-card lm-fade-up">
        <div class="lm-card-header">
            <i class="bi bi-tags"></i> Listado de Productos
            <div class="ms-auto lm-search-wrap" style="width:240px">
                <i class="bi bi-search"></i>
                <input type="text" class="lm-input form-control" id="buscador" placeholder="Buscar producto...">
            </div>
        </div>
        <div class="table-responsive">
            <table class="lm-table" id="tablaProductos">
                <thead>
                    <tr>
                        <th>#ID</th>
                        <th>Producto</th>
                        <th>Precio</th>
                        <th>Descripción</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($productos)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">
                        <i class="bi bi-box-seam d-block fs-2 mb-2"></i>
                        Sin productos — conecta la base de datos o crea el primero
                    </td></tr>
                <?php else: foreach ($productos as $p): ?>
                    <tr>
                        <td class="text-muted">#<?= $p['id_prod'] ?></td>
                        <td><strong><?= htmlspecialchars($p['producto']) ?></strong></td>
                        <td>$<?= number_format($p['precio'], 2) ?></td>
                        <td class="text-muted"><?= htmlspecialchars($p['descripcion']) ?></td>
                        <td><span class="lm-badge <?= (int) ($p['activo'] ?? 1) === 1 ? 'badge-activo' : 'badge-inactivo' ?>"><?= (int) ($p['activo'] ?? 1) === 1 ? 'Activo' : 'Inactivo' ?></span></td>
                        <td>
                            <div class="d-flex gap-1">
                                <!-- Editar: rellena el modal con JS -->
                                <button class="btn-lm-edit btn btn-sm btn-editar"
                                    data-id="<?= $p['id_prod'] ?>"
                                    data-nombre="<?= htmlspecialchars($p['producto']) ?>"
                                    data-precio="<?= $p['precio'] ?>"
                                    data-desc="<?= htmlspecialchars($p['descripcion']) ?>"
                                    data-bs-toggle="modal" data-bs-target="#modalProducto">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <!-- Borrado lógico -->
                                <a href="?eliminar=<?= $p['id_prod'] ?>"
                                   class="btn-lm-danger btn btn-sm btn-eliminar"
                                   title="Desactivar producto">
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

</div>
</div>

<!-- ══════════ Modal Crear/Editar Producto ══════════ -->
<div class="modal fade" id="modalProducto" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitulo"><i class="bi bi-box-seam me-2"></i>Nuevo Producto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="accion" id="formAccion" value="crear">
                <input type="hidden" name="id_prod" id="formId" value="0">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="lm-form-label">Nombre del producto <span class="text-danger">*</span></label>
                        <input type="text" name="producto" id="formNombre" class="lm-input form-control" placeholder="Ej: Laptop Lenovo X1" required>
                    </div>
                    <div class="mb-3">
                        <label class="lm-form-label">Precio (CLP) <span class="text-danger">*</span></label>
                        <input type="number" name="precio" id="formPrecio" class="lm-input form-control" placeholder="0.00" min="0" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="lm-form-label">Descripción</label>
                        <textarea name="descripcion" id="formDesc" class="lm-input form-control" rows="3" placeholder="Descripción del producto..."></textarea>
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

<script>
// Rellenar modal para edición
document.querySelectorAll('.btn-editar').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('modalTitulo').innerHTML = '<i class="bi bi-pencil me-2"></i>Editar Producto';
        document.getElementById('formAccion').value = 'editar';
        document.getElementById('formId').value     = btn.dataset.id;
        document.getElementById('formNombre').value = btn.dataset.nombre;
        document.getElementById('formPrecio').value = btn.dataset.precio;
        document.getElementById('formDesc').value   = btn.dataset.desc;
    });
});
// Limpiar modal al abrir para crear
document.getElementById('modalProducto').addEventListener('show.bs.modal', e => {
    if (!e.relatedTarget?.classList.contains('btn-editar')) {
        document.getElementById('modalTitulo').innerHTML = '<i class="bi bi-box-seam me-2"></i>Nuevo Producto';
        document.getElementById('formAccion').value = 'crear';
        document.getElementById('formId').value     = '0';
        document.getElementById('formNombre').value = '';
        document.getElementById('formPrecio').value = '';
        document.getElementById('formDesc').value   = '';
    }
});
// Buscador en tabla
document.getElementById('buscador').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#tablaProductos tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
