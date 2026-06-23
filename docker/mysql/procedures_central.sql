-- ============================================================================
-- PROCEDIMIENTOS ALMACENADOS - NODO CENTRAL
-- Libre Mercado - Sistema Distribuido
-- ============================================================================

-- ============================================================================
-- TABLA DE LOGS DE TRANSACCIONES
-- ============================================================================

CREATE TABLE IF NOT EXISTS log_transacciones (
    id_log INT AUTO_INCREMENT PRIMARY KEY,
    id_transaccion VARCHAR(50) NOT NULL,
    tipo_operacion ENUM('venta', 'compra', 'stock', 'carrito', 'rollback') NOT NULL,
    id_usuario INT,
    id_cliente INT,
    id_sucursal INT,
    id_producto INT,
    cantidad INT,
    monto_total DECIMAL(12,2),
    estado ENUM('inicio', 'preparada', 'confirmada', 'fallida', 'rollback') NOT NULL,
    nodo_origen VARCHAR(50) NOT NULL,
    nodo_destino VARCHAR(50),
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

-- Función para verificar si un nodo está operativo (solo verifica existencia de tabla)
CREATE FUNCTION IF NOT EXISTS fn_nodo_operativo(p_id_sucursal INT) 
RETURNS BOOLEAN
DETERMINISTIC
BEGIN
    DECLARE v_operativo BOOLEAN DEFAULT TRUE;
    
    -- Sucursales 2 y 3 son operativas, 1 (central) no maneja stock
    IF p_id_sucursal NOT IN (2, 3) THEN
        SET v_operativo = FALSE;
    END IF;
    
    RETURN v_operativo;
END //

-- Función para obtener stock disponible (valor local, debe consultarse en nodo sucursal)
CREATE FUNCTION IF NOT EXISTS fn_stock_disponible(p_id_prod INT, p_id_sucursal INT) 
RETURNS INT
READS SQL DATA
BEGIN
    DECLARE v_stock INT DEFAULT 0;
    
    -- Este valor es referencial, el stock real está en nodos sucursales
    SELECT COALESCE(MAX(cantidad), 0) INTO v_stock
    FROM stock
    WHERE id_prod = p_id_prod AND id_suc = p_id_sucursal;
    
    RETURN v_stock;
END //

DELIMITER ;

-- ============================================================================
-- PROCEDIMIENTOS PARA CARRITO
-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_carrito_activo(
    IN p_id_cli INT
)
BEGIN
    DECLARE v_id_carrito INT;
    DECLARE v_id_usuario INT;
    
    -- Obtener usuario actual de la sesión (si existe)
    SET v_id_usuario = @lm_id_usuario;
    
    -- Buscar carrito activo existente
    SELECT id_carrito INTO v_id_carrito
    FROM carrito
    WHERE id_cli = p_id_cli AND estado = 'activo'
    ORDER BY id_carrito DESC
    LIMIT 1;
    
    -- Si no existe, crear uno nuevo
    IF v_id_carrito IS NULL THEN
        INSERT INTO carrito (id_cli, estado, fecha_creacion)
        VALUES (p_id_cli, 'activo', NOW());
        
        SET v_id_carrito = LAST_INSERT_ID();
        
        -- Log de creación de carrito
        INSERT INTO log_transacciones (
            id_transaccion, tipo_operacion, id_cliente, estado, nodo_origen,
            datos_adicionales
        ) VALUES (
            CONCAT('CART-', v_id_carrito, '-', UNIX_TIMESTAMP()),
            'carrito',
            p_id_cli,
            'inicio',
            'central',
            JSON_OBJECT('accion', 'crear_carrito')
        );
    END IF;
    
    -- Retornar carrito con items
    SELECT 
        c.id_carrito,
        c.id_cli,
        c.fecha_creacion,
        c.estado,
        COALESCE(SUM(dc.cantidad * dc.precio_unitario), 0) AS total
    FROM carrito c
    LEFT JOIN detalle_carrito dc ON dc.id_carrito = c.id_carrito
    WHERE c.id_carrito = v_id_carrito
    GROUP BY c.id_carrito, c.id_cli, c.fecha_creacion, c.estado;
    
    -- Retornar items del carrito
    SELECT 
        dc.id_detalle_carrito,
        dc.id_prod,
        dc.cantidad,
        dc.precio_unitario,
        p.producto,
        p.precio,
        (dc.cantidad * dc.precio_unitario) AS subtotal
    FROM detalle_carrito dc
    INNER JOIN productos p ON p.id_prod = dc.id_prod
    WHERE dc.id_carrito = v_id_carrito
    ORDER BY dc.id_detalle_carrito ASC;
END //

DELIMITER ;

-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_carrito_agregar(
    IN p_id_cli INT,
    IN p_id_prod INT,
    IN p_cantidad INT,
    IN p_precio_unitario DECIMAL(12,2)
)
BEGIN
    DECLARE v_id_carrito INT;
    DECLARE v_id_detalle INT;
    DECLARE v_cantidad_actual INT;
    DECLARE v_producto VARCHAR(120);
    DECLARE v_activo INT;
    DECLARE v_error_msg TEXT;
    DECLARE v_id_transaccion VARCHAR(50);
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 v_error_msg = MESSAGE_TEXT;
        
        INSERT INTO log_transacciones (
            id_transaccion, tipo_operacion, id_cliente, id_producto,
            estado, nodo_origen, mensaje_error, datos_adicionales
        ) VALUES (
            v_id_transaccion,
            'carrito',
            p_id_cli,
            p_id_prod,
            'fallida',
            'central',
            v_error_msg,
            JSON_OBJECT('cantidad', p_cantidad)
        );
        
        RESIGNAL;
    END;
    
    -- Verificar que el producto exista y esté activo
    SELECT producto, activo INTO v_producto, v_activo
    FROM productos
    WHERE id_prod = p_id_prod
    FOR UPDATE;
    
    IF v_activo = 0 OR v_producto IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El producto no está disponible';
    END IF;
    
    -- Obtener o crear carrito activo
    SELECT id_carrito INTO v_id_carrito
    FROM carrito
    WHERE id_cli = p_id_cli AND estado = 'activo'
    ORDER BY id_carrito DESC
    LIMIT 1
    FOR UPDATE;
    
    IF v_id_carrito IS NULL THEN
        INSERT INTO carrito (id_cli, estado, fecha_creacion)
        VALUES (p_id_cli, 'activo', NOW());
        
        SET v_id_carrito = LAST_INSERT_ID();
    END IF;
    
    -- Generar ID de transacción único
    SET v_id_transaccion = CONCAT('CART-ADD-', v_id_carrito, '-', p_id_prod, '-', UNIX_TIMESTAMP());
    
    INSERT INTO log_transacciones (
        id_transaccion, tipo_operacion, id_cliente, id_producto,
        cantidad, estado, nodo_origen, datos_adicionales
    ) VALUES (
        v_id_transaccion,
        'carrito',
        p_id_cli,
        p_id_prod,
        p_cantidad,
        'inicio',
        'central',
        JSON_OBJECT('precio', p_precio_unitario, 'carrito', v_id_carrito)
    );
    
    -- Buscar detalle existente
    SELECT id_detalle_carrito, cantidad INTO v_id_detalle, v_cantidad_actual
    FROM detalle_carrito
    WHERE id_carrito = v_id_carrito AND id_prod = p_id_prod
    FOR UPDATE;
    
    IF v_id_detalle IS NOT NULL THEN
        -- Actualizar cantidad
        UPDATE detalle_carrito
        SET cantidad = v_cantidad_actual + p_cantidad,
            precio_unitario = p_precio_unitario
        WHERE id_detalle_carrito = v_id_detalle;
    ELSE
        -- Insertar nuevo item
        INSERT INTO detalle_carrito (id_carrito, id_prod, cantidad, precio_unitario)
        VALUES (v_id_carrito, p_id_prod, p_cantidad, p_precio_unitario);
    END IF;
    
    -- Actualizar log
    UPDATE log_transacciones
    SET estado = 'confirmada',
        datos_adicionales = JSON_SET(datos_adicionales, '$.id_detalle', 
            COALESCE(v_id_detalle, LAST_INSERT_ID()))
    WHERE id_transaccion = v_id_transaccion;
    
    -- Retornar carrito actualizado
    CALL sp_carrito_activo(p_id_cli);
END //

DELIMITER ;

-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_carrito_eliminar(
    IN p_id_cli INT,
    IN p_id_prod INT
)
BEGIN
    DECLARE v_id_carrito INT;
    
    SELECT id_carrito INTO v_id_carrito
    FROM carrito
    WHERE id_cli = p_id_cli AND estado = 'activo'
    ORDER BY id_carrito DESC
    LIMIT 1;
    
    IF v_id_carrito IS NOT NULL THEN
        DELETE FROM detalle_carrito
        WHERE id_carrito = v_id_carrito AND id_prod = p_id_prod;
        
        -- Log
        INSERT INTO log_transacciones (
            id_transaccion, tipo_operacion, id_cliente, id_producto,
            estado, nodo_origen, datos_adicionales
        ) VALUES (
            CONCAT('CART-DEL-', v_id_carrito, '-', p_id_prod, '-', UNIX_TIMESTAMP()),
            'carrito',
            p_id_cli,
            p_id_prod,
            'confirmada',
            'central',
            JSON_OBJECT('accion', 'eliminar_item')
        );
    END IF;
    
    CALL sp_carrito_activo(p_id_cli);
END //

DELIMITER ;

-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_carrito_vaciar(
    IN p_id_cli INT
)
BEGIN
    DECLARE v_id_carrito INT;
    
    SELECT id_carrito INTO v_id_carrito
    FROM carrito
    WHERE id_cli = p_id_cli AND estado = 'activo'
    ORDER BY id_carrito DESC
    LIMIT 1;
    
    IF v_id_carrito IS NOT NULL THEN
        -- Eliminar items
        DELETE FROM detalle_carrito
        WHERE id_carrito = v_id_carrito;
        
        -- Cambiar estado del carrito
        UPDATE carrito
        SET estado = 'cancelado'
        WHERE id_carrito = v_id_carrito;
        
        -- Log
        INSERT INTO log_transacciones (
            id_transaccion, tipo_operacion, id_cliente,
            estado, nodo_origen, datos_adicionales
        ) VALUES (
            CONCAT('CART-EMPTY-', v_id_carrito, '-', UNIX_TIMESTAMP()),
            'carrito',
            p_id_cli,
            'confirmada',
            'central',
            JSON_OBJECT('accion', 'vaciar_carrito')
        );
    END IF;
END //

DELIMITER ;

-- ============================================================================
-- PROCEDIMIENTO PARA VENTAS (2PC - FASE CENTRAL)
-- ============================================================================

-- Asegurar que ventas.estado incluya 'preparada'
ALTER TABLE ventas
MODIFY COLUMN estado ENUM('pendiente', 'preparada', 'confirmada', 'cancelada') NOT NULL DEFAULT 'pendiente';

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_venta_2pc(
    IN p_id_cli INT,
    IN p_id_suc INT,
    IN p_items_json JSON,
    IN p_id_carrito INT,
    OUT p_id_venta INT,
    OUT p_total DECIMAL(12,2),
    OUT p_error_msg TEXT
)
BEGIN
    DECLARE v_cliente_valido INT;
    DECLARE v_sucursal_valida INT;
    DECLARE v_item JSON;
    DECLARE v_id_prod INT;
    DECLARE v_cantidad INT;
    DECLARE v_precio DECIMAL(12,2);
    DECLARE v_subtotal DECIMAL(12,2);
    DECLARE v_i INT DEFAULT 0;
    DECLARE v_total_items INT;
    DECLARE v_total DECIMAL(12,2) DEFAULT 0;
    DECLARE v_error TEXT;
    DECLARE v_id_transaccion VARCHAR(50);
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 v_error = MESSAGE_TEXT;
        SET p_error_msg = v_error;
        SET p_id_venta = NULL;
        SET p_total = 0;
        
        -- Log de error
        IF v_id_transaccion IS NOT NULL THEN
            UPDATE log_transacciones
            SET estado = 'fallida',
                mensaje_error = v_error
            WHERE id_transaccion = v_id_transaccion;
        END IF;
        
        ROLLBACK;
    END;
    
    -- Iniciar transacción
    START TRANSACTION;
    
    -- Generar ID de transacción
    SET v_id_transaccion = CONCAT('VENTA-', UNIX_TIMESTAMP(), '-', FLOOR(RAND() * 1000000));
    
    -- Log de inicio
    INSERT INTO log_transacciones (
        id_transaccion, tipo_operacion, id_cliente, id_sucursal,
        estado, nodo_origen, datos_adicionales
    ) VALUES (
        v_id_transaccion,
        'venta',
        p_id_cli,
        p_id_suc,
        'inicio',
        'central',
        JSON_OBJECT('id_carrito', p_id_carrito, 'items_count', JSON_LENGTH(p_items_json))
    );
    
    -- Validar cliente
    SELECT id_cli INTO v_cliente_valido
    FROM clientes
    WHERE id_cli = p_id_cli AND activo = 1
    FOR UPDATE;
    
    IF v_cliente_valido IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Cliente inválido o inactivo';
    END IF;
    
    -- Validar sucursal
    SELECT id_suc INTO v_sucursal_valida
    FROM sucursales
    WHERE id_suc = p_id_suc AND activo = 1
    FOR UPDATE;
    
    IF v_sucursal_valida IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Sucursal inválida o inactiva';
    END IF;
    
    -- Calcular total y validar items
    SET v_total = 0;
    SET v_total_items = JSON_LENGTH(p_items_json);
    
    -- Insertar venta (estado pendiente)
    INSERT INTO ventas (id_cli, id_suc, total, estado, fecha_venta)
    VALUES (p_id_cli, p_id_suc, 0, 'pendiente', NOW());
    
    SET p_id_venta = LAST_INSERT_ID();
    
    -- Procesar cada item
    WHILE v_i < v_total_items DO
        SET v_item = JSON_EXTRACT(p_items_json, CONCAT('$[', v_i, ']'));
        SET v_id_prod = JSON_UNQUOTE(JSON_EXTRACT(v_item, '$.id_prod'));
        SET v_cantidad = JSON_UNQUOTE(JSON_EXTRACT(v_item, '$.cantidad'));
        SET v_precio = JSON_UNQUOTE(JSON_EXTRACT(v_item, '$.precio_unitario'));
        
        -- Validar producto
        IF NOT EXISTS (
            SELECT 1 FROM productos 
            WHERE id_prod = v_id_prod AND activo = 1
            FOR UPDATE
        ) THEN
            SET p_error_msg = CONCAT('Producto inválido: ', v_id_prod);
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = p_error_msg;
        END IF;
        
        SET v_subtotal = v_cantidad * v_precio;
        SET v_total = v_total + v_subtotal;
        
        -- Insertar detalle de venta
        INSERT INTO detalle_ventas (id_venta, id_prod, cantidad, precio_unitario, subtotal)
        VALUES (p_id_venta, v_id_prod, v_cantidad, v_precio, v_subtotal);
        
        SET v_i = v_i + 1;
    END WHILE;
    
    -- Actualizar total de venta
    UPDATE ventas
    SET total = v_total, estado = 'preparada'
    WHERE id_venta = p_id_venta;
    
    SET p_total = v_total;
    
    -- Actualizar log
    UPDATE log_transacciones
    SET estado = 'preparada',
        monto_total = v_total,
        datos_adicionales = JSON_SET(datos_adicionales, '$.id_venta', p_id_venta)
    WHERE id_transaccion = v_id_transaccion;
    
    -- El commit se hace desde PHP después de confirmar stock
    -- Aquí solo dejamos la transacción abierta
END //

DELIMITER ;

-- ============================================================================
-- PROCEDIMIENTO PARA CONFIRMAR VENTA (POST 2PC)
-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_venta_confirmar(
    IN p_id_venta INT,
    IN p_id_carrito INT
)
BEGIN
    DECLARE v_error TEXT;
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 v_error = MESSAGE_TEXT;
        
        UPDATE log_transacciones
        SET estado = 'fallida',
            mensaje_error = v_error
        WHERE id_transaccion LIKE CONCAT('VENTA-', '%')
          AND datos_adicionales->>'$.id_venta' = CAST(p_id_venta AS CHAR);
        
        ROLLBACK;
        RESIGNAL;
    END;
    
    -- Actualizar estado de venta
    UPDATE ventas
    SET estado = 'confirmada'
    WHERE id_venta = p_id_venta;
    
    -- Actualizar carrito si existe
    IF p_id_carrito IS NOT NULL AND p_id_carrito > 0 THEN
        UPDATE carrito
        SET estado = 'pagado'
        WHERE id_carrito = p_id_carrito;
    END IF;
    
    -- Log de confirmación
    UPDATE log_transacciones
    SET estado = 'confirmada'
    WHERE tipo_operacion = 'venta'
      AND id_transaccion LIKE CONCAT('VENTA-', '%')
      AND datos_adicionales->>'$.id_venta' = CAST(p_id_venta AS CHAR)
    LIMIT 1;
    
    COMMIT;
END //

DELIMITER ;

-- ============================================================================
-- PROCEDIMIENTO PARA REVERTIR VENTA (ROLLBACK)
-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_venta_revertir(
    IN p_id_venta INT
)
BEGIN
    DECLARE v_error TEXT;
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 v_error = MESSAGE_TEXT;
        
        UPDATE log_transacciones
        SET estado = 'fallida',
            mensaje_error = v_error
        WHERE tipo_operacion = 'rollback'
          AND datos_adicionales->>'$.id_venta' = CAST(p_id_venta AS CHAR);
        
        RESIGNAL;
    END;
    
    -- Cambiar estado de venta a cancelada
    UPDATE ventas
    SET estado = 'cancelada'
    WHERE id_venta = p_id_venta;
    
    -- Log de rollback
    INSERT INTO log_transacciones (
        id_transaccion, tipo_operacion, id_sucursal,
        estado, nodo_origen, mensaje_error, datos_adicionales
    ) VALUES (
        CONCAT('ROLLBACK-', p_id_venta, '-', UNIX_TIMESTAMP()),
        'rollback',
        (SELECT id_suc FROM ventas WHERE id_venta = p_id_venta),
        'rollback',
        'central',
        'Reversión de venta por fallo en 2PC',
        JSON_OBJECT('id_venta', p_id_venta, 'razon', 'fallo_2pc')
    );
    
    COMMIT;
END //

DELIMITER ;

-- ============================================================================
-- PROCEDIMIENTO PARA COMPRAS (2PC - FASE CENTRAL)
-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_compra_2pc(
    IN p_id_proveedor INT,
    IN p_id_suc INT,
    IN p_id_prod INT,
    IN p_cantidad INT,
    IN p_precio_unitario DECIMAL(12,2),
    OUT p_id_compra INT,
    OUT p_total DECIMAL(12,2),
    OUT p_error_msg TEXT
)
BEGIN
    DECLARE v_proveedor_valido INT;
    DECLARE v_sucursal_valida INT;
    DECLARE v_producto_valido INT;
    DECLARE v_producto_nombre VARCHAR(120);
    DECLARE v_error TEXT;
    DECLARE v_id_transaccion VARCHAR(50);
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 v_error = MESSAGE_TEXT;
        SET p_error_msg = v_error;
        SET p_id_compra = NULL;
        SET p_total = 0;
        
        IF v_id_transaccion IS NOT NULL THEN
            UPDATE log_transacciones
            SET estado = 'fallida',
                mensaje_error = v_error
            WHERE id_transaccion = v_id_transaccion;
        END IF;
        
        ROLLBACK;
    END;
    
    START TRANSACTION;
    
    -- Generar ID de transacción
    SET v_id_transaccion = CONCAT('COMPRA-', UNIX_TIMESTAMP(), '-', FLOOR(RAND() * 1000000));
    
    -- Log de inicio
    INSERT INTO log_transacciones (
        id_transaccion, tipo_operacion, id_sucursal, id_producto,
        cantidad, estado, nodo_origen, datos_adicionales
    ) VALUES (
        v_id_transaccion,
        'compra',
        p_id_suc,
        p_id_prod,
        p_cantidad,
        'inicio',
        'central',
        JSON_OBJECT('id_proveedor', p_id_proveedor, 'precio', p_precio_unitario)
    );
    
    -- Validar proveedor
    SELECT id_proveedor INTO v_proveedor_valido
    FROM proveedores
    WHERE id_proveedor = p_id_proveedor AND activo = 1
    FOR UPDATE;
    
    IF v_proveedor_valido IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Proveedor inválido o inactivo';
    END IF;
    
    -- Validar sucursal
    SELECT id_suc INTO v_sucursal_valida
    FROM sucursales
    WHERE id_suc = p_id_suc AND activo = 1
    FOR UPDATE;
    
    IF v_sucursal_valida IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Sucursal inválida o inactiva';
    END IF;
    
    -- Validar producto
    SELECT id_prod, producto INTO v_producto_valido, v_producto_nombre
    FROM productos
    WHERE id_prod = p_id_prod AND activo = 1
    FOR UPDATE;
    
    IF v_producto_valido IS NULL THEN
        SET v_error = CONCAT('Producto inválido: ', p_id_prod);
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = v_error;
    END IF;
    
    -- Calcular total
    SET p_total = p_cantidad * p_precio_unitario;
    
    -- Insertar compra
    INSERT INTO compras (id_proveedor, id_suc, total, fecha_compra)
    VALUES (p_id_proveedor, p_id_suc, p_total, NOW());
    
    SET p_id_compra = LAST_INSERT_ID();
    
    -- Insertar detalle de compra
    INSERT INTO detalle_compras (id_compra, id_prod, cantidad, precio_unitario, subtotal)
    VALUES (p_id_compra, p_id_prod, p_cantidad, p_precio_unitario, p_total);
    
    -- Actualizar log
    UPDATE log_transacciones
    SET estado = 'preparada',
        monto_total = p_total,
        datos_adicionales = JSON_SET(datos_adicionales, '$.id_compra', p_id_compra, '$.producto', v_producto_nombre)
    WHERE id_transaccion = v_id_transaccion;
    
    -- El commit se hace desde PHP después de confirmar reposición de stock
END //

DELIMITER ;

-- ============================================================================
-- PROCEDIMIENTO PARA CONFIRMAR COMPRA (POST 2PC)
-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_compra_confirmar(
    IN p_id_compra INT
)
BEGIN
    DECLARE v_error TEXT;
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 v_error = MESSAGE_TEXT;
        
        UPDATE log_transacciones
        SET estado = 'fallida',
            mensaje_error = v_error
        WHERE tipo_operacion = 'compra'
          AND id_transaccion LIKE CONCAT('COMPRA-', '%')
          AND datos_adicionales->>'$.id_compra' = CAST(p_id_compra AS CHAR);
        
        RESIGNAL;
    END;
    
    -- Log de confirmación
    UPDATE log_transacciones
    SET estado = 'confirmada'
    WHERE tipo_operacion = 'compra'
      AND id_transaccion LIKE CONCAT('COMPRA-', '%')
      AND datos_adicionales->>'$.id_compra' = CAST(p_id_compra AS CHAR)
    LIMIT 1;
    
    COMMIT;
END //

DELIMITER ;

-- ============================================================================
-- PROCEDIMIENTO PARA REVERTIR COMPRA (ROLLBACK)
-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_compra_revertir(
    IN p_id_compra INT
)
BEGIN
    DECLARE v_error TEXT;
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 v_error = MESSAGE_TEXT;
        
        UPDATE log_transacciones
        SET estado = 'fallida',
            mensaje_error = v_error
        WHERE tipo_operacion = 'rollback'
          AND datos_adicionales->>'$.id_compra' = CAST(p_id_compra AS CHAR);
        
        RESIGNAL;
    END;
    
    -- Cambiar estado de compra a cancelada
    UPDATE compras
    SET total = 0
    WHERE id_compra = p_id_compra;
    
    -- Eliminar detalle
    DELETE FROM detalle_compras WHERE id_compra = p_id_compra;
    
    -- Log de rollback
    INSERT INTO log_transacciones (
        id_transaccion, tipo_operacion, id_sucursal,
        estado, nodo_origen, mensaje_error, datos_adicionales
    ) VALUES (
        CONCAT('ROLLBACK-COMPRA-', p_id_compra, '-', UNIX_TIMESTAMP()),
        'rollback',
        (SELECT id_suc FROM compras WHERE id_compra = p_id_compra),
        'rollback',
        'central',
        'Reversión de compra por fallo en 2PC',
        JSON_OBJECT('id_compra', p_id_compra, 'razon', 'fallo_2pc')
    );
    
    COMMIT;
END //

DELIMITER ;

-- ============================================================================
-- PROCEDIMIENTO PARA CONSULTAS Y REPORTES
-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_ventas_listar()
BEGIN
    SELECT 
        v.id_venta,
        c.cliente,
        s.sucursal,
        v.total,
        v.fecha_venta AS fecha,
        v.estado
    FROM ventas v
    INNER JOIN clientes c ON c.id_cli = v.id_cli
    INNER JOIN sucursales s ON s.id_suc = v.id_suc
    ORDER BY v.fecha_venta DESC;
END //

CREATE PROCEDURE IF NOT EXISTS sp_ventas_detalle(
    IN p_id_venta INT
)
BEGIN
    SELECT 
        dv.id_detalle_venta,
        dv.id_prod,
        p.producto,
        dv.cantidad,
        dv.precio_unitario,
        dv.subtotal
    FROM detalle_ventas dv
    INNER JOIN productos p ON p.id_prod = dv.id_prod
    WHERE dv.id_venta = p_id_venta
    ORDER BY dv.id_detalle_venta ASC;
END //

CREATE PROCEDURE IF NOT EXISTS sp_compras_listar()
BEGIN
    SELECT 
        c.id_compra,
        p.proveedor,
        COALESCE(GROUP_CONCAT(DISTINCT pr.producto ORDER BY pr.producto SEPARATOR ', '), 'Sin producto') AS producto,
        SUM(dc.cantidad) AS cantidad,
        s.sucursal,
        c.total,
        c.fecha_compra AS fecha
    FROM compras c
    INNER JOIN proveedores p ON p.id_proveedor = c.id_proveedor
    INNER JOIN sucursales s ON s.id_suc = c.id_suc
    LEFT JOIN detalle_compras dc ON dc.id_compra = c.id_compra
    LEFT JOIN productos pr ON pr.id_prod = dc.id_prod
    GROUP BY c.id_compra, p.proveedor, s.sucursal, c.total, c.fecha_compra
    ORDER BY c.fecha_compra DESC;
END //

CREATE PROCEDURE IF NOT EXISTS sp_compras_detalle(
    IN p_id_compra INT
)
BEGIN
    SELECT 
        dc.id_detalle_compra,
        dc.id_prod,
        p.producto,
        dc.cantidad,
        dc.precio_unitario,
        dc.subtotal
    FROM detalle_compras dc
    INNER JOIN productos p ON p.id_prod = dc.id_prod
    WHERE dc.id_compra = p_id_compra
    ORDER BY dc.id_detalle_compra ASC;
END //

DELIMITER ;

-- ============================================================================
-- PROCEDIMIENTO PARA LOGS DE TRANSACCIONES
-- ============================================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_logs_transacciones(
    IN p_limite INT,
    IN p_tipo VARCHAR(20),
    IN p_estado VARCHAR(20)
)
BEGIN
    DECLARE v_limite INT DEFAULT COALESCE(p_limite, 50);
    
    SELECT 
        id_log,
        id_transaccion,
        tipo_operacion,
        id_usuario,
        id_cliente,
        id_sucursal,
        cantidad,
        monto_total,
        estado,
        nodo_origen,
        nodo_destino,
        mensaje_error,
        fecha_creacion
    FROM log_transacciones
    WHERE (p_tipo IS NULL OR tipo_operacion = p_tipo)
      AND (p_estado IS NULL OR estado = p_estado)
    ORDER BY fecha_creacion DESC
    LIMIT v_limite;
END //

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