<?php

/**
 * WRAPPERS PHP PARA PROCEDIMIENTOS ALMACENADOS
 * Libre Mercado - Sistema Distribuido
 * 
 * Esta capa abstrae la llamada a stored procedures MySQL
 * Reemplaza funciones anteriores de lm_services.php
 */

// ============================================================================
// CARRITO
// ============================================================================

function sp_carrito_activo(int $idCli): array
{
    $pdo = lm_pdo('central');
    $stmt = $pdo->prepare('CALL sp_carrito_activo(?)');
    $stmt->execute([$idCli]);
    
    // Primer resultado: información del carrito
    $carritoInfo = $stmt->fetch();
    
    // Segundo resultado: items del carrito
    $stmt->nextRowset();
    $items = $stmt->fetchAll();
    
    // Limpiar cursor
    $stmt->closeCursor();
    
    if (!$carritoInfo) {
        return ['id_carrito' => 0, 'id_cli' => $idCli, 'items' => [], 'total' => 0];
    }
    
    return [
        'id_carrito' => (int) $carritoInfo['id_carrito'],
        'id_cli' => (int) $carritoInfo['id_cli'],
        'fecha_creacion' => $carritoInfo['fecha_creacion'],
        'estado' => $carritoInfo['estado'],
        'items' => $items,
        'total' => (float) $carritoInfo['total'],
    ];
}

