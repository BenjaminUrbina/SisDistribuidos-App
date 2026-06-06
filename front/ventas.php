<?php
require_once 'includes/header.php';

/**
 * HISTORIAL DE VENTAS — ventas.php
 * TODO BD:
 */

$usuario = lm_usuario_actual();
$ventas = (($usuario['rol'] ?? '') === 'cliente' && !empty($usuario['id_cli'])) ? lm_ventas_listar_por_cliente((int) $usuario['id_cli']) : lm_ventas_listar();
?>

<div class="lm-page">
<div class="container-fluid">

    <div class="lm-page-header lm-fade-up">
        <div>
            <h1>Historial de <span>Ventas</span></h1>
            <p>Registro completo de transacciones &mdash; Nodo principal</p>
        </div>
        <a href="carrito.php" class="btn-lm-primary btn"><i class="bi bi-cart-plus me-1"></i>Nueva Venta</a>
    </div>

    <div class="lm-card lm-fade-up">
        <div class="lm-card-header">
            <i class="bi bi-receipt"></i> Ventas
            <div class="ms-auto lm-search-wrap" style="width:240px">
                <i class="bi bi-search"></i>
                <input type="text" class="lm-input form-control" id="buscador" placeholder="Buscar venta...">
            </div>
        </div>
        <div class="table-responsive">
            <table class="lm-table" id="tablaVentas">
                <thead>
                    <tr><th>#Venta</th><th>Cliente</th><th>Sucursal</th><th>Total</th><th>Fecha</th><th>Estado</th><th>Detalle</th></tr>
                </thead>
                <tbody>
                <?php if (empty($ventas)): ?>
                    <tr><td colspan="7" class="text-center py-5 text-muted">
                        <i class="bi bi-receipt d-block fs-2 mb-2"></i>Sin ventas registradas
                    </td></tr>
                <?php else: foreach ($ventas as $v): ?>
                    <tr>
                        <td class="text-muted">#<?= $v['id_venta'] ?></td>
                        <td><?= htmlspecialchars($v['cliente']) ?></td>
                        <td><?= htmlspecialchars($v['sucursal']) ?></td>
                        <td>$<?= number_format($v['total'], 2) ?></td>
                        <td><?= date('d/m/Y H:i', strtotime($v['fecha'])) ?></td>
                        <td>
                            <span class="lm-badge <?= $v['estado']==='pagado' ? 'badge-pagado' : 'badge-pendiente' ?>">
                                <?= ucfirst($v['estado']) ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn-lm-ghost btn btn-sm"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalDetalle"
                                    data-id="<?= $v['id_venta'] ?>">
                                <i class="bi bi-eye"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</div>

<!-- Modal detalle venta -->
<div class="modal fade" id="modalDetalle" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-receipt me-2"></i>Detalle de Venta <span id="idVentaModal"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="contenidoDetalle" class="text-muted text-center py-4">
                    <i class="bi bi-database-slash d-block fs-2 mb-2"></i>
                    Selecciona una venta para ver el detalle.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-lm-ghost btn" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('buscador').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#tablaVentas tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
document.getElementById('modalDetalle').addEventListener('show.bs.modal', e => {
    const id = e.relatedTarget?.dataset.id;
    document.getElementById('idVentaModal').textContent = id ? '#' + id : '';
    if (!id) return;

    fetch('api/detalle_venta.php?id=' + id)
        .then(r => r.json())
        .then(data => {
            if (!data.ok) {
                document.getElementById('contenidoDetalle').innerHTML = '<div class="text-danger">' + (data.error || 'No fue posible cargar el detalle.') + '</div>';
                return;
            }

            const filas = data.detalle.map(item => `
                <tr>
                    <td>${item.producto}</td>
                    <td>${item.cantidad}</td>
                    <td>$${Number(item.precio_unitario).toLocaleString('es-CL')}</td>
                    <td>$${Number(item.subtotal).toLocaleString('es-CL')}</td>
                </tr>
            `).join('');

            document.getElementById('contenidoDetalle').innerHTML = `
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Producto</th><th>Cant.</th><th>Precio</th><th>Subtotal</th></tr></thead>
                        <tbody>${filas}</tbody>
                    </table>
                </div>`;
        })
        .catch(() => {
            document.getElementById('contenidoDetalle').innerHTML = '<div class="text-danger">No fue posible cargar el detalle.</div>';
        });
});
</script>

<?php require_once 'includes/footer.php'; ?>
