<?php
/**
 * TEST DE CONCURRENCIA - 2 USUARIOS SIMULTÁNEOS
 * Libre Mercado - Sistema Distribuido
 * 
 * Permite abrir 2 pestañas con sesiones diferentes para probar:
 * - Compras simultáneas del mismo producto
 * - Race conditions y locks
 * - Rollbacks por stock insuficiente
 * - Resistencia a partición (caída de nodos)
 */

require_once __DIR__ . '/includes/config.php';

// Token de sesión para diferenciar usuarios
$sessionToken = $_GET['token'] ?? 'USER1';
session_name('LM_TEST_' . preg_replace('/[^a-zA-Z0-9]/', '', $sessionToken));
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$mensaje = '';
$tipoMsg = '';
$logsRecientes = [];

// Acciones del formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    try {
        if ($accion === 'comprar') {
            $idCli = (int) ($_POST['id_cli'] ?? 1);
            $idSuc = (int) ($_POST['id_suc'] ?? 2);
            $idProd = (int) ($_POST['id_prod'] ?? 1);
            $cantidad = max(1, (int) ($_POST['cantidad'] ?? 1));
            
            // Crear items para la venta
            $producto = lm_producto_por_id($idProd);
            $items = [[
                'id_prod' => $idProd,
                'cantidad' => $cantidad,
                'precio_unitario' => (float) ($producto['precio'] ?? 0),
            ]];
            
            // Registrar venta usando SP
            $resultado = sp_registrar_venta_2pc($idCli, $idSuc, $items, null);
            
            $mensaje = "✅ Compra exitosa! Venta #{$resultado['id_venta']} - Total: $" . number_format($resultado['total'], 0, ',', '.');
            $tipoMsg = 'success';
            
        } elseif ($accion === 'test_concurrencia') {
            $idProd = (int) ($_POST['id_prod_test'] ?? 1);
            $operaciones = min(10, max(1, (int) ($_POST['cantidad_operaciones'] ?? 5)));
            
            $resultado = sp_testear_concurrencia($idProd, $operaciones, 1);
            
            $mensaje = "✅ Test completado! ";
            if ($resultado['estadisticas']) {
                $stats = $resultado['estadisticas'];
                $mensaje .= "Exitosas: {$stats['operaciones_exitosas']}, Fallidas: {$stats['operaciones_fallidas']}, Stock final: {$stats['stock_final']}";
            }
            $tipoMsg = 'info';
            
        } elseif ($accion === 'recuperar') {
            $resultado = sp_simular_recuperacion_post_caida();
            $mensaje = "✅ Recuperación completada. Discrepancias encontradas: " . count($resultado);
            $tipoMsg = 'info';
            
        } elseif ($accion === 'limpiar_logs') {
            $eliminados = sp_logs_limpiar(30);
            $mensaje = "✅ Logs antiguos eliminados: {$eliminados}";
            $tipoMsg = 'success';
        }
        
    } catch (Throwable $e) {
        $mensaje = "❌ Error: " . $e->getMessage();
        $tipoMsg = 'danger';
    }
}

// Obtener logs recientes
try {
    $logsRecientes = sp_logs_transacciones(20, null, null);
} catch (Throwable $e) {
    // Ignorar si no hay logs
}

// Obtener productos y sucursales
$productos = lm_catalogo_productos(true);
$sucursales = lm_sucursales_operativas();
$clientes = lm_clientes_listar(true);

