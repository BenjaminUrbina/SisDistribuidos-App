-- ============================================================================
-- SCRIPTS DE PRUEBA PARA PROCEDIMIENTOS ALMACENADOS
-- Libre Mercado - Sistema Distribuido
-- ============================================================================
-- Uso: mysql -u root -p libremercado_central < test_procedures.sql
-- ============================================================================

-- ============================================================================
-- CONFIGURACIÓN INICIAL
-- ============================================================================


docker exec libre_mercado-mysql-central-1 mysql -u root -proot \
  -e "SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='libremercado_central' AND ROUTINE_TYPE='PROCEDURE'"



  
-- Ver procedimientos instalados
SELECT 
    ROUTINE_NAME,
    ROUTINE_TYPE,
    LAST_ALTERED
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA IN ('libremercado_central', 'libremercado_sucursal1', 'libremercado_sucursal2')
ORDER BY ROUTINE_TYPE, ROUTINE_NAME;

-- ============================================================================
-- TEST 1: CARRITO DE COMPRAS
-- ============================================================================

-- Test sp_carrito_activo (crear carrito si no existe)
CALL sp_carrito_activo(1);

-- Test sp_carrito_agregar
CALL sp_carrito_agregar(1, 1, 2, 899990); -- Cliente 1, Producto 1, Cantidad 2
CALL sp_carrito_agregar(1, 2, 1, 1250000); -- Cliente 1, Producto 2, Cantidad 1

-- Verificar carrito
CALL sp_carrito_activo(1);

-- Test sp_carrito_eliminar
CALL sp_carrito_eliminar(1, 1);

-- Verificar después de eliminar
CALL sp_carrito_activo(1);

-- Test sp_carrito_vaciar
CALL sp_carrito_vaciar(1);

-- ============================================================================
-- TEST 2: VENTAS (2PC)
-- ============================================================================

-- Preparar datos de prueba
SET @items_json = JSON_ARRAY(
    JSON_OBJECT('id_prod', 1, 'cantidad', 1, 'precio_unitario', 899990),
    JSON_OBJECT('id_prod', 3, 'cantidad', 2, 'precio_unitario', 349990)
);

-- Test sp_venta_2pc
CALL sp_venta_2pc(
    1,              -- id_cli
    2,              -- id_suc (La Serena)
    @items_json,    -- items en JSON
    NULL,           -- id_carrito
    @p_id_venta,    -- OUT: id_venta
    @p_total,       -- OUT: total
    @p_error_msg    -- OUT: error_msg
);

-- Verificar outputs
SELECT @p_id_venta AS 'ID Venta', @p_total AS 'Total', @p_error_msg AS 'Error';

-- Si no hay error, confirmar venta
IF @p_error_msg IS NULL THEN
    CALL sp_venta_confirmar(@p_id_venta, NULL);
END IF;

-- Verificar venta creada
CALL sp_ventas_listar();

-- Verificar detalle
CALL sp_ventas_detalle(@p_id_venta);

-- ============================================================================
-- TEST 3: COMPRAS (2PC)
-- ============================================================================

-- Test sp_compra_2pc
CALL sp_compra_2pc(
    1,              -- id_proveedor
    2,              -- id_suc
    1,              -- id_prod
    10,             -- cantidad
    750000,         -- precio_unitario
    @p_id_compra,   -- OUT: id_compra
    @p_total,       -- OUT: total
    @p_error_msg    -- OUT: error_msg
);

-- Verificar outputs
SELECT @p_id_compra AS 'ID Compra', @p_total AS 'Total', @p_error_msg AS 'Error';

-- Confirmar compra si no hay error
IF @p_error_msg IS NULL THEN
    CALL sp_compra_confirmar(@p_id_compra);
END IF;

-- Verificar compra creada
CALL sp_compras_listar();

-- Verificar detalle
CALL sp_compras_detalle(@p_id_compra);

-- ============================================================================
-- TEST 4: STOCK
-- ============================================================================

-- Consultar stock actual
CALL sp_stock_todos();

-- Consultar stock bajo
CALL sp_stock_bajo();

-- Actualizar stock manual
CALL sp_actualizar_stock_manual(1, 2, 100, 'Test manual', @p_exito, @p_error_msg);
SELECT @p_exito AS 'Éxito', @p_error_msg AS 'Error';

