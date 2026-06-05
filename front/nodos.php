<?php
require_once 'includes/header.php';

/**
 * MONITOR DE NODOS — nodos.php
 * Muestra el estado en tiempo real de los 3 nodos distribuidos
 * y la justificación de la elección CAP del sistema.
 */

// Estado actualizado
$nodos = [
    'principal' => [
        'nombre'      => 'Nodo Principal',
        'descripcion' => 'Ventas, Clientes, Productos',
        'host'        => DB_HOST_PRINCIPAL . ':' . DB_PORT_PRINCIPAL,
        'db'          => DB_NAME_PRINCIPAL,
        'estado'      => $estadoNodos['principal'],
        'icono'       => 'bi-server',
    ],
    'sucursales' => [
        'nombre'      => 'Nodo Sucursales',
        'descripcion' => 'Stock, Inventario, Sucursales',
        'host'        => DB_HOST_SUCURSALES . ':' . DB_PORT_SUCURSALES,
        'db'          => DB_NAME_SUCURSALES,
        'estado'      => $estadoNodos['sucursales'],
        'icono'       => 'bi-hdd-network',
    ],
    'proveedores' => [
        'nombre'      => 'Nodo Proveedores',
        'descripcion' => 'Compras, Proveedores, Reabastecimiento',
        'host'        => DB_HOST_PROVEEDORES . ':' . DB_PORT_PROVEEDORES,
        'db'          => DB_NAME_PROVEEDORES,
        'estado'      => $estadoNodos['proveedores'],
        'icono'       => 'bi-cloud-arrow-up',
    ],
];
?>