function sp_carrito_agregar(int $idCli, int $idProd, int $cantidad, float $precioUnitario): array
{
    $pdo = lm_pdo('central');
    $pdo->beginTransaction();
    
    try {
        $stmt = $pdo->prepare('CALL sp_carrito_agregar(?, ?, ?, ?)');
        $stmt->execute([$idCli, $idProd, $cantidad, $precioUnitario]);
        
        // Obtener resultados de sp_carrito_activo (llamado dentro del SP)
        $stmt->nextRowset();
        $carritoInfo = $stmt->fetch();
        
        $stmt->nextRowset();
        $items = $stmt->fetchAll();
        
        $pdo->commit();
        
        return [
            'id_carrito' => (int) $carritoInfo['id_carrito'],
            'items' => $items,
            'total' => (float) ($carritoInfo['total'] ?? 0),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function sp_carrito_eliminar(int $idCli, int $idProd): array
{
    $pdo = lm_pdo('central');
    $stmt = $pdo->prepare('CALL sp_carrito_eliminar(?, ?)');
    $stmt->execute([$idCli, $idProd]);
    
    // Obtener carrito actualizado
    $stmt->nextRowset();
    $carritoInfo = $stmt->fetch();
    
    $stmt->nextRowset();
    $items = $stmt->fetchAll();
    
    // Limpiar cursor
    $stmt->closeCursor();
    
    return [
        'id_carrito' => (int) $carritoInfo['id_carrito'],
        'items' => $items,
        'total' => (float) ($carritoInfo['total'] ?? 0),
    ];
}

function sp_carrito_vaciar(int $idCli): void
{
    $pdo = lm_pdo('central');
    $stmt = $pdo->prepare('CALL sp_carrito_vaciar(?)');
    $stmt->execute([$idCli]);
}

// ============================================================================
// VENTAS (2PC)
// ============================================================================

function sp_registrar_venta_2pc(int $idCli, int $idSuc, array $items, ?int $idCarrito = null): array
{
    $pdoCentral = lm_pdo('central');
    $stockNode = LmDatabase::stockNodeForSucursal($idSuc);
    $pdoStock = lm_pdo($stockNode);
    
    // Validar que todos los nodos estén disponibles (CP)
    if (LmDatabase::isSimulatedDown('central') || LmDatabase::isSimulatedDown($stockNode)) {
        throw new RuntimeException("Nodo no disponible. Operación cancelada (CP).");
    }
    
    $idVenta = null;
    $total = 0;
    $errorMsg = null;
    
    // FASE 1: Central - Crear venta y detalles
    // NOTA: sp_venta_2pc maneja su propia transacción (START TRANSACTION + COMMIT/ROLLBACK)
    try {
        $stmt = $pdoCentral->prepare('CALL sp_venta_2pc(?, ?, ?, ?, @p_id_venta, @p_total, @p_error_msg)');
        $stmt->execute([
            $idCli,
            $idSuc,
            json_encode($items),
            $idCarrito
        ]);
        
        // Obtener outputs
        $result = $pdoCentral->query('SELECT @p_id_venta AS id_venta, @p_total AS total, @p_error_msg AS error_msg')->fetch();
        $idVenta = $result['id_venta'];
        $total = $result['total'];
        $errorMsg = $result['error_msg'];
        
        // Limpiar cursor
        $stmt->closeCursor();
        
        if ($idVenta === null || $errorMsg !== null) {
            throw new RuntimeException($errorMsg ?? 'Error en fase 1 (Central)');
        }
        
        // FASE 2: Sucursal - Descontar stock
        // sp_descontar_stock_venta NO hace commit/rollback, debemos manejarlo desde PHP
        $pdoStock->beginTransaction();
        try {
            foreach ($items as $item) {
                $idProd = (int) ($item['id_prod'] ?? 0);
                $cantidad = max(1, (int) ($item['cantidad'] ?? 0));
                
                $stmt = $pdoStock->prepare('CALL sp_descontar_stock_venta(?, ?, ?, ?, @p_exito, @p_error_msg)');
                $stmt->execute([
                    $idVenta,
                    $idProd,
                    $cantidad,
                    $idSuc,
                ]);
                
                // Verificar resultado
                $result = $pdoStock->query('SELECT @p_exito AS exito, @p_error_msg AS error_msg')->fetch();
                
                // Limpiar cursor después de cada item
                $stmt->closeCursor();
                
                if (!$result['exito']) {
                    throw new RuntimeException($result['error_msg'] ?? 'Error al descontar stock');
                }
            }
            
            // Confirmar en sucursal
            $pdoStock->commit();
            
            // FASE 3: Confirmar venta en central (sp_venta_confirmar hace COMMIT)
            $stmt = $pdoCentral->prepare('CALL sp_venta_confirmar(?, ?)');
            $stmt->execute([$idVenta, $idCarrito]);
            
            return [
                'id_venta' => (int) $idVenta,
                'total' => (float) $total,
            ];
            
        } catch (Throwable $e) {
            // Rollback en sucursal
            if ($pdoStock->inTransaction()) {
                $pdoStock->rollBack();
            }
            
            // Revertir venta en central (sp_venta_revertir hace COMMIT)
            $stmt = $pdoCentral->prepare('CALL sp_venta_revertir(?)');
            $stmt->execute([$idVenta]);
            
            throw $e;
        }
        
    } catch (Throwable $e) {
        throw $e;
    }
}

// ============================================================================
// COMPRAS (2PC)
// ============================================================================

function sp_registrar_compra_2pc(int $idProveedor, int $idSuc, int $idProd, int $cantidad, float $precioUnitario): array
{
    $pdoCentral = lm_pdo('central');
    $stockNode = LmDatabase::stockNodeForSucursal($idSuc);
    $pdoStock = lm_pdo($stockNode);
    
    // Validar nodos (CP)
    if (LmDatabase::isSimulatedDown('central') || LmDatabase::isSimulatedDown($stockNode)) {
        throw new RuntimeException("Nodo no disponible. Operación cancelada (CP).");
    }
    
    $idCompra = null;
    $total = 0;
    $errorMsg = null;
    
    // FASE 1: Central - Crear compra
    // NOTA: sp_compra_2pc maneja su propia transacción
    try {
        $stmt = $pdoCentral->prepare('CALL sp_compra_2pc(?, ?, ?, ?, ?, @p_id_compra, @p_total, @p_error_msg)');
        $stmt->execute([
            $idProveedor,
            $idSuc,
            $idProd,
            $cantidad,
            $precioUnitario
        ]);
        
        // Obtener outputs
        $result = $pdoCentral->query('SELECT @p_id_compra AS id_compra, @p_total AS total, @p_error_msg AS error_msg')->fetch();
        $idCompra = $result['id_compra'];
        $total = $result['total'];
        $errorMsg = $result['error_msg'];
        
        // Limpiar cursor
        $stmt->closeCursor();
        
        if ($idCompra === null || $errorMsg !== null) {
            throw new RuntimeException($errorMsg ?? 'Error en fase 1 (Central)');
        }
        
        // FASE 2: Sucursal - Reponer stock
        // sp_reponer_stock_compra NO hace commit/rollback, lo manejamos desde PHP
        $pdoStock->beginTransaction();
        try {
            $stmt = $pdoStock->prepare('CALL sp_reponer_stock_compra(?, ?, ?, ?, ?, @p_exito, @p_error_msg)');
            $stmt->execute([
                $idCompra,
                $idProd,
                $cantidad,
                $idSuc,
                'Compra #' . $idCompra
            ]);
            
            // Verificar resultado
            $result = $pdoStock->query('SELECT @p_exito AS exito, @p_error_msg AS error_msg')->fetch();
            
            // Limpiar cursor
            $stmt->closeCursor();
            
            if (!$result['exito']) {
                throw new RuntimeException($result['error_msg'] ?? 'Error al reponer stock');
            }
            
            // Confirmar en sucursal
            $pdoStock->commit();
            
            // FASE 3: Confirmar compra en central (sp_compra_confirmar hace COMMIT)
            $stmt = $pdoCentral->prepare('CALL sp_compra_confirmar(?)');
            $stmt->execute([$idCompra]);
            
            return [
                'id_compra' => (int) $idCompra,
                'total' => (float) $total,
            ];
            
        } catch (Throwable $e) {
            if ($pdoStock->inTransaction()) {
                $pdoStock->rollBack();
            }
            
            // Revertir compra (sp_compra_revertir hace COMMIT)
            $stmt = $pdoCentral->prepare('CALL sp_compra_revertir(?)');
            $stmt->execute([$idCompra]);
            
            throw $e;
        }
        
    } catch (Throwable $e) {
        throw $e;
    }
}

// ============================================================================
// STOCK
// ============================================================================

function sp_stock_actualizar(int $idProd, int $idSuc, int $cantidad, string $motivo = 'Ajuste manual'): array
{
    $stockNode = LmDatabase::stockNodeForSucursal($idSuc);
    $pdo = lm_pdo($stockNode);
    
    $stmt = $pdo->prepare('CALL sp_actualizar_stock_manual(?, ?, ?, ?, @p_exito, @p_error_msg)');
    $stmt->execute([
        $idProd,
        $idSuc,
        $cantidad,
        $motivo
    ]);
    
    // Primer rowset: stock_actual (el SP hace SELECT cantidad AS stock_actual)
    $stockData = $stmt->fetch();
    
    // Segundo rowset: resultado con exito y error_msg
    $stmt->nextRowset();
    $result = $stmt->fetch();
    
    // Cerrar cursor
    $stmt->closeCursor();
    
    if (!$result || !($result['exito'] ?? false)) {
        throw new RuntimeException($result['error_msg'] ?? 'Error al actualizar stock');
    }
    
    return [
        'exito' => true,
        'stock_actual' => (int) ($stockData['stock_actual'] ?? $cantidad),
    ];
}

function sp_stock_consultar(?int $idProd = null): array
{
    $nodes = ['sucursal1', 'sucursal2'];
    $resultados = [];
    
    foreach ($nodes as $node) {
        try {
            if (LmDatabase::isSimulatedDown($node)) {
                continue;
            }
            
            $pdo = lm_pdo($node);
            $stmt = $pdo->prepare('CALL sp_stock_todos()');
            $stmt->execute();
            $rows = $stmt->fetchAll();
            $stmt->closeCursor();
            
            foreach ($rows as $row) {
                $row['nodo'] = $node;
                $resultados[] = $row;
            }
        } catch (Throwable $e) {
            error_log("Error consultando stock en nodo {$node}: " . $e->getMessage());
        }
    }
    
    return $resultados;
}

function sp_stock_bajo(): array
{
    $nodes = ['sucursal1', 'sucursal2'];
    $resultados = [];
    
    foreach ($nodes as $node) {
        try {
            if (LmDatabase::isSimulatedDown($node)) {
                continue;
            }
            
            $pdo = lm_pdo($node);
            $stmt = $pdo->prepare('CALL sp_stock_bajo()');
            $stmt->execute();
            $rows = $stmt->fetchAll();
            $stmt->closeCursor();
            
            foreach ($rows as $row) {
                $row['nodo'] = $node;
                $resultados[] = $row;
            }
        } catch (Throwable $e) {
            error_log("Error consultando stock bajo en nodo {$node}: " . $e->getMessage());
        }
    }
    
    return $resultados;
}

// ============================================================================
// CONSULTAS Y REPORTES
// ============================================================================

function sp_ventas_listar(): array
{
    $pdo = lm_pdo('central');
    $stmt = $pdo->query('CALL sp_ventas_listar()');
    $result = $stmt->fetchAll();
    $stmt->closeCursor();
    return $result;
}

function sp_ventas_detalle(int $idVenta): array
{
    $pdo = lm_pdo('central');
    $stmt = $pdo->prepare('CALL sp_ventas_detalle(?)');
    $stmt->execute([$idVenta]);
    $result = $stmt->fetchAll();
    $stmt->closeCursor();
    return $result;
}

function sp_compras_listar(): array
{
    $pdo = lm_pdo('central');
    $stmt = $pdo->query('CALL sp_compras_listar()');
    $result = $stmt->fetchAll();
    $stmt->closeCursor();
    return $result;
}

function sp_compras_detalle(int $idCompra): array
{
    $pdo = lm_pdo('central');
    $stmt = $pdo->prepare('CALL sp_compras_detalle(?)');
    $stmt->execute([$idCompra]);
    $result = $stmt->fetchAll();
    $stmt->closeCursor();
    return $result;
}

// ============================================================================
// LOGS DE TRANSACCIONES
// ============================================================================

function sp_logs_transacciones(int $limite = 50, ?string $tipo = null, ?string $estado = null): array
{
    $pdo = lm_pdo('central');
    $stmt = $pdo->prepare('CALL sp_logs_transacciones(?, ?, ?)');
    $stmt->execute([$limite, $tipo, $estado]);
    $result = $stmt->fetchAll();
    $stmt->closeCursor();
    return $result;
}

function sp_logs_limpiar(int $diasAntiguedad = 30): int
{
    $pdo = lm_pdo('central');
    $stmt = $pdo->prepare('CALL sp_logs_limpiar(?)');
    $stmt->execute([$diasAntiguedad]);
    $result = $stmt->fetch();
    $stmt->closeCursor();
    return (int) ($result['registros_eliminados'] ?? 0);
}

// ============================================================================
// TESTING
// ============================================================================

function sp_testear_concurrencia(int $idProd, int $cantidadOperaciones, int $cantidadPorOperacion): array
{
    $stockNode = 'sucursal1'; // Nodo por defecto para testing
    $pdo = lm_pdo($stockNode);
    
    $stmt = $pdo->prepare('CALL sp_testear_concurrencia(?, ?, ?)');
    $stmt->execute([$idProd, $cantidadOperaciones, $cantidadPorOperacion]);
    
    // Primer resultado: estadísticas
    $stats = $stmt->fetch();
    
    // Segundo resultado: operaciones fallidas (si las hay)
    $stmt->nextRowset();
    $fallos = $stmt->fetchAll();
    
    // Limpiar cursor
    $stmt->closeCursor();
    
    return [
        'estadisticas' => $stats,
        'operaciones_fallidas' => $fallos,
    ];
}

function sp_simular_recuperacion_post_caida(): array
{
    $nodes = ['sucursal1', 'sucursal2'];
    $resultados = [];
    
    foreach ($nodes as $node) {
        try {
            if (LmDatabase::isSimulatedDown($node)) {
                continue;
            }
            
            $pdo = lm_pdo($node);
            $stmt = $pdo->prepare('CALL sp_simular_recuperacion_post_caida()');
            $stmt->execute();
            
            // Primer resultado: discrepancias
            $discrepancias = $stmt->fetchAll();
            
            // Segundo resultado: movimientos pendientes
            $stmt->nextRowset();
            $pendientes = $stmt->fetch();
            
            // Limpiar cursor
            $stmt->closeCursor();
            
            $resultados[$node] = [
                'discrepancias' => $discrepancias,
                'movimientos_pendientes' => $pendientes['movimientos_pendientes'] ?? 0,
            ];
        } catch (Throwable $e) {
            error_log("Error en recuperación nodo {$node}: " . $e->getMessage());
        }
    }
    
    return $resultados;
}

// ============================================================================
// FUNCIONES AUXILIARES PARA COMPATIBILIDAD
// ============================================================================

/**
 * Mantiene compatibilidad con código legacy que usa lm_registrar_venta
 */
function lm_registrar_venta(int $idCli, int $idSuc, array $items, ?int $idCarrito = null): array
{
    return sp_registrar_venta_2pc($idCli, $idSuc, $items, $idCarrito);
}

/**
 * Mantiene compatibilidad con código legacy que usa lm_registrar_compra
 */
function lm_registrar_compra(int $idProveedor, int $idSuc, int $idProd, int $cantidad, float $precioUnitario): array
{
    return sp_registrar_compra_2pc($idProveedor, $idSuc, $idProd, $cantidad, $precioUnitario);
}

/**
 * Mantiene compatibilidad con código legacy que usa lm_stock_actualizar
 * COMENTADA: la función original en lm_services.php se usa directamente
 */
/* function lm_stock_actualizar(int $idSuc, int $idProd, int $cantidad, string $motivo = 'Ajuste manual'): void
{
    sp_stock_actualizar($idProd, $idSuc, $cantidad, $motivo);
} */

/**
 * Mantiene compatibilidad con código legacy que usa lm_carrito_activo
 */
function lm_carrito_activo(int $idCli): array
{
    return sp_carrito_activo($idCli);
}

/**
 * Mantiene compatibilidad con código legacy que usa lm_carrito_agregar_item
 */
function lm_carrito_agregar_item(int $idCli, int $idProd, int $cantidad): array
{
    $producto = lm_producto_por_id($idProd);
    return sp_carrito_agregar($idCli, $idProd, $cantidad, (float) ($producto['precio'] ?? 0));
}

/**
 * Mantiene compatibilidad con código legacy que usa lm_carrito_eliminar_item
 */
function lm_carrito_eliminar_item(int $idCli, int $idProd): array
{
    return sp_carrito_eliminar($idCli, $idProd);
}

/**
 * Mantiene compatibilidad con código legacy que usa lm_carrito_vaciar
 */
function lm_carrito_vaciar(int $idCli): void
{
    sp_carrito_vaciar($idCli);
}