-- ============================================================================
-- PROCEDIMIENTOS ALMACENADOS - NODOS SUCURSALES (La Serena y Coquimbo)
-- Libre Mercado - Sistema Distribuido
-- ============================================================================
-- Estos SPs se instalan en: libremercado_sucursal1 y libremercado_sucursal2
-- Gestionan: stock y movimientos_stock
-- ============================================================================

-- ============================================================================
-- TABLA DE LOGS DE TRANSACCIONES (ESPEJO PARA AUDITORÍA LOCAL)
-- ============================================================================

CREATE TABLE IF NOT EXISTS log_transacciones (
    id_log INT AUTO_INCREMENT PRIMARY KEY,
    id_transaccion VARCHAR(50) NOT NULL,
    tipo_operacion ENUM('venta', 'compra', 'stock', 'rollback') NOT NULL,
    id_producto INT,
    cantidad INT,
    stock_anterior INT,
    stock_nuevo INT,
    estado ENUM('inicio', 'preparada', 'confirmada', 'fallida', 'rollback') NOT NULL,
    nodo_origen VARCHAR(50) NOT NULL,
    mensaje_error TEXT,
    datos_adicionales JSON,
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_transaccion (id_transaccion),
    INDEX idx_tipo (tipo_operacion),
    INDEX idx_estado (estado),
    INDEX idx_fecha (fecha_creacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- FUNCIONES UTILITARIAS
-- ============================================================================

DELIMITER //

-- Función para obtener stock actual con lock
CREATE FUNCTION IF NOT EXISTS fn_stock_actual(p_id_prod INT) 
RETURNS INT
READS SQL DATA
BEGIN
    DECLARE v_stock INT DEFAULT 0;
    
    SELECT COALESCE(MAX(cantidad), 0) INTO v_stock
    FROM stock
    WHERE id_prod = p_id_prod;
    
    RETURN v_stock;
END //

-- Función para verificar si hay stock suficiente
CREATE FUNCTION IF NOT EXISTS fn_stock_suficiente(p_id_prod INT, p_cantidad INT) 
RETURNS BOOLEAN
READS SQL DATA
BEGIN
    DECLARE v_stock INT;
    SET v_stock = fn_stock_actual(p_id_prod);
    RETURN v_stock >= p_cantidad;
END //

DELIMITER ;

-- ============================================================================
-- PROCEDIMIENTO PARA DESCONTAR STOCK (VENTAS - 2PC FASE SUCURSAL)
-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_descontar_stock_venta(
    IN p_id_venta INT,
    IN p_id_prod INT,
    IN p_cantidad INT,
    IN p_id_suc INT,
    OUT p_exito BOOLEAN,
    OUT p_error_msg TEXT
)
BEGIN
    DECLARE v_stock_actual INT;
    DECLARE v_stock_anterior INT;
    DECLARE v_producto_nombre VARCHAR(120);
    DECLARE v_id_stock INT;
    DECLARE v_error TEXT;
    DECLARE v_id_transaccion VARCHAR(50);
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 v_error = MESSAGE_TEXT;
        SET p_exito = FALSE;
        SET p_error_msg = v_error;
        
        -- Log de error
        IF v_id_transaccion IS NOT NULL THEN
            UPDATE log_transacciones
            SET estado = 'fallida',
                mensaje_error = v_error
            WHERE id_transaccion = v_id_transaccion;
        END IF;
        
        ROLLBACK;
    END;
    
    SET p_exito = FALSE;
    
    -- Iniciar transacción con lock
    START TRANSACTION;
    
    -- Generar ID de transacción
    SET v_id_transaccion = CONCAT('STOCK-DEL-', p_id_venta, '-', p_id_prod, '-', UNIX_TIMESTAMP());
    
    -- Log de inicio
    INSERT INTO log_transacciones (
        id_transaccion, tipo_operacion, id_producto, cantidad,
        estado, nodo_origen, datos_adicionales
    ) VALUES (
        v_id_transaccion,
        'venta',
        p_id_prod,
        p_cantidad,
        'inicio',
        'sucursal',
        JSON_OBJECT('id_venta', p_id_venta, 'id_suc', p_id_suc)
    );
    
    -- Obtener stock con lock FOR UPDATE
    SELECT id_stock, cantidad INTO v_id_stock, v_stock_anterior
    FROM stock
    WHERE id_prod = p_id_prod AND id_suc = p_id_suc
    FOR UPDATE;
    
    -- Si no existe registro de stock, crearlo
    IF v_id_stock IS NULL THEN
        -- Buscar nombre del producto (si existe en tabla local)
        SELECT producto INTO v_producto_nombre
        FROM stock
        WHERE id_prod = p_id_prod
        LIMIT 1;
        
        IF v_producto_nombre IS NULL THEN
            SET v_producto_nombre = CONCAT('PROD-', p_id_prod);
        END IF;
        
        INSERT INTO stock (id_prod, id_suc, producto, cantidad, stock_minimo)
        VALUES (p_id_prod, p_id_suc, v_producto_nombre, 0, 5);
        
        SET v_id_stock = LAST_INSERT_ID();
        SET v_stock_anterior = 0;
    END IF;
    
    -- Verificar stock suficiente
    IF v_stock_anterior < p_cantidad THEN
        SET p_error_msg = CONCAT('Stock insuficiente. Actual: ', v_stock_anterior, ', Requerido: ', p_cantidad);
        
        UPDATE log_transacciones
        SET estado = 'fallida',
            mensaje_error = p_error_msg,
            stock_anterior = v_stock_anterior
        WHERE id_transaccion = v_id_transaccion;
        
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = p_error_msg;
    END IF;
    
    -- Descontar stock
    UPDATE stock
    SET cantidad = v_stock_anterior - p_cantidad,
        actualizado_en = NOW()
    WHERE id_stock = v_id_stock;
    
    -- Registrar movimiento
    INSERT INTO movimientos_stock (id_prod, id_suc, tipo, cantidad, motivo, fecha)
    VALUES (p_id_prod, p_id_suc, 'salida', p_cantidad, CONCAT('Venta #', p_id_venta), NOW());
    
    SET p_exito = TRUE;
    
    -- Log de confirmación
    UPDATE log_transacciones
    SET estado = 'confirmada',
        stock_anterior = v_stock_anterior,
        stock_nuevo = v_stock_anterior - p_cantidad,
        datos_adicionales = JSON_SET(datos_adicionales, 
            '$.stock_resultante', v_stock_anterior - p_cantidad)
    WHERE id_transaccion = v_id_transaccion;
    
    -- El commit se hace desde PHP
END //

DELIMITER ;

-- ============================================================================
-- PROCEDIMIENTO PARA REPONER STOCK (COMPRAS - 2PC FASE SUCURSAL)
-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_reponer_stock_compra(
    IN p_id_compra INT,
    IN p_id_prod INT,
    IN p_cantidad INT,
    IN p_id_suc INT,
    IN p_motivo VARCHAR(200),
    OUT p_exito BOOLEAN,
    OUT p_error_msg TEXT
)
BEGIN
    DECLARE v_stock_actual INT;
    DECLARE v_stock_anterior INT;
    DECLARE v_producto_nombre VARCHAR(120);
    DECLARE v_id_stock INT;
    DECLARE v_error TEXT;
    DECLARE v_id_transaccion VARCHAR(50);
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 v_error = MESSAGE_TEXT;
        SET p_exito = FALSE;
        SET p_error_msg = v_error;
        
        IF v_id_transaccion IS NOT NULL THEN
            UPDATE log_transacciones
            SET estado = 'fallida',
                mensaje_error = v_error
            WHERE id_transaccion = v_id_transaccion;
        END IF;
        
        ROLLBACK;
    END;
    
    SET p_exito = FALSE;
    
    START TRANSACTION;
    
    -- Generar ID de transacción
    SET v_id_transaccion = CONCAT('STOCK-ADD-', p_id_compra, '-', p_id_prod, '-', UNIX_TIMESTAMP());
    
    -- Log de inicio
    INSERT INTO log_transacciones (
        id_transaccion, tipo_operacion, id_producto, cantidad,
        estado, nodo_origen, datos_adicionales
    ) VALUES (
        v_id_transaccion,
        'compra',
        p_id_prod,
        p_cantidad,
        'inicio',
        'sucursal',
        JSON_OBJECT('id_compra', p_id_compra, 'id_suc', p_id_suc, 'motivo', p_motivo)
    );
    
    -- Obtener stock con lock
    SELECT id_stock, cantidad INTO v_id_stock, v_stock_anterior
    FROM stock
    WHERE id_prod = p_id_prod AND id_suc = p_id_suc
    FOR UPDATE;
    
    -- Si no existe, crearlo
    IF v_id_stock IS NULL THEN
        SELECT producto INTO v_producto_nombre
        FROM stock
        WHERE id_prod = p_id_prod
        LIMIT 1;
        
        IF v_producto_nombre IS NULL THEN
            SET v_producto_nombre = CONCAT('PROD-', p_id_prod);
        END IF;
        
        INSERT INTO stock (id_prod, id_suc, producto, cantidad, stock_minimo)
        VALUES (p_id_prod, p_id_suc, v_producto_nombre, 0, 5);
        
        SET v_id_stock = LAST_INSERT_ID();
        SET v_stock_anterior = 0;
    END IF;
    
    -- Sumar stock
    UPDATE stock
    SET cantidad = v_stock_anterior + p_cantidad,
        actualizado_en = NOW()
    WHERE id_stock = v_id_stock;
    
    -- Registrar movimiento
    INSERT INTO movimientos_stock (id_prod, id_suc, tipo, cantidad, motivo, fecha)
    VALUES (p_id_prod, p_id_suc, 'entrada', p_cantidad, 
            COALESCE(p_motivo, CONCAT('Compra #', p_id_compra)), NOW());
    
    SET p_exito = TRUE;
    
    -- Log de confirmación
    UPDATE log_transacciones
    SET estado = 'confirmada',
        stock_anterior = v_stock_anterior,
        stock_nuevo = v_stock_anterior + p_cantidad,
        datos_adicionales = JSON_SET(datos_adicionales, 
            '$.stock_resultante', v_stock_anterior + p_cantidad)
    WHERE id_transaccion = v_id_transaccion;
    
    -- El commit se hace desde PHP
END //

DELIMITER ;

-- ============================================================================
-- PROCEDIMIENTO PARA ACTUALIZACIÓN MANUAL DE STOCK
-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_actualizar_stock_manual(
    IN p_id_prod INT,
    IN p_id_suc INT,
    IN p_cantidad INT,
    IN p_motivo VARCHAR(200),
    OUT p_exito BOOLEAN,
    OUT p_error_msg TEXT
)
BEGIN
    DECLARE v_stock_anterior INT;
    DECLARE v_producto_nombre VARCHAR(120);
    DECLARE v_id_stock INT;
    DECLARE v_error TEXT;
    DECLARE v_id_transaccion VARCHAR(50);
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 v_error = MESSAGE_TEXT;
        SET p_exito = FALSE;
        SET p_error_msg = v_error;
        
        IF v_id_transaccion IS NOT NULL THEN
            UPDATE log_transacciones
            SET estado = 'fallida',
                mensaje_error = v_error
            WHERE id_transaccion = v_id_transaccion;
        END IF;
        
        ROLLBACK;
    END;
    
    SET p_exito = FALSE;
    
    START TRANSACTION;
    
    -- Generar ID de transacción
    SET v_id_transaccion = CONCAT('STOCK-ADJ-', p_id_prod, '-', p_id_suc, '-', UNIX_TIMESTAMP());
    
    -- Log de inicio
    INSERT INTO log_transacciones (
        id_transaccion, tipo_operacion, id_producto, cantidad,
        estado, nodo_origen, datos_adicionales
    ) VALUES (
        v_id_transaccion,
        'stock',
        p_id_prod,
        p_cantidad,
        'inicio',
        'sucursal',
        JSON_OBJECT('id_suc', p_id_suc, 'motivo', p_motivo, 'tipo', 'ajuste_manual')
    );
    
    -- Obtener stock con lock
    SELECT id_stock, cantidad INTO v_id_stock, v_stock_anterior
    FROM stock
    WHERE id_prod = p_id_prod AND id_suc = p_id_suc
    FOR UPDATE;
    
    -- Si no existe, crearlo
    IF v_id_stock IS NULL THEN
        SELECT producto INTO v_producto_nombre
        FROM stock
        WHERE id_prod = p_id_prod
        LIMIT 1;
        
        IF v_producto_nombre IS NULL THEN
            SET v_producto_nombre = CONCAT('PROD-', p_id_prod);
        END IF;
        
        INSERT INTO stock (id_prod, id_suc, producto, cantidad, stock_minimo)
        VALUES (p_id_prod, p_id_suc, v_producto_nombre, p_cantidad, 5);
        
        SET v_id_stock = LAST_INSERT_ID();
        SET v_stock_anterior = 0;
    ELSE
        -- Actualizar cantidad
        UPDATE stock
        SET cantidad = p_cantidad,
            actualizado_en = NOW()
        WHERE id_stock = v_id_stock;
    END IF;
    
    -- Registrar movimiento de ajuste
    INSERT INTO movimientos_stock (id_prod, id_suc, tipo, cantidad, motivo, fecha)
    VALUES (p_id_prod, p_id_suc, 'ajuste', ABS(p_cantidad - v_stock_anterior), 
            COALESCE(p_motivo, 'Ajuste manual'), NOW());
    
    SET p_exito = TRUE;
    
    -- Log de confirmación
    UPDATE log_transacciones
    SET estado = 'confirmada',
        stock_anterior = v_stock_anterior,
        stock_nuevo = p_cantidad,
        datos_adicionales = JSON_SET(datos_adicionales, 
            '$.stock_resultante', p_cantidad, '$.diferencia', p_cantidad - v_stock_anterior)
    WHERE id_transaccion = v_id_transaccion;
    
    COMMIT;
    
    -- Retornar stock actualizado
    SELECT cantidad AS stock_actual FROM stock WHERE id_stock = v_id_stock;
END //

DELIMITER ;

-- ============================================================================
-- PROCEDIMIENTO PARA SINCRONIZAR STOCK CON CATÁLOGO
-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_sync_stock(
    IN p_id_prod INT,
    IN p_nombre_producto VARCHAR(120)
)
BEGIN
    DECLARE v_id_stock INT;
    DECLARE v_id_suc_default INT;
    
    -- Obtener ID de sucursal del nodo actual
    -- En sucursal1: id_suc = 2, En sucursal2: id_suc = 3
    -- Esto se determina por configuración externa
    
    -- Buscar registro existente
    SELECT id_stock INTO v_id_stock
    FROM stock
    WHERE id_prod = p_id_prod
    LIMIT 1;
    
    IF v_id_stock IS NOT NULL THEN
        -- Actualizar nombre
        UPDATE stock
        SET producto = p_nombre_producto
        WHERE id_stock = v_id_stock;
    ELSE
        -- Insertar nuevo registro con stock 0
        -- El id_suc debe ser proporcionado por el contexto
        SET v_id_suc_default = 2; -- Valor por defecto, se sobrescribe en runtime
        
        INSERT INTO stock (id_prod, id_suc, sucursal, producto, cantidad, stock_minimo)
        VALUES (p_id_prod, v_id_suc_default, 'sucursal', p_nombre_producto, 0, 5);
    END IF;
    
    -- Retornar registro actualizado
    SELECT * FROM stock WHERE id_prod = p_id_prod;
END //

DELIMITER ;

-- ============================================================================
-- PROCEDIMIENTO PARA CONSULTAR STOCK
-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_stock_consultar(
    IN p_id_prod INT
)
BEGIN
    SELECT 
        s.id_stock,
        s.id_prod,
        s.id_suc,
        s.sucursal,
        s.producto,
        s.cantidad,
        s.stock_minimo,
        s.actualizado_en,
        CASE 
            WHEN s.cantidad <= 0 THEN 'sin_stock'
            WHEN s.cantidad <= s.stock_minimo THEN 'bajo_stock'
            ELSE 'disponible'
        END AS estado_stock
    FROM stock s
    WHERE s.id_prod = p_id_prod OR p_id_prod IS NULL
    ORDER BY s.id_suc, s.id_prod;
END //

CREATE PROCEDURE IF NOT EXISTS sp_stock_todos()
BEGIN
    SELECT 
        s.id_stock,
        s.id_prod,
        s.id_suc,
        s.sucursal,
        s.producto,
        s.cantidad,
        s.stock_minimo,
        s.actualizado_en,
        CASE 
            WHEN s.cantidad <= 0 THEN 'sin_stock'
            WHEN s.cantidad <= s.stock_minimo THEN 'bajo_stock'
            ELSE 'disponible'
        END AS estado_stock,
        (s.cantidad <= s.stock_minimo) AS alerta
    FROM stock s
    ORDER BY s.id_suc, s.id_prod;
END //

CREATE PROCEDURE IF NOT EXISTS sp_stock_bajo()
BEGIN
    SELECT 
        s.id_stock,
        s.id_prod,
        s.id_suc,
        s.sucursal,
        s.producto,
        s.cantidad,
        s.stock_minimo,
        (s.stock_minimo - s.cantidad) AS faltante
    FROM stock s
    WHERE s.cantidad <= s.stock_minimo
    ORDER BY s.cantidad ASC;
END //

DELIMITER ;

-- ============================================================================
-- PROCEDIMIENTO PARA MOVIMIENTOS DE STOCK
-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_movimientos_listar(
    IN p_id_prod INT,
    IN p_limite INT
)
BEGIN
    DECLARE v_limite INT DEFAULT COALESCE(p_limite, 100);
    
    SELECT 
        m.id_movimiento,
        m.id_prod,
        m.id_suc,
        m.tipo,
        m.cantidad,
        m.motivo,
        m.fecha,
        p.producto
    FROM movimientos_stock m
    LEFT JOIN stock p ON p.id_prod = m.id_prod
    WHERE p_id_prod IS NULL OR m.id_prod = p_id_prod
    ORDER BY m.fecha DESC
    LIMIT v_limite;
END //

DELIMITER ;

-- ============================================================================
-- PROCEDIMIENTOS DE TESTING DE CONCURRENCIA
-- ============================================================================

DELIMITER //

-- Procedimiento para testear concurrencia con múltiples operaciones
CREATE PROCEDURE IF NOT EXISTS sp_testear_concurrencia(
    IN p_id_prod INT,
    IN p_cantidad_operaciones INT,
    IN p_cantidad_por_operacion INT
)
BEGIN
    DECLARE v_i INT DEFAULT 0;
    DECLARE v_stock_inicial INT;
    DECLARE v_exito BOOLEAN;
    DECLARE v_error TEXT;
    DECLARE v_resultados JSON;
    DECLARE v_exitosas INT DEFAULT 0;
    DECLARE v_fallidas INT DEFAULT 0;
    
    -- Obtener stock inicial
    SELECT COALESCE(MAX(cantidad), 0) INTO v_stock_inicial
    FROM stock
    WHERE id_prod = p_id_prod;
    
    -- Crear tabla temporal para resultados
    DROP TEMPORARY TABLE IF EXISTS tmp_test_resultados;
    CREATE TEMPORARY TABLE tmp_test_resultados (
        operacion INT,
        stock_antes INT,
        stock_despues INT,
        exito BOOLEAN,
        error TEXT,
        tiempo_ms INT
    );
    
    -- Ejecutar operaciones concurrentes (simuladas)
    WHILE v_i < p_cantidad_operaciones DO
        BEGIN
            DECLARE v_stock_antes INT;
            DECLARE v_stock_despues INT;
            DECLARE v_start_time INT;
            DECLARE v_end_time INT;
            
            -- Stock antes
            SELECT COALESCE(MAX(cantidad), 0) INTO v_stock_antes
            FROM stock WHERE id_prod = p_id_prod;
            
            SET v_start_time = UNIX_TIMESTAMP(NOW(3)) * 1000;
            
            -- Intentar descontar stock
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 v_error = MESSAGE_TEXT;
                    SET v_fallidas = v_fallidas + 1;
                    
                    INSERT INTO tmp_test_resultados
                    VALUES (v_i, v_stock_antes, v_stock_antes, FALSE, v_error, 0);
                END;
                
                START TRANSACTION;
                
                -- Con lock
                SELECT cantidad INTO v_stock_antes
                FROM stock WHERE id_prod = p_id_prod FOR UPDATE;
                
                IF v_stock_antes >= p_cantidad_por_operacion THEN
                    UPDATE stock
                    SET cantidad = cantidad - p_cantidad_por_operacion
                    WHERE id_prod = p_id_prod;
                    
                    INSERT INTO movimientos_stock (id_prod, id_suc, tipo, cantidad, motivo)
                    VALUES (p_id_prod, 2, 'salida', p_cantidad_por_operacion, 'Test concurrencia');
                    
                    COMMIT;
                    
                    SET v_exitosas = v_exitosas + 1;
                    
                    -- Stock después
                    SELECT COALESCE(MAX(cantidad), 0) INTO v_stock_despues
                    FROM stock WHERE id_prod = p_id_prod;
                    
                    SET v_end_time = UNIX_TIMESTAMP(NOW(3)) * 1000;
                    
                    INSERT INTO tmp_test_resultados
                    VALUES (v_i, v_stock_antes, v_stock_despues, TRUE, NULL, v_end_time - v_start_time);
                ELSE
                    ROLLBACK;
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Stock insuficiente en operación de test';
                END IF;
            END;
            
            SET v_i = v_i + 1;
        END;
    END WHILE;
    
    -- Retornar resultados
    SELECT 
        p_id_prod AS producto_testeado,
        v_stock_inicial AS stock_inicial,
        p_cantidad_operaciones AS operaciones_intentadas,
        v_exitosas AS operaciones_exitosas,
        v_fallidas AS operaciones_fallidas,
        (SELECT COALESCE(MAX(cantidad), 0) FROM stock WHERE id_prod = p_id_prod) AS stock_final,
        (v_stock_inicial - (SELECT COALESCE(MAX(cantidad), 0) FROM stock WHERE id_prod = p_id_prod)) AS stock_descontado_total,
        AVG(tiempo_ms) AS tiempo_promedio_ms,
        MAX(tiempo_ms) AS tiempo_maximo_ms,
        MIN(tiempo_ms) AS tiempo_minimo_ms
    FROM tmp_test_resultados
    WHERE exito = TRUE;
    
    -- Detalle de conflictos
    SELECT * FROM tmp_test_resultados WHERE exito = FALSE;
    
    DROP TEMPORARY TABLE tmp_test_resultados;
END //

DELIMITER ;

-- ============================================================================
-- PROCEDIMIENTO PARA SIMULAR RECUPERACIÓN POST CAÍDA
-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_simular_recuperacion_post_caida()
BEGIN
    DECLARE v_stock_reconciliado INT DEFAULT 0;
    DECLARE v_movimientos_pendientes INT DEFAULT 0;
    
    -- Crear tabla temporal para reconciliación
    DROP TEMPORARY TABLE IF EXISTS tmp_reconciliacion;
    CREATE TEMPORARY TABLE tmp_reconciliacion (
        id_prod INT,
        stock_actual INT,
        stock_calculado INT,
        diferencia INT,
        movimientos_revisados INT
    );
    
    -- Reconciliar stock con movimientos
    INSERT INTO tmp_reconciliacion
    SELECT 
        s.id_prod,
        s.cantidad AS stock_actual,
        COALESCE(
            (SELECT SUM(CASE WHEN tipo = 'entrada' THEN cantidad ELSE -cantidad END)
             FROM movimientos_stock m 
             WHERE m.id_prod = s.id_prod),
            0
        ) AS stock_calculado,
        s.cantidad - COALESCE(
            (SELECT SUM(CASE WHEN tipo = 'entrada' THEN cantidad ELSE -cantidad END)
             FROM movimientos_stock m 
             WHERE m.id_prod = s.id_prod),
            0
        ) AS diferencia,
        (SELECT COUNT(*) FROM movimientos_stock m WHERE m.id_prod = s.id_prod) AS movimientos_revisados
    FROM stock s;
    
    -- Reportar discrepancias
    SELECT * FROM tmp_reconciliacion WHERE diferencia != 0;
    
    -- Contar movimientos sin procesar (si hubiera)
    SELECT COUNT(*) AS movimientos_pendientes
    FROM movimientos_stock
    WHERE motivo LIKE '%pendiente%';
    
    -- Log de recuperación
    INSERT INTO log_transacciones (
        id_transaccion, tipo_operacion, estado, nodo_origen,
        datos_adicionales
    ) VALUES (
        CONCAT('RECOVERY-', UNIX_TIMESTAMP()),
        'stock',
        'confirmada',
        'sucursal',
        JSON_OBJECT(
            'accion', 'recuperacion_post_caida',
            'productos_revisados', (SELECT COUNT(*) FROM tmp_reconciliacion),
            'discrepancias', (SELECT COUNT(*) FROM tmp_reconciliacion WHERE diferencia != 0)
        )
    );
    
    DROP TEMPORARY TABLE tmp_reconciliacion;
END //

DELIMITER ;

-- ============================================================================
-- PROCEDIMIENTO PARA LIMPIEZA DE LOGS
-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_logs_limpiar(
    IN p_dias_antiguedad INT
)
BEGIN
    DECLARE v_fecha_limite DATETIME;
    
    SET v_fecha_limite = DATE_SUB(NOW(), INTERVAL COALESCE(p_dias_antiguedad, 30) DAY);
    
    DELETE FROM log_transacciones
    WHERE fecha_creacion < v_fecha_limite
      AND estado IN ('confirmada', 'rollback');
    
    SELECT ROW_COUNT() AS registros_eliminados;
END //

DELIMITER ;