<div class="lm-page">
<div class="container-fluid">

    <div class="lm-page-header lm-fade-up">
        <div>
            <h1>Monitor de <span>Nodos</span></h1>
            <p>Estado de la arquitectura distribuida &mdash; Teorema CAP</p>
        </div>
        <a href="nodos.php" class="btn-lm-ghost btn"><i class="bi bi-arrow-clockwise me-1"></i>Actualizar</a>
    </div>

    <!-- Estado nodos -->
    <div class="row g-3 mb-4 lm-fade-up">
        <?php foreach ($nodos as $key => $n):
            $online = $n['estado'] === 'online';
            $clase  = $online ? 'online' : 'offline';
            $color  = $online ? 'var(--lm-success)' : 'var(--lm-danger)';
        ?>
        <div class="col-md-4">
            <div class="lm-nodo-card <?= $clase ?>">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="lm-stat-icon <?= $online ? 'bg-succ-lm' : 'bg-dang-lm' ?>" style="width:42px;height:42px">
                        <i class="bi <?= $n['icono'] ?>"></i>
                    </div>
                    <span class="lm-badge <?= $online ? 'badge-activo' : 'badge-inactivo' ?>">
                        <?= $online ? 'ONLINE' : 'OFFLINE' ?>
                    </span>
                </div>
                <div class="lm-nodo-title"><?= $n['nombre'] ?></div>
                <p class="text-muted small mb-2"><?= $n['descripcion'] ?></p>
                <hr style="border-color:var(--lm-border);margin:.75rem 0">
                <div class="small text-muted">
                    <div><i class="bi bi-hdd me-1"></i> Host: <code><?= $n['host'] ?></code></div>
                    <div><i class="bi bi-database me-1"></i> BD: <code><?= $n['db'] ?></code></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Teorema CAP -->
    <div class="row g-3">

        <!-- Elección CP -->
        <div class="col-lg-7">
            <div class="lm-card lm-fade-up">
                <div class="lm-card-header">
                    <i class="bi bi-diagram-3 text-warning"></i> Teorema CAP &mdash; Elección del Sistema
                </div>
                <div class="lm-card-body">

                    <!-- Badge elección -->
                    <div class="d-flex align-items-center gap-3 mb-4 p-3"
                         style="background:rgba(163,230,53,.08); border:1px solid var(--lm-accent); border-radius:var(--lm-radius-sm)">
                        <div style="font-family:var(--lm-font-head); font-size:2.5rem; font-weight:800; color:var(--lm-accent); line-height:1">CP</div>
                        <div>
                            <div style="font-family:var(--lm-font-head); font-weight:700;">Consistencia + Tolerancia a Particiones</div>
                            <div class="text-muted small">Libre Mercado prioriza CP sobre Disponibilidad</div>
                        </div>
                    </div>

                    <h6 style="font-family:var(--lm-font-head); font-weight:700; margin-bottom:.75rem;">
                        <i class="bi bi-question-circle me-2 text-info"></i>¿Por qué CP?
                    </h6>
                    <p class="text-muted small">
                        En un sistema de comercio electrónico con stock distribuido, permitir datos
                        inconsistentes (ej. sobrerventa sin stock real) provoca pérdidas económicas y daño reputacional
                        mayor al de una baja temporal de disponibilidad. Por ello se prioriza <strong style="color:var(--lm-text)">Consistencia</strong>
                        frente a la disponibilidad continua.
                    </p>

                    <h6 style="font-family:var(--lm-font-head); font-weight:700; margin:.75rem 0;">
                        <i class="bi bi-lightning-charge me-2 text-warning"></i>Comportamiento ante partición de red
                    </h6>
                    <ul class="text-muted small" style="padding-left:1.2rem; line-height:2">
                        <li>Si el nodo de <strong style="color:var(--lm-text)">sucursales</strong> falla → el sistema <strong style="color:var(--lm-danger)">rechaza nuevas ventas</strong> (no arriesga sobreventa).</li>
                        <li>Si el nodo de <strong style="color:var(--lm-text)">proveedores</strong> falla → las compras quedan en cola; las ventas continúan con stock existente.</li>
                        <li>El commit en 2 fases asegura que ninguna transacción queda a medias entre nodos.</li>
                        <li>Los clientes reciben un mensaje de <em>servicio temporalmente no disponible</em> en vez de datos incorrectos.</li>
                    </ul>

                    <h6 style="font-family:var(--lm-font-head); font-weight:700; margin:.75rem 0;">
                        <i class="bi bi-x-circle me-2 text-danger"></i>¿Por qué NO AP?
                    </h6>
                    <p class="text-muted small mb-0">
                        Un sistema AP permitiría operar con datos desactualizados durante una partición,
                        lo que en e-commerce se traduce en <em>overselling</em> —vender productos sin stock real—,
                        generando conflictos con clientes y proveedores difíciles de revertir.
                    </p>

                </div>
            </div>
        </div>

        <!-- Leyenda CAP visual -->
        <div class="col-lg-5">
            <div class="lm-card lm-fade-up h-100">
                <div class="lm-card-header"><i class="bi bi-info-circle"></i> Resumen de Garantías</div>
                <div class="lm-card-body">

                    <?php
                    $garantias = [
                        ['letra'=>'C', 'nombre'=>'Consistencia',           'activo'=>true,  'desc'=>'Todos los nodos ven los mismos datos al mismo tiempo. Una venta siempre refleja el stock real.'],
                        ['letra'=>'A', 'nombre'=>'Disponibilidad',         'activo'=>false, 'desc'=>'Sacrificada ante partición: si un nodo falla, el sistema puede rechazar solicitudes para proteger la consistencia.'],
                        ['letra'=>'P', 'nombre'=>'Tolerancia a Partición', 'activo'=>true,  'desc'=>'El sistema puede operar aunque algunos nodos pierdan comunicación, manteniendo integridad de datos.'],
                    ];
                    foreach ($garantias as $g): ?>
                    <div class="d-flex gap-3 mb-3 p-3"
                         style="border-radius:var(--lm-radius-sm); background:var(--lm-surface2); border:1px solid var(--lm-border)">
                        <div style="width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;
                                    font-family:var(--lm-font-head);font-weight:800;font-size:1.1rem;flex-shrink:0;
                                    background:<?= $g['activo'] ? 'var(--lm-accent-glow)' : 'rgba(248,113,113,.1)' ?>;
                                    color:<?= $g['activo'] ? 'var(--lm-accent)' : 'var(--lm-danger)' ?>">
                            <?= $g['letra'] ?>
                        </div>
                        <div>
                            <div style="font-family:var(--lm-font-head);font-weight:700;font-size:.9rem">
                                <?= $g['nombre'] ?>
                                <span class="lm-badge <?= $g['activo'] ? 'badge-activo' : 'badge-inactivo' ?> ms-2" style="font-size:.68rem">
                                    <?= $g['activo'] ? 'Garantizado' : 'Sacrificado' ?>
                                </span>
                            </div>
                            <div class="text-muted small mt-1"><?= $g['desc'] ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <!-- Config link -->
                    <div class="mt-3 p-3 text-center" style="background:var(--lm-surface2);border-radius:var(--lm-radius-sm)">
                        <div class="text-muted small mb-2">Edita la configuración de nodos en:</div>
                        <code style="color:var(--lm-accent); font-size:.85rem">includes/config.php</code>
                    </div>

                </div>
            </div>
        </div>

    </div>
</div>
</div>

<?php require_once 'includes/footer.php'; ?>
