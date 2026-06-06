<?php
// ─── includes ──────────────────────────────────────────────────────────
require_once 'includes/header.php';

$statsData = lm_dashboard_stats();
$stats = [
    ['label' => 'Productos',   'value' => $statsData['productos'], 'icon' => 'bi-box-seam',    'bg' => 'bg-accent'],
    ['label' => 'Clientes',    'value' => $statsData['clientes'], 'icon' => 'bi-people',      'bg' => 'bg-info-lm'],
    ['label' => 'Ventas Hoy',  'value' => $statsData['ventas_hoy'], 'icon' => 'bi-receipt',     'bg' => 'bg-succ-lm'],
    ['label' => 'Sucursales',  'value' => $statsData['sucursales'], 'icon' => 'bi-geo-alt',     'bg' => 'bg-warn-lm'],
];

$ultimasVentas = lm_dashboard_ventas_recientes(5);
$stockBajo     = lm_dashboard_stock_bajo();
?>

<div class="lm-page">
<div class="container-fluid">

    <!-- ── Header ──────────────────────────────────────────────────────── -->
    <div class="lm-page-header lm-fade-up">
        <div>
            <h1>Panel de <span>Control</span></h1>
            <p>Resumen general del sistema distribuido &mdash; <?= date('d \d\e F, Y') ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="nodos.php" class="btn-lm-ghost btn"><i class="bi bi-diagram-3 me-1"></i>Ver nodos</a>
            <a href="ventas.php" class="btn-lm-primary btn"><i class="bi bi-plus-lg me-1"></i>Nueva Venta</a>
        </div>
    </div>

    <!-- ── Alert estado BD ─────────────────────────────────────────────── -->
    <?php
    $online  = count(array_filter($estadoNodos, fn($v) => $v === 'online'));
    $total   = count($estadoNodos);
    if ($online === 0): ?>
        <div class="alert alert-danger alert-auto alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>Sin conexión a los nodos.</strong> Verifica la configuración en <code>includes/config.php</code>.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($online < $total): ?>
        <div class="alert alert-warning alert-auto alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-wifi-off me-2"></i>
            <strong>Degradado:</strong> <?= $online ?>/<?= $total ?> nodos activos. Algunas funciones podrían no estar disponibles.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ── Stat cards ──────────────────────────────────────────────────── -->
    <div class="row g-3 mb-4 lm-fade-up">
        <?php foreach ($stats as $s): ?>
        <div class="col-6 col-lg-3">
            <div class="lm-stat">
                <div class="lm-stat-icon <?= $s['bg'] ?>"><i class="bi <?= $s['icon'] ?>"></i></div>
                <div class="lm-stat-value"><?= $s['value'] ?></div>
                <div class="lm-stat-label"><?= $s['label'] ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Tablas ───────────────────────────────────────────────────────── -->
    <div class="row g-3">

        <!-- Últimas ventas -->
        <div class="col-lg-7">
            <div class="lm-card lm-fade-up">
                <div class="lm-card-header">
                    <i class="bi bi-receipt text-success"></i> Últimas Ventas
                    <a href="ventas.php" class="btn-lm-ghost btn btn-sm ms-auto">Ver todas</a>
                </div>
                <div class="table-responsive">
                    <table class="lm-table">
                        <thead>
                            <tr>
                                <th>#Venta</th>
                                <th>Cliente</th>
                                <th>Sucursal</th>
                                <th>Total</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($ultimasVentas)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">
                                <i class="bi bi-database-slash d-block fs-3 mb-2"></i>
                                Sin datos — conecta la base de datos
                            </td></tr>
                        <?php else: foreach ($ultimasVentas as $v): ?>
                            <tr>
                                <td>#<?= $v['id_venta'] ?></td>
                                <td><?= htmlspecialchars($v['cliente']) ?></td>
                                <td><?= htmlspecialchars($v['sucursal']) ?></td>
                                <td>$<?= number_format($v['total'], 2) ?></td>
                                <td><span class="lm-badge badge-pagado"><?= $v['estado'] ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Stock bajo -->
        <div class="col-lg-5">
            <div class="lm-card lm-fade-up">
                <div class="lm-card-header">
                    <i class="bi bi-exclamation-circle text-warning"></i> Stock Crítico
                    <a href="sucursales.php" class="btn-lm-ghost btn btn-sm ms-auto">Gestionar</a>
                </div>
                <div class="table-responsive">
                    <table class="lm-table">
                        <thead>
                            <tr><th>Producto</th><th>Sucursal</th><th>Stock</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($stockBajo)): ?>
                            <tr><td colspan="3" class="text-center py-4 text-muted">
                                <i class="bi bi-database-slash d-block fs-3 mb-2"></i>
                                Sin datos
                            </td></tr>
                        <?php else: foreach ($stockBajo as $s): ?>
                            <tr>
                                <td><?= htmlspecialchars($s['producto']) ?></td>
                                <td><?= htmlspecialchars($s['sucursal']) ?></td>
                                <td><span class="lm-badge badge-inactivo"><?= $s['cantidad'] ?></span></td>
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
