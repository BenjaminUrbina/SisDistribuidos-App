<?php
require_once 'includes/header.php';

/**
 * HISTORIAL DE VENTAS — ventas.php
 * TODO BD:
 *   $pdo = conectarNodo('principal');
 *   $ventas = $pdo->query("
 *       SELECT v.id_venta, c.cliente, s.sucursal, v.total, v.fecha, v.estado
 *       FROM ventas v
 *       JOIN clientes c ON v.id_cli = c.id_cli
 *       JOIN sucursales s ON v.id_suc = s.id_suc
 *       ORDER BY v.fecha DESC
 *   ")->fetchAll();
 *
 *   // Detalle de una venta:
 *   $detalle = $pdo->prepare("
 *       SELECT dv.*, p.producto FROM detalle_ventas dv
 *       JOIN productos p ON dv.id_prod = p.id_prod
 *       WHERE dv.id_venta = ?
 *   ")->execute([$id])->fetchAll();
 */

$ventas = []; /* BD */
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
                    Conecta la BD para cargar el detalle vía AJAX / PDO.
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
    // TODO: fetch('api/detalle_venta.php?id=' + id).then(r=>r.json()).then(renderDetalle)
});
</script>

<?php require_once 'includes/footer.php'; ?>