// Obtener stock actual
$stockActual = sp_stock_consultar();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Test de Concurrencia - Libre Mercado</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .user-badge {
            position: fixed;
            top: 10px;
            right: 10px;
            z-index: 9999;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .user-1 { background: #4CAF50; color: white; }
        .user-2 { background: #2196F3; color: white; }
        .log-row { font-size: 0.85rem; }
        .log-success { border-left: 3px solid #4CAF50; }
        .log-error { border-left: 3px solid #f44336; }
        .log-info { border-left: 3px solid #2196F3; }
        .stock-card {
            transition: all 0.3s ease;
        }
        .stock-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
    </style>
</head>
<body style="background: var(--lm-bg); color: var(--lm-text);">
    
    <!-- Badge de usuario -->
    <div class="user-badge <?= $sessionToken === 'USER2' ? 'user-2' : 'user-1' ?>">
        <?= $sessionToken === 'USER2' ? '👤 Usuario 2' : '👤 Usuario 1' ?>
        <small class="ms-2">(<?= htmlspecialchars($sessionToken) ?>)</small>
    </div>

    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1><i class="bi bi-people"></i> Test de Concurrencia</h1>
                        <p class="text-muted">Prueba compras simultáneas con 2 usuarios en pestañas diferentes</p>
                    </div>
                    <div>
                        <a href="?token=USER1" class="btn btn-outline-success me-2" target="_blank">
                            <i class="bi bi-person-check"></i> Abrir Usuario 1
                        </a>
                        <a href="?token=USER2" class="btn btn-outline-primary" target="_blank">
                            <i class="bi bi-person-check"></i> Abrir Usuario 2
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipoMsg ?> alert-dismissible fade show">
            <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            
            <!-- Panel de Compra -->
            <div class="col-lg-4">
                <div class="lm-card">
                    <div class="lm-card-header">
                        <i class="bi bi-cart-check"></i> Realizar Compra
                    </div>
                    <div class="lm-card-body">
                        <form method="POST">
                            <input type="hidden" name="accion" value="comprar">
                            
                            <div class="mb-3">
                                <label class="form-label">Cliente</label>
                                <select name="id_cli" class="form-select" required>
                                    <?php foreach ($clientes as $c): ?>
                                    <option value="<?= $c['id_cli'] ?>"><?= htmlspecialchars($c['cliente']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Sucursal</label>
                                <select name="id_suc" class="form-select" required>
                                    <?php foreach ($sucursales as $s): ?>
                                    <option value="<?= $s['id_suc'] ?>"><?= htmlspecialchars($s['sucursal']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Producto</label>
                                <select name="id_prod" class="form-select" required>
                                    <?php foreach ($productos as $p): ?>
                                    <option value="<?= $p['id_prod'] ?>">
                                        <?= htmlspecialchars($p['producto']) ?> - $<?= number_format($p['precio'], 0) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Cantidad</label>
                                <input type="number" name="cantidad" class="form-control" value="1" min="1" max="10">
                            </div>
                            
                            <button type="submit" class="btn btn-lm-primary w-100">
                                <i class="bi bi-bag-check"></i> Comprar Ahora
                            </button>
                        </form>
                        
                        <div class="alert alert-info mt-3 small">
                            <i class="bi bi-info-circle"></i> 
                            Abre esta página en 2 pestañas con diferentes tokens para simular usuarios concurrentes.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Panel de Stock -->
            <div class="col-lg-4">
                <div class="lm-card">
                    <div class="lm-card-header">
                        <i class="bi bi-boxes"></i> Stock Actual por Sucursal
                    </div>
                    <div class="lm-card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th>Sucursal</th>
                                        <th>Stock</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stockActual as $item): 
                                        $estado = $item['cantidad'] <= 0 ? 'sin_stock' : 
                                                  ($item['cantidad'] <= $item['stock_minimo'] ? 'bajo_stock' : 'disponible');
                                        $claseEstado = $estado === 'disponible' ? 'bg-success' : 
                                                       ($estado === 'bajo_stock' ? 'bg-warning' : 'bg-danger');
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['producto']) ?></td>
                                        <td><?= htmlspecialchars($item['sucursal']) ?></td>
                                        <td><strong><?= $item['cantidad'] ?></strong></td>
                                        <td>
                                            <span class="badge <?= $claseEstado ?>">
                                                <?= str_replace('_', ' ', $estado) ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <button onclick="location.reload()" class="btn btn-outline-secondary btn-sm w-100 mt-2">
                            <i class="bi bi-arrow-clockwise"></i> Actualizar
                        </button>
                    </div>
                </div>
            </div>

            <!-- Panel de Testing -->
            <div class="col-lg-4">
                <div class="lm-card">
                    <div class="lm-card-header">
                        <i class="bi bi-speedometer2"></i> Tests Avanzados
                    </div>
                    <div class="lm-card-body">
                        
                        <!-- Test de Concurrencia -->
                        <form method="POST" class="mb-3">
                            <input type="hidden" name="accion" value="test_concurrencia">
                            <div class="mb-2">
                                <label class="form-label small">Producto para test</label>
                                <select name="id_prod_test" class="form-select form-select-sm">
                                    <?php foreach ($productos as $p): ?>
                                    <option value="<?= $p['id_prod'] ?>"><?= htmlspecialchars($p['producto']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Operaciones</label>
                                <input type="number" name="cantidad_operaciones" class="form-control form-control-sm" value="5" min="1" max="10">
                            </div>
                            <button type="submit" class="btn btn-info btn-sm w-100">
                                <i class="bi bi-play"></i> Ejecutar Test Concurrencia
                            </button>
                        </form>
                        
                        <hr>
                        
                        <!-- Recuperación post caída -->
                        <form method="POST" class="mb-3">
                            <input type="hidden" name="accion" value="recuperar">
                            <button type="submit" class="btn btn-warning btn-sm w-100">
                                <i class="bi bi-arrow-repeat"></i> Simular Recuperación Post Caída
                            </button>
                        </form>
                        
                        <!-- Limpiar logs -->
                        <form method="POST">
                            <input type="hidden" name="accion" value="limpiar_logs">
                            <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                                <i class="bi bi-trash"></i> Limpiar Logs Antiguos
                            </button>
                        </form>
                        
                    </div>
                </div>
            </div>

        </div>

        <!-- Logs de Transacciones -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="lm-card">
                    <div class="lm-card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-journal-text"></i> Logs de Transacciones Recientes</span>
                        <span class="badge bg-secondary"><?= count($logsRecientes) ?> registros</span>
                    </div>
                    <div class="lm-card-body">
                        <div class="table-responsive">
                            <table class="table table-hover table-sm">
                                <thead>
                                    <tr>
                                        <th>ID Transacción</th>
                                        <th>Tipo</th>
                                        <th>Cliente</th>
                                        <th>Producto</th>
                                        <th>Cantidad</th>
                                        <th>Estado</th>
                                        <th>Nodo</th>
                                        <th>Fecha</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($logsRecientes)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">
                                            <i class="bi bi-inbox d-block fs-3 mb-2"></i>
                                            Sin logs registrados
                                        </td>
                                    </tr>
                                    <?php else: foreach ($logsRecientes as $log): ?>
                                    <tr class="log-row log-<?= $log['estado'] ?>">
                                        <td><code><?= htmlspecialchars($log['id_transaccion']) ?></code></td>
                                        <td>
                                            <span class="badge bg-<?= $log['tipo_operacion'] === 'venta' ? 'primary' : 
                                                                ($log['tipo_operacion'] === 'compra' ? 'success' : 'info') ?>">
                                                <?= ucfirst($log['tipo_operacion']) ?>
                                            </span>
                                        </td>
                                        <td><?= $log['id_cliente'] ? '#' . $log['id_cliente'] : '-' ?></td>
                                        <td><?= $log['id_producto'] ? '#' . $log['id_producto'] : '-' ?></td>
                                        <td><?= $log['cantidad'] ?? '-' ?></td>
                                        <td>
                                            <span class="badge badge-<?= $log['estado'] === 'confirmada' ? 'success' : 
                                                                      ($log['estado'] === 'fallida' ? 'danger' : 'warning') ?>">
                                                <?= ucfirst($log['estado']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($log['nodo_origen']) ?></td>
                                        <td><?= date('d/m H:i', strtotime($log['fecha_creacion'])) ?></td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Instrucciones -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="alert alert-light border">
                    <h5><i class="bi bi-lightbulb"></i> Cómo probar concurrencia:</h5>
                    <ol class="mb-0">
                        <li>Abre <strong>dos pestañas</strong> del navegador con diferentes tokens (Usuario 1 y Usuario 2)</li>
                        <li>En ambas pestañas, selecciona el <strong>mismo producto</strong> y sucursal</li>
                        <li>Haz clic en "Comprar Ahora" <strong>simultáneamente</strong> en ambas pestañas</li>
                        <li>Observa cómo el sistema maneja los locks:
                            <ul>
                                <li>✅ La primera compra se completa exitosamente</li>
                                <li>⚠️ La segunda compra puede fallar si el stock es insuficiente</li>
                                <li>📝 Ambos intentos quedan registrados en la tabla de logs</li>
                            </ul>
                        </li>
                        <li>Para probar resistencia a partición:
                            <ul>
                                <li>Ve a <a href="nodos.php" target="_blank">Monitor de Nodos</a></li>
                                <li>Simula la caída de una sucursal</li>
                                <li>Intenta comprar productos de esa sucursal</li>
                                <li>El sistema rechazará la venta (arquitectura CP)</li>
                            </ul>
                        </li>
                    </ol>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh cada 5 segundos para ver cambios de stock
        setTimeout(() => {
            if (confirm('¿Recargar para ver stock actualizado?')) {
                location.reload();
            }
        }, 5000);
    </script>
</body>
</html>