-- Verificar stock actualizado
CALL sp_stock_consultar(1);

-- Ver movimientos de stock
CALL sp_movimientos_listar(1, 20);

-- ============================================================================
-- TEST 5: CONCURRENCIA
-- ============================================================================

-- Test de concurrencia con múltiples operaciones
CALL sp_testear_concurrencia(1, 5, 2); -- Producto 1, 5 operaciones, 2 unidades c/u

-- ============================================================================
-- TEST 6: RECUPERACIÓN POST CAÍDA
-- ============================================================================

-- Simular recuperación
CALL sp_simular_recuperacion_post_caida();

-- ============================================================================
-- TEST 7: LOGS DE TRANSACCIONES
-- ============================================================================

-- Ver logs recientes
CALL sp_logs_transacciones(50, NULL, NULL);

-- Ver logs solo de ventas
CALL sp_logs_transacciones(50, 'venta', NULL);

-- Ver logs solo confirmadas
CALL sp_logs_transacciones(50, NULL, 'confirmada');

-- Ver logs fallidas
CALL sp_logs_transacciones(50, NULL, 'fallida');

-- Limpiar logs antiguos (más de 7 días)
CALL sp_logs_limpiar(7);

-- ============================================================================
-- TEST 8: ROLLBACK POR STOCK INSUFICIENTE
-- ============================================================================

-- Intentar comprar más stock del disponible (debe fallar y hacer rollback)
SET @items_insuficientes = JSON_ARRAY(
    JSON_OBJECT('id_prod', 1, 'cantidad', 9999, 'precio_unitario', 899990)
);

CALL sp_venta_2pc(
    1,
    2,
    @items_insuficientes,
    NULL,
    @p_id_venta_fail,
    @p_total_fail,
    @p_error_msg_fail
);

-- Verificar error
SELECT @p_error_msg_fail AS 'Error Esperado (Stock Insuficiente)';

-- Verificar que no se creó la venta
SELECT * FROM ventas WHERE id_venta = @p_id_venta_fail;

-- ============================================================================
-- TEST 9: VALIDACIÓN DE DATOS INVÁLIDOS
-- ============================================================================

-- Intentar venta con cliente inválido
CALL sp_venta_2pc(
    99999,          -- Cliente que no existe
    2,
    @items_json,
    NULL,
    @p_id_venta_inv,
    @p_total_inv,
    @p_error_msg_inv
);

SELECT @p_error_msg_inv AS 'Error Esperado (Cliente Inválido)';

-- Intentar compra con proveedor inválido
CALL sp_compra_2pc(
    99999,          -- Proveedor que no existe
    2,
    1,
    10,
    1000,
    @p_id_compra_inv,
    @p_total_inv,
    @p_error_msg_inv2
);

SELECT @p_error_msg_inv2 AS 'Error Esperado (Proveedor Inválido)';

-- ============================================================================
-- TEST 10: ESTADÍSTICAS Y REPORTES
-- ============================================================================

-- Dashboard de transacciones
SELECT 
    tipo_operacion,
    estado,
    COUNT(*) AS cantidad,
    SUM(COALESCE(monto_total, 0)) AS monto_total
FROM log_transacciones
GROUP BY tipo_operacion, estado
ORDER BY tipo_operacion, estado;

-- Transacciones por hora (últimas 24 horas)
SELECT 
    DATE_FORMAT(fecha_creacion, '%Y-%m-%d %H:00') AS hora,
    COUNT(*) AS transacciones,
    SUM(COALESCE(monto_total, 0)) AS monto
FROM log_transacciones
WHERE fecha_creacion >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY DATE_FORMAT(fecha_creacion, '%Y-%m-%d %H:00')
ORDER BY hora;

-- ============================================================================
-- CLEANUP (OPCIONAL)
-- ============================================================================

-- Eliminar logs de test (mantener últimos 30 días)
CALL sp_logs_limpiar(30);

-- Ver espacio liberado
SELECT 
    table_name,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Size (MB)'
FROM information_schema.TABLES
WHERE table_schema IN ('libremercado_central', 'libremercado_sucursal1', 'libremercado_sucursal2')
  AND table_name = 'log_transacciones';

-- ============================================================================
-- FIN DE TESTS
-- ============================================================================

SELECT '✅ Tests completados!' AS 'Estado';