<?php
require_once 'includes/auth.php';
lm_requiere_roles(['admin', 'vendedor']);
require_once 'includes/header.php';

/**
 * COMPRAS Y PROVEEDORES — compras.php
 * ─────────────────────────────────────────────────────────────────────────────
 * TODO BD (Nodo: proveedores):
 *   $pdo = conectarNodo('proveedores');
 *   $proveedores = $pdo->query("SELECT * FROM proveedores WHERE activo=1")->fetchAll();
 *   $compras     = $pdo->query("SELECT c.*, p.proveedor FROM compras c
 *                               JOIN proveedores p ON c.id_prov = p.id_prov
 *                               ORDER BY c.fecha DESC")->fetchAll();
 *
 *   // Al crear compra → también actualizar stock en nodo sucursales (ACID):
 *   // $pdoSuc = conectarNodo('sucursales');
 *   // Iniciar transacción en ambos nodos, insertar compra + detalle + sumar stock.
 */

$mensaje = ''; $tipoMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion   = $_POST['accion'] ?? '';
    $nombre   = htmlspecialchars(trim($_POST['proveedor'] ?? ''));
    $contacto = htmlspecialchars(trim($_POST['contacto'] ?? ''));
    $id       = intval($_POST['id_prov'] ?? 0);

    try {
        if ($accion === 'crear_prov' && $nombre) {
            lm_guardar_proveedor([
                'id_prov' => 0,
                'proveedor' => $nombre,
                'contacto' => $contacto,
            ]);
            $mensaje = "Proveedor '$nombre' registrado."; $tipoMsg = 'success';
        } elseif ($accion === 'editar_prov' && $id > 0) {
            lm_guardar_proveedor([
                'id_prov' => $id,
                'proveedor' => $nombre,
                'contacto' => $contacto,
            ]);
            $mensaje = "Proveedor actualizado."; $tipoMsg = 'success';
        } elseif ($accion === 'crear_compra') {
            $id_prov = intval($_POST['id_prov_compra'] ?? 0);
            $id_prod = intval($_POST['id_prod_compra'] ?? 0);
            $id_suc  = intval($_POST['id_suc_compra']  ?? 0);
            $cant    = intval($_POST['cantidad_compra'] ?? 0);
            $precio  = floatval($_POST['precio_compra'] ?? 0);
            if ($id_prov && $id_prod && $id_suc && $cant > 0) {
                lm_registrar_compra($id_prov, $id_suc, $id_prod, $cant, $precio);
                $mensaje = "Compra registrada y stock actualizado (ACID)."; $tipoMsg = 'success';
            } else {
                $mensaje = "Completa todos los campos."; $tipoMsg = 'danger';
            }
        }
    } catch (Throwable $e) {
        $mensaje = $e->getMessage(); $tipoMsg = 'danger';
    }
}

if (isset($_GET['eliminar_prov'])) {
    $id = intval($_GET['eliminar_prov']);
    try {
        lm_desactivar_proveedor($id);
        $mensaje = "Proveedor desactivado."; $tipoMsg = 'warning';
    } catch (Throwable $e) {
        $mensaje = $e->getMessage(); $tipoMsg = 'danger';
    }
}

$proveedores = lm_proveedores_listar(false);
$compras     = lm_compras_listar();
$productos   = lm_catalogo_productos(true);
$sucursales  = lm_sucursales_operativas();
?>

