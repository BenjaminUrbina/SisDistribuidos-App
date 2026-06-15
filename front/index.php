<?php
require_once 'includes/header.php';

$usuarioActual = lm_usuario_actual();
$rolActual = $usuarioActual['rol'] ?? 'publico';

// Redirección para usuarios logueados
if ($rolActual === 'cliente') {
    header('Location: ' . lm_url('cliente.php'));
    exit;
} elseif ($rolActual === 'vendedor') {
    header('Location: ' . lm_url('vendedor.php'));
    exit;
}

// Si es Administrador o Invitado
$statsData = lm_dashboard_stats();
$stats = [
    ['label' => 'Productos',   'value' => $statsData['productos'], 'icon' => 'bi-box-seam',    'bg' => 'bg-accent'],
    ['label' => 'Clientes',    'value' => $statsData['clientes'], 'icon' => 'bi-people',      'bg' => 'bg-info-lm'],
    ['label' => 'Ventas Hoy',  'value' => $statsData['ventas_hoy'], 'icon' => 'bi-receipt',     'bg' => 'bg-succ-lm'],
    ['label' => 'Sucursales',  'value' => $statsData['sucursales'], 'icon' => 'bi-geo-alt',     'bg' => 'bg-warn-lm'],
];

$ultimasVentas = lm_dashboard_ventas_recientes(5);
?>

<div class="lm-page">
<div class="container-fluid">

    <?php if ($rolActual === 'publico'): ?>
    <!-- Hero para Invitados -->
    <div class="lm-card p-5 mb-4 text-center border-0 lm-fade-up" style="background: linear-gradient(135deg, var(--lm-surface) 0%, var(--lm-bg) 100%);">
        <h1 class="display-4 fw-bold mb-3">Bienvenido a <span class="text-accent">Libre Mercado</span></h1>
        <p class="lead text-muted mb-4 mx-auto" style="max-width: 700px;">
            La primera plataforma de comercio electrónico distribuida con consistencia garantizada y 
            transacciones ACID reales sobre múltiples nodos.
        </p>
        <div class="d-flex justify-content-center gap-3">
            <a href="<?= lm_url('cliente.php') ?>" class="btn btn-lm-primary btn-lg px-5">Explorar Catálogo</a>
            <a href="<?= lm_url('login.php') ?>" class="btn btn-outline-light btn-lg px-5">Iniciar Sesión</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Panel de Control (Visible para Admin o Resumen para Publico) -->
    <div class="lm-page-header lm-fade-up">
        <div>
            <h1>Dashboard <span>Global</span></h1>
            <p>Estado del sistema distribuido &mdash; <?= date('d \d\e F, Y') ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="nodos.php" class="btn-lm-ghost btn"><i class="bi bi-diagram-3 me-1"></i>Estado de Nodos</a>
            <?php if ($rolActual === 'admin'): ?>
                <a href="ventas.php" class="btn-lm-primary btn"><i class="bi bi-receipt me-1"></i>Ver Ventas</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stat cards -->
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

    <div class="row g-3">
        <!-- Últimas ventas (Visible solo para Admin) -->
        <?php if ($rolActual === 'admin'): ?>
        <div class="col-lg-12">
            <div class="lm-card lm-fade-up">
                <div class="lm-card-header">
                    <i class="bi bi-receipt text-success"></i> Monitor de Ventas Globales
                    <a href="ventas.php" class="btn-lm-ghost btn btn-sm ms-auto">Ver historial completo</a>
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
                            <tr><td colspan="5" class="text-center py-4 text-muted">Sin datos en la red central</td></tr>
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
        <?php else: ?>
        <!-- Info para invitados -->
        <div class="col-lg-6">
            <div class="lm-card h-100 lm-fade-up">
                <div class="lm-card-header"><i class="bi bi-shield-check text-success"></i> Garantía ACID</div>
                <div class="lm-card-body">
                    <p class="small text-muted">
                        Cada vez que compras, nuestro sistema coordina múltiples bases de datos para asegurar que el 
                        descuento de stock y el registro de tu pago ocurran al mismo tiempo o no ocurran en absoluto.
                    </p>
                    <ul class="small text-muted">
                        <li><strong>Atomicidad:</strong> O se completa todo, o no se hace nada.</li>
                        <li><strong>Consistencia:</strong> Los datos siempre son válidos.</li>
                        <li><strong>Aislamiento:</strong> Las ventas concurrentes no chocan.</li>
                        <li><strong>Durabilidad:</strong> Tu compra está segura en disco.</li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="lm-card h-100 lm-fade-up">
                <div class="lm-card-header"><i class="bi bi-diagram-3 text-warning"></i> Arquitectura CP</div>
                <div class="lm-card-body">
                    <p class="small text-muted">
                        Preferimos la Consistencia sobre la Disponibilidad. Si un nodo de stock falla, el sistema 
                        bloquea las ventas de ese producto para evitar sobreventa y mantener la integridad del negocio.
                    </p>
                    <a href="nodos.php" class="btn btn-lm-ghost btn-sm mt-2">Ver monitor de red</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<?php require_once 'includes/footer.php'; ?>
