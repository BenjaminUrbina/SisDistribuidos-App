<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$paginaActual = basename($_SERVER['PHP_SELF'], '.php');
$estadoNodos = estadoNodos();
$nodosOnline = count(array_filter($estadoNodos, fn($v) => $v === 'online'));
$totalNodos  = count($estadoNodos);
$usuarioActual = lm_usuario_actual();
$rolActual = $usuarioActual['rol'] ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Libre Mercado &mdash; <?= ucfirst($paginaActual) ?></title>

    <!-- Bootstrap 5.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <!-- CSS propio -->
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>

<!-- ═══════════════════════════ NAVBAR ═══════════════════════════ -->
<nav class="navbar navbar-expand-lg navbar-dark lm-navbar sticky-top">
    <div class="container-fluid px-4">

        <!-- Brand -->
        <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
            <span class="lm-logo-icon"><i class="bi bi-shop-window"></i></span>
            <span class="lm-brand-text">Libre<strong>Mercado</strong></span>
        </a>

        <!-- Nodos badge -->
        <span class="lm-node-badge d-none d-md-flex align-items-center gap-1 me-auto ms-4">
            <span class="lm-pulse-dot <?= $nodosOnline === $totalNodos ? 'online' : ($nodosOnline > 0 ? 'partial' : 'offline') ?>"></span>
            <small><?= $nodosOnline ?>/<?= $totalNodos ?> nodos activos</small>
        </span>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto gap-1 align-items-lg-center">
                <?php if ($rolActual === 'cliente'): ?>
                    <li class="nav-item">
                        <a class="nav-link lm-nav-link <?= $paginaActual==='cliente' ? 'active' : '' ?>" href="cliente.php">
                            <i class="bi bi-bag me-1"></i>Cliente
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link lm-nav-link <?= $paginaActual==='carrito' ? 'active' : '' ?>" href="carrito.php">
                            <i class="bi bi-cart3 me-1"></i>Carrito
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link lm-nav-link <?= $paginaActual==='ventas' ? 'active' : '' ?>" href="ventas.php">
                            <i class="bi bi-receipt me-1"></i>Mis compras
                        </a>
                    </li>
                <?php elseif ($rolActual === 'vendedor'): ?>
                    <li class="nav-item">
                        <a class="nav-link lm-nav-link <?= $paginaActual==='vendedor' ? 'active' : '' ?>" href="vendedor.php">
                            <i class="bi bi-shop me-1"></i>Vendedor
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link lm-nav-link <?= $paginaActual==='productos' ? 'active' : '' ?>" href="productos.php">
                            <i class="bi bi-box-seam me-1"></i>Productos
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link lm-nav-link <?= $paginaActual==='compras' ? 'active' : '' ?>" href="compras.php">
                            <i class="bi bi-truck me-1"></i>Compras
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link lm-nav-link <?= $paginaActual==='sucursales' ? 'active' : '' ?>" href="sucursales.php">
                            <i class="bi bi-geo-alt me-1"></i>Sucursales
                        </a>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link lm-nav-link <?= $paginaActual==='index' ? 'active' : '' ?>" href="index.php">
                            <i class="bi bi-speedometer2 me-1"></i>Dashboard
                        </a>
                    </li>

                    <li class="nav-item dropdown">
                        <a class="nav-link lm-nav-link dropdown-toggle <?= in_array($paginaActual,['productos']) ? 'active':'' ?>"
                           href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-box-seam me-1"></i>Catálogo
                        </a>
                        <ul class="dropdown-menu lm-dropdown">
                            <li><a class="dropdown-item" href="productos.php"><i class="bi bi-tags me-2"></i>Productos</a></li>
                        </ul>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link lm-nav-link <?= $paginaActual==='clientes' ? 'active':'' ?>" href="clientes.php">
                            <i class="bi bi-people me-1"></i>Clientes
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link lm-nav-link <?= $paginaActual==='sucursales' ? 'active':'' ?>" href="sucursales.php">
                            <i class="bi bi-geo-alt me-1"></i>Sucursales
                        </a>
                    </li>

                    <li class="nav-item dropdown">
                        <a class="nav-link lm-nav-link dropdown-toggle <?= in_array($paginaActual,['ventas','carrito']) ? 'active':'' ?>"
                           href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-cart3 me-1"></i>Ventas
                        </a>
                        <ul class="dropdown-menu lm-dropdown">
                            <li><a class="dropdown-item" href="carrito.php"><i class="bi bi-cart-plus me-2"></i>Carrito</a></li>
                            <li><a class="dropdown-item" href="ventas.php"><i class="bi bi-receipt me-2"></i>Historial de Ventas</a></li>
                        </ul>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link lm-nav-link <?= $paginaActual==='compras' ? 'active':'' ?>" href="compras.php">
                            <i class="bi bi-truck me-1"></i>Compras
                        </a>
                    </li>

                    <li class="nav-item ms-lg-2">
                        <a class="nav-link lm-btn-nodos <?= $paginaActual==='nodos' ? 'active':'' ?>" href="nodos.php">
                            <i class="bi bi-diagram-3 me-1"></i>Nodos
                        </a>
                    </li>
                <?php endif; ?>

                <li class="nav-item ms-lg-2">
                    <?php if ($usuarioActual): ?>
                        <span class="nav-link lm-user-pill">
                            <i class="bi bi-person-circle"></i>
                            <span><?= htmlspecialchars($usuarioActual['cliente']) ?></span>
                            <span class="lm-badge <?= $rolActual === 'vendedor' ? 'badge-admin' : 'badge-cliente' ?>">
                                <?= ucfirst($rolActual) ?>
                            </span>
                        </span>
                    <?php else: ?>
                        <a class="nav-link lm-btn-nodos" href="login.php">
                            <i class="bi bi-person-lock me-1"></i>Ingresar
                        </a>
                    <?php endif; ?>
                </li>

                <?php if ($usuarioActual): ?>
                    <li class="nav-item">
                        <a class="nav-link lm-nav-link" href="logout.php">
                            <i class="bi bi-box-arrow-right me-1"></i>Salir
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
<!-- ════════════════════════════════════════════════════════════════ -->