<div class="lm-page">
<div class="container-fluid">

    <div class="lm-page-header lm-fade-up">
        <div>
            <h1><span>Compras</span> y Proveedores</h1>
            <p>Gestión de reabastecimiento &mdash; Nodo proveedores</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn-lm-ghost btn" data-bs-toggle="modal" data-bs-target="#modalProveedor">
                <i class="bi bi-person-badge me-1"></i>Nuevo Proveedor
            </button>
            <button class="btn-lm-primary btn" data-bs-toggle="modal" data-bs-target="#modalCompra">
                <i class="bi bi-truck me-1"></i>Nueva Compra
            </button>
        </div>
    </div>

    <?php if ($mensaje): ?>
    <div class="alert alert-<?= $tipoMsg ?> alert-auto alert-dismissible fade show mb-4">
        <?= $mensaje ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Proveedores -->
        <div class="col-lg-5">
            <div class="lm-card lm-fade-up">
                <div class="lm-card-header"><i class="bi bi-person-badge"></i> Proveedores</div>
                <div class="table-responsive">
                    <table class="lm-table">
                        <thead><tr><th>#ID</th><th>Proveedor</th><th>Contacto</th><th></th></tr></thead>
                        <tbody>
                        <?php if (empty($proveedores)): ?>
                            <tr><td colspan="4" class="text-center py-4 text-muted">
                                <i class="bi bi-person-badge d-block fs-2 mb-2"></i>Sin proveedores
                            </td></tr>
                        <?php else: foreach ($proveedores as $p): ?>
                            <tr>
                                <td class="text-muted">#<?= (int) ($p['id_proveedor'] ?? 0) ?></td>
                                <td><strong><?= htmlspecialchars((string) ($p['proveedor'] ?? '')) ?></strong></td>
                                <td class="text-muted"><?= htmlspecialchars((string) ($p['contacto'] ?? ($p['telefono'] ?? '')) ) ?></td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <button class="btn-lm-edit btn btn-sm btn-editar-prov"
                                            data-id="<?= (int) ($p['id_proveedor'] ?? 0) ?>"
                                            data-nombre="<?= htmlspecialchars((string) ($p['proveedor'] ?? '')) ?>"
                                            data-contacto="<?= htmlspecialchars((string) ($p['contacto'] ?? ($p['telefono'] ?? '')) ) ?>"
                                            data-bs-toggle="modal" data-bs-target="#modalProveedor">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <a href="?eliminar_prov=<?= (int) ($p['id_proveedor'] ?? 0) ?>" class="btn-lm-danger btn btn-sm btn-eliminar">
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

        <!-- Historial compras -->
        <div class="col-lg-7">
            <div class="lm-card lm-fade-up">
                <div class="lm-card-header"><i class="bi bi-truck"></i> Historial de Compras</div>
                <div class="table-responsive">
                    <table class="lm-table">
                        <thead>
                            <tr><th>#Compra</th><th>Proveedor</th><th>Producto</th><th>Cant.</th><th>Total</th><th>Fecha</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($compras)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">
                                <i class="bi bi-truck d-block fs-2 mb-2"></i>Sin compras registradas
                            </td></tr>
                        <?php else: foreach ($compras as $c): ?>
                            <tr>
                                <td class="text-muted">#<?= (int) ($c['id_compra'] ?? 0) ?></td>
                                <td><?= htmlspecialchars((string) ($c['proveedor'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($c['producto'] ?? 'Sin producto')) ?></td>
                                <td><?= (int) ($c['cantidad'] ?? 0) ?></td>
                                <td>$<?= number_format((float) ($c['total'] ?? 0), 2) ?></td>
                                <td><?= date('d/m/Y', strtotime((string) ($c['fecha'] ?? date('Y-m-d')))) ?></td>
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

<!-- Modal Proveedor -->
<div class="modal fade" id="modalProveedor" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="tituloProveedor"><i class="bi bi-person-badge me-2"></i>Nuevo Proveedor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="accion" id="accionProv" value="crear_prov">
                <input type="hidden" name="id_prov" id="idProv" value="0">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="lm-form-label">Nombre del proveedor <span class="text-danger">*</span></label>
                        <input type="text" name="proveedor" id="inpProvNombre" class="lm-input form-control" placeholder="Empresa o persona" required>
                    </div>
                    <div class="mb-3">
                        <label class="lm-form-label">Contacto / Teléfono</label>
                        <input type="text" name="contacto" id="inpProvContacto" class="lm-input form-control" placeholder="+56 9 XXXX XXXX">
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

<!-- Modal Compra -->
<div class="modal fade" id="modalCompra" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-truck me-2"></i>Registrar Compra</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="accion" value="crear_compra">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="lm-form-label">Proveedor <span class="text-danger">*</span></label>
                        <select name="id_prov_compra" class="lm-input form-select" required>
                            <option value="">Seleccionar...</option>
                            <?php foreach ($proveedores as $p): ?>
                            <option value="<?= $p['id_proveedor'] ?>"><?= htmlspecialchars($p['proveedor']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="lm-form-label">Producto <span class="text-danger">*</span></label>
                        <select name="id_prod_compra" class="lm-input form-select" required>
                            <option value="">Seleccionar...</option>
                            <?php foreach ($productos as $p): ?>
                                <option value="<?= $p['id_prod'] ?>"><?= htmlspecialchars($p['producto']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="lm-form-label">Sucursal destino</label>
                            <select name="id_suc_compra" class="lm-input form-select" required>
                                <option value="">Seleccionar...</option>
                                <?php foreach ($sucursales as $s): ?>
                                    <option value="<?= $s['id_suc'] ?>"><?= htmlspecialchars($s['sucursal']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="lm-form-label">Cantidad</label>
                            <input type="number" name="cantidad_compra" class="lm-input form-control" min="1" placeholder="0" required>
                        </div>
                        <div class="col-3">
                            <label class="lm-form-label">P. Unit.</label>
                            <input type="number" name="precio_compra" class="lm-input form-control" min="0" step="0.01" placeholder="0.00" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-lm-ghost btn" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn-lm-primary btn"><i class="bi bi-check2 me-1"></i>Registrar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.btn-editar-prov').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('tituloProveedor').innerHTML = '<i class="bi bi-pencil me-2"></i>Editar Proveedor';
        document.getElementById('accionProv').value    = 'editar_prov';
        document.getElementById('idProv').value        = btn.dataset.id;
        document.getElementById('inpProvNombre').value = btn.dataset.nombre;
        document.getElementById('inpProvContacto').value = btn.dataset.contacto;
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
