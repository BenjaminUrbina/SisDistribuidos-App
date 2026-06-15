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
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= lm_url('cliente.php') ?>">
            <span class="lm-logo-icon"><i class="bi bi-shop-window"></i></span>
            <span class="lm-brand-text">Libre<strong>Mercado</strong></span>
        </a>

        <!-- Search Bar (ML Style) -->
        <form class="d-none d-lg-flex mx-auto" style="width: 40%;" action="<?= lm_url('cliente.php') ?>" method="GET">
            <div class="lm-search-wrap w-100">
                <i class="bi bi-search"></i>
                <input type="text" name="q" class="lm-input form-control w-100" placeholder="Buscar productos, marcas y más...">
                <?php if (($sessionToken ?? 'DEFAULT') !== 'DEFAULT'): ?>
                    <input type="hidden" name="token" value="<?= htmlspecialchars($sessionToken) ?>">
                <?php endif; ?>
            </div>
        </form>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto gap-1 align-items-lg-center">

                <!-- Monitor de Nodos (Siempre visible pero discreto) -->
                <li class="nav-item me-lg-3">
                    <a class="nav-link lm-node-badge d-flex align-items-center gap-2 p-0" href="<?= lm_url('nodos.php') ?>">
                        <span class="lm-pulse-dot <?= $nodosOnline === $totalNodos ? 'online' : ($nodosOnline > 0 ? 'partial' : 'offline') ?>"></span>
                        <small><?= $nodosOnline ?>/<?= $totalNodos ?> Nodos</small>
                    </a>
                </li>

                <?php if ($rolActual === 'cliente'): ?>
                    <li class="nav-item">
                        <a class="nav-link lm-nav-link <?= $paginaActual==='cliente' ? 'active' : '' ?>" href="<?= lm_url('cliente.php') ?>">Catálogo</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link lm-nav-link <?= $paginaActual==='ventas' ? 'active' : '' ?>" href="<?= lm_url('ventas.php') ?>">Mis compras</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link lm-nav-link position-relative <?= $paginaActual==='carrito' ? 'active' : '' ?>" href="<?= lm_url('carrito.php') ?>">
                            <i class="bi bi-cart3 fs-5"></i>
                        </a>
                    </li>

                <?php elseif ($rolActual === 'vendedor'): ?>
                    <li class="nav-item">
                        <a class="nav-link lm-nav-link <?= $paginaActual==='vendedor' ? 'active' : '' ?>" href="<?= lm_url('vendedor.php') ?>">Resumen</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link lm-nav-link <?= $paginaActual==='productos' ? 'active' : '' ?>" href="<?= lm_url('productos.php') ?>">Productos</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link lm-nav-link <?= $paginaActual==='compras' ? 'active' : '' ?>" href="<?= lm_url('compras.php') ?>">Reposición</a>
                    </li>

                <?php else: // Admin o No logueado ?>
                    <li class="nav-item">
                        <a class="nav-link lm-nav-link <?= $paginaActual==='index' ? 'active' : '' ?>" href="<?= lm_url('index.php') ?>">Dashboard</a>
                    </li>

                    <li class="nav-item dropdown">
                        <a class="nav-link lm-nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Gestión</a>
                        <ul class="dropdown-menu lm-dropdown">
                            <li><a class="dropdown-item" href="<?= lm_url('clientes.php') ?>"><i class="bi bi-people me-2"></i>Clientes</a></li>
                            <li><a class="dropdown-item" href="<?= lm_url('sucursales.php') ?>"><i class="bi bi-geo-alt me-2"></i>Sucursales</a></li>
                            <li><a class="dropdown-item" href="<?= lm_url('nodos.php') ?>"><i class="bi bi-diagram-3 me-2"></i>Nodos</a></li>
                        </ul>
                    </li>

                    <li class="nav-item dropdown">
                        <a class="nav-link lm-nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Operaciones</a>
                        <ul class="dropdown-menu lm-dropdown">
                            <li><a class="dropdown-item" href="<?= lm_url('productos.php') ?>"><i class="bi bi-tags me-2"></i>Catálogo</a></li>
                            <li><a class="dropdown-item" href="<?= lm_url('ventas.php') ?>"><i class="bi bi-receipt me-2"></i>Historial Ventas</a></li>
                            <li><a class="dropdown-item" href="<?= lm_url('compras.php') ?>"><i class="bi bi-truck me-2"></i>Historial Compras</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <!-- Usuario Pill -->
                <li class="nav-item ms-lg-2">
                    <?php if ($usuarioActual): ?>
                        <div class="dropdown">
                            <a class="nav-link lm-user-pill dropdown-toggle" href="#" data-bs-toggle="dropdown">
                                <i class="bi bi-person-circle"></i>
                                <span><?= htmlspecialchars($usuarioActual['cliente'] ?? 'Usuario') ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end lm-dropdown">
                                <li class="px-3 py-2 small text-muted border-bottom mb-2" style="border-color:var(--lm-border) !important;">
                                    Rol: <strong><?= ucfirst($rolActual) ?></strong>
                                </li>
                                <li><a class="dropdown-item text-danger" href="<?= lm_url('logout.php') ?>"><i class="bi bi-box-arrow-right me-2"></i>Salir</a></li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <a class="nav-link lm-btn-nodos" href="<?= lm_url('login.php') ?>">
                            <i class="bi bi-person-lock me-1"></i>Ingresar
                        </a>
                    <?php endif; ?>
                </li>
            </ul>
        </div>
    </div>
</nav>
<!-- ════════════════════════════════════════════════════════════════ -->
