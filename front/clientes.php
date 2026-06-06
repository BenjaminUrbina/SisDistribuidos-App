<?php
require_once 'includes/header.php';

/**
 * CLIENTES — clientes.php
 * ─────────────────────────────────────────────────────────────────────────────
 * TODO BD:
 *   $pdo = conectarNodo('principal');
 *   $clientes = $pdo->query("SELECT * FROM clientes WHERE activo = 1")->fetchAll();
 *   INSERT INTO clientes (cliente, email, password_hash, rol) VALUES (...)
 *   UPDATE clientes SET ... WHERE id_cli = ?
 *   UPDATE clientes SET activo = 0 WHERE id_cli = ?   ← borrado lógico
 */

$mensaje = ''; $tipoMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $nombre = htmlspecialchars(trim($_POST['cliente'] ?? ''));
    $email  = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $rol    = $_POST['rol'] ?? 'cliente';
    $id     = intval($_POST['id_cli'] ?? 0);
    $telefono = trim($_POST['telefono'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $rut = trim($_POST['rut'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($nombre && $email) {
        try {
            if ($accion === 'crear' || $accion === 'editar') {
                lm_guardar_cliente([
                    'id_cli' => $id,
                    'cliente' => $nombre,
                    'email' => $email,
                    'rol' => $rol,
                    'telefono' => $telefono,
                    'direccion' => $direccion,
                    'rut' => $rut,
                    'password' => $password,
                ]);
                $mensaje = $accion === 'crear' ? "Cliente '$nombre' registrado." : 'Cliente actualizado.';
                $tipoMsg = 'success';
            }
        } catch (Throwable $e) {
            $mensaje = $e->getMessage();
            $tipoMsg = 'danger';
        }
    } else {
        $mensaje = "Nombre y email son obligatorios."; $tipoMsg = 'danger';
    }
}

if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    try {
        lm_desactivar_cliente($id);
        $mensaje = "Cliente desactivado.";
        $tipoMsg = 'warning';
    } catch (Throwable $e) {
        $mensaje = $e->getMessage();
        $tipoMsg = 'danger';
    }
}

$clientes = lm_clientes_listar(false);
?>

<div class="lm-page">
<div class="container-fluid">

    <div class="lm-page-header lm-fade-up">
        <div>
            <h1><span>Clientes</span> y Usuarios</h1>
            <p>Gestión de credenciales y roles del sistema</p>
        </div>
        <button class="btn-lm-primary btn" data-bs-toggle="modal" data-bs-target="#modalCliente">
            <i class="bi bi-person-plus me-1"></i>Nuevo Cliente
        </button>
    </div>

    <?php if ($mensaje): ?>
    <div class="alert alert-<?= $tipoMsg ?> alert-auto alert-dismissible fade show mb-4">
        <?= $mensaje ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="lm-card lm-fade-up">
        <div class="lm-card-header">
            <i class="bi bi-people"></i> Listado de Clientes
            <div class="ms-auto lm-search-wrap" style="width:240px">
                <i class="bi bi-search"></i>
                <input type="text" class="lm-input form-control" id="buscador" placeholder="Buscar...">
            </div>
        </div>
        <div class="table-responsive">
            <table class="lm-table" id="tablaClientes">
                <thead>
                    <tr>
                        <th>#ID</th><th>Cliente</th><th>Email</th><th>Rol</th><th>Estado</th><th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($clientes)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">
                        <i class="bi bi-people d-block fs-2 mb-2"></i>Sin clientes registrados
                    </td></tr>
                <?php else: foreach ($clientes as $c): ?>
                    <?php $rol = (string) ($c['rol'] ?? 'cliente'); ?>
                    <tr>
                        <td class="text-muted">#<?= $c['id_cli'] ?></td>
                        <td><strong><?= htmlspecialchars($c['cliente']) ?></strong></td>
                        <td class="text-muted"><?= htmlspecialchars($c['email']) ?></td>
                        <td>
                            <span class="lm-badge <?= $rol==='admin' ? 'badge-admin' : ($rol==='vendedor' ? 'badge-pendiente' : 'badge-cliente') ?>">
                                <?= ucfirst($rol) ?>
                            </span>
                        </td>
                        <td><span class="lm-badge <?= $c['activo'] ? 'badge-activo' : 'badge-inactivo' ?>">
                            <?= $c['activo'] ? 'Activo' : 'Inactivo' ?>
                        </span></td>
                        <td>
                            <div class="d-flex gap-1">
                                <button class="btn-lm-edit btn btn-sm btn-editar"
                                    data-id="<?= $c['id_cli'] ?>"
                                    data-nombre="<?= htmlspecialchars($c['cliente']) ?>"
                                    data-email="<?= htmlspecialchars($c['email']) ?>"
                                    data-rol="<?= htmlspecialchars($rol) ?>"
                                    data-bs-toggle="modal" data-bs-target="#modalCliente">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <a href="?eliminar=<?= $c['id_cli'] ?>"
                                   class="btn-lm-danger btn btn-sm btn-eliminar">
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

<!-- Modal Cliente -->
<div class="modal fade" id="modalCliente" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitulo"><i class="bi bi-person-plus me-2"></i>Nuevo Cliente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="accion" id="formAccion" value="crear">
                <input type="hidden" name="id_cli"  id="formId"    value="0">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="lm-form-label">Nombre completo <span class="text-danger">*</span></label>
                        <input type="text" name="cliente" id="formNombre" class="lm-input form-control" placeholder="Nombre Apellido" required>
                    </div>
                    <div class="mb-3">
                        <label class="lm-form-label">Correo electrónico <span class="text-danger">*</span></label>
                        <input type="email" name="email" id="formEmail" class="lm-input form-control" placeholder="correo@ejemplo.cl" required>
                    </div>
                    <div class="mb-3">
                        <label class="lm-form-label">Contraseña <small class="text-muted">(solo al crear)</small></label>
                        <input type="password" name="password" class="lm-input form-control" placeholder="Mínimo 8 caracteres">
                    </div>
                    <div class="mb-3">
                        <label class="lm-form-label">Teléfono</label>
                        <input type="text" name="telefono" class="lm-input form-control" placeholder="+56 9 XXXX XXXX">
                    </div>
                    <div class="mb-3">
                        <label class="lm-form-label">Dirección</label>
                        <input type="text" name="direccion" class="lm-input form-control" placeholder="Calle y número">
                    </div>
                    <div class="mb-3">
                        <label class="lm-form-label">RUT</label>
                        <input type="text" name="rut" class="lm-input form-control" placeholder="12.345.678-9">
                    </div>
                    <div class="mb-3">
                        <label class="lm-form-label">Rol</label>
                        <select name="rol" id="formRol" class="lm-input form-select">
                            <option value="cliente">Cliente</option>
                            <option value="admin">Administrador</option>
                            <option value="vendedor">Vendedor</option>
                        </select>
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
document.querySelectorAll('.btn-editar').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('modalTitulo').innerHTML = '<i class="bi bi-pencil me-2"></i>Editar Cliente';
        document.getElementById('formAccion').value = 'editar';
        document.getElementById('formId').value     = btn.dataset.id;
        document.getElementById('formNombre').value = btn.dataset.nombre;
        document.getElementById('formEmail').value  = btn.dataset.email;
        document.getElementById('formRol').value    = btn.dataset.rol;
    });
});
document.getElementById('modalCliente').addEventListener('show.bs.modal', e => {
    if (!e.relatedTarget?.classList.contains('btn-editar')) {
        document.getElementById('modalTitulo').innerHTML = '<i class="bi bi-person-plus me-2"></i>Nuevo Cliente';
        document.getElementById('formAccion').value = 'crear';
        document.getElementById('formId').value = '0';
        ['formNombre','formEmail'].forEach(id => document.getElementById(id).value = '');
    }
});
document.getElementById('buscador').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#tablaClientes tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
