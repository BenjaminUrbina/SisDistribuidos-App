# Procedimientos Almacenados - Libre Mercado

## 📋 Resumen

Este directorio contiene los procedimientos almacenados MySQL para el sistema distribuido Libre Mercado. Los SPs reemplazan las funciones PHP anteriores y proporcionan:

- ✅ **Transacciones 2PC** con locks explícitos (`SELECT ... FOR UPDATE`)
- ✅ **Soporte para concurrencia** (múltiples usuarios simultáneos)
- ✅ **Rollback automático** en caso de fallo
- ✅ **Logs de auditoría** en tabla `log_transacciones`
- ✅ **Testing de resistencia** a partición de red

## 🗂️ Archivos

| Archivo | Descripción |
|---------|-------------|
| `procedures_central.sql` | SPs del nodo central (ventas, compras, carrito) |
| `procedures_sucursal.sql` | SPs de nodos sucursales (stock, movimientos) |
| `test_procedures.sql` | Scripts de prueba manual de todos los SPs |

## 📦 Procedimientos Instalados

### Nodo Central (`libremercado_central`)

#### Carrito
- `sp_carrito_activo(id_cli)` - Obtener/crear carrito activo
- `sp_carrito_agregar(id_cli, id_prod, cantidad, precio)` - Agregar item al carrito
- `sp_carrito_eliminar(id_cli, id_prod)` - Eliminar item del carrito
- `sp_carrito_vaciar(id_cli)` - Vaciar carrito completo

#### Ventas (2PC)
- `sp_venta_2pc(id_cli, id_suc, items_json, id_carrito, OUT id_venta, OUT total, OUT error_msg)` - Fase 1: Crear venta
- `sp_venta_confirmar(id_venta, id_carrito)` - Fase 2: Confirmar venta
- `sp_venta_revertir(id_venta)` - Rollback de venta

#### Compras (2PC)
- `sp_compra_2pc(id_prov, id_suc, id_prod, cantidad, precio, OUT id_compra, OUT total, OUT error_msg)` - Fase 1: Crear compra
- `sp_compra_confirmar(id_compra)` - Fase 2: Confirmar compra
- `sp_compra_revertir(id_compra)` - Rollback de compra

#### Consultas
- `sp_ventas_listar()` - Listar todas las ventas
- `sp_ventas_detalle(id_venta)` - Detalle de venta
- `sp_compras_listar()` - Listar todas las compras
- `sp_compras_detalle(id_compra)` - Detalle de compra

#### Logs
- `sp_logs_transacciones(limite, tipo, estado)` - Consultar logs
- `sp_logs_limpiar(dias_antiguedad)` - Eliminar logs antiguos

### Nodos Sucursales (`libremercado_sucursal1`, `libremercado_sucursal2`)

#### Stock
- `sp_descontar_stock_venta(id_venta, id_prod, cantidad, id_suc, OUT exito, OUT error_msg)` - Descontar stock por venta
- `sp_reponer_stock_compra(id_compra, id_prod, cantidad, id_suc, motivo, OUT exito, OUT error_msg)` - Repone stock por compra
- `sp_actualizar_stock_manual(id_prod, id_suc, cantidad, motivo, OUT exito, OUT error_msg)` - Ajuste manual de stock
- `sp_sync_stock(id_prod, nombre_producto)` - Sincronizar stock con catálogo

#### Consultas
- `sp_stock_consultar(id_prod)` - Consultar stock de producto
- `sp_stock_todos()` - Todo el stock
- `sp_stock_bajo()` - Stock bajo mínimo
- `sp_movimientos_listar(id_prod, limite)` - Movimientos de stock

#### Testing
- `sp_testear_concurrencia(id_prod, cantidad_ops, cantidad_por_op)` - Test de concurrencia
- `sp_simular_recuperacion_post_caida()` - Recuperación post fallo

## 🚀 Instalación

### Vía Seeder (Recomendado)

```bash
# Ejecutar seeder desde navegador
http://localhost:8080/seeder.php
```

El seeder:
1. Crea todas las tablas
2. Instala los procedimientos en los 3 nodos
3. Pobla datos de prueba
4. Configura stock inicial

### Manual (MySQL CLI)

```bash
# Nodo Central
mysql -u root -p libremercado_central < docker/mysql/procedures_central.sql

# Sucursal 1
mysql -u root -h 127.0.0.1 -P 3308 -p libremercado_sucursal1 < docker/mysql/procedures_sucursal.sql

# Sucursal 2
mysql -u root -h 127.0.0.1 -P 3309 -p libremercado_sucursal2 < docker/mysql/procedures_sucursal.sql
```

## 🧪 Testing

### Test de Concurrencia (2 Usuarios)

```bash
# Abrir en 2 pestañas del navegador
http://localhost:8080/test_concurrencia.php?token=USER1
http://localhost:8080/test_concurrencia.php?token=USER2
```

**Qué probar:**
1. Comprar el mismo producto simultáneamente
2. Observar locks y rollbacks
3. Ver logs de transacciones
4. Probar caída de nodos desde `nodos.php`

### Test Manual (MySQL CLI)

```bash
mysql -u root -p libremercado_central < docker/mysql/test_procedures.sql
```

## 📊 Ejemplos de Uso

### PHP (Usando Wrappers)

```php
// Registrar venta (2PC automático)
$items = [
    ['id_prod' => 1, 'cantidad' => 2, 'precio_unitario' => 899990],
    ['id_prod' => 3, 'cantidad' => 1, 'precio_unitario' => 349990],
];

try {
    $resultado = sp_registrar_venta_2pc(
        1,          // id_cli
        2,          // id_suc
        $items,     // items
        null        // id_carrito (opcional)
    );
    echo "Venta #{$resultado['id_venta']} registrada!";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage();
}

// Consultar stock
$stock = sp_stock_consultar();
foreach ($stock as $item) {
    echo "{$item['producto']} en {$item['sucursal']}: {$item['cantidad']} unidades\n";
}

// Ver logs
$logs = sp_logs_transacciones(50, 'venta', 'confirmada');
```

### MySQL Directo

```sql
-- Crear venta
SET @items = JSON_ARRAY(
    JSON_OBJECT('id_prod', 1, 'cantidad', 2, 'precio_unitario', 899990)
);

CALL sp_venta_2pc(1, 2, @items, NULL, @id_venta, @total, @error);
SELECT @id_venta, @total, @error;

-- Si no hay error, confirmar
CALL sp_venta_confirmar(@id_venta, NULL);

-- Ver logs
CALL sp_logs_transacciones(20, 'venta', NULL);
```

## 🔒 Arquitectura CP

El sistema prioriza **Consistencia + Tolerancia a Partición** sobre Disponibilidad:

- ✅ Si un nodo de stock falla → **Se rechaza la venta**
- ✅ Locks explícitos previenen race conditions
- ✅ 2PC asegura atomicidad entre nodos
- ✅ Rollback automático si cualquier fase falla

## 📝 Logs de Transacciones

Todas las operaciones quedan registradas en `log_transacciones`:

```sql
SELECT 
    id_transaccion,
    tipo_operacion,
    estado,
    monto_total,
    nodo_origen,
    mensaje_error,
    fecha_creacion
FROM log_transacciones
ORDER BY fecha_creacion DESC
LIMIT 20;
```

**Estados posibles:**
- `inicio` - Transacción iniciada
- `preparada` - Fase 1 completada
- `confirmada` - 2PC completado exitosamente
- `fallida` - Error en alguna fase
- `rollback` - Revertida por fallo

## 🛠️ Troubleshooting

### Error: "Stock insuficiente"

```sql
-- Ver stock actual
CALL sp_stock_consultar(id_prod);

-- Ver movimientos recientes
CALL sp_movimientos_listar(id_prod, 10);

-- Repone stock manualmente
CALL sp_actualizar_stock_manual(id_prod, id_suc, 50, 'Reposición urgente');
```

### Error: "Nodo no disponible"

```php
// Verificar estado de nodos
$estado = estadoNodos();
print_r($estado);

// Si un nodo está caído, restaurarlo
lm_restaurar_nodo('sucursal1');
```

### Ver transacciones pendientes

```sql
SELECT * FROM log_transacciones
WHERE estado IN ('inicio', 'preparada')
  AND fecha_creacion < DATE_SUB(NOW(), INTERVAL 5 MINUTE);
```

## 📈 Métricas

### Transacciones por hora

```sql
SELECT 
    DATE_FORMAT(fecha_creacion, '%Y-%m-%d %H:00') AS hora,
    tipo_operacion,
    COUNT(*) AS cantidad,
    SUM(monto_total) AS monto
FROM log_transacciones
WHERE fecha_creacion >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY hora, tipo_operacion
ORDER BY hora;
```

### Tasa de éxito

```sql
SELECT 
    tipo_operacion,
    estado,
    COUNT(*) AS cantidad,
    ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER (PARTITION BY tipo_operacion), 2) AS porcentaje
FROM log_transacciones
GROUP BY tipo_operacion, estado;
```

## 🔗 Referencias

- **Seeder:** `/front/seeder.php`
- **Test Concurrencia:** `/front/test_concurrencia.php`
- **Monitor de Nodos:** `/front/nodos.php`
- **Wrappers PHP:** `/front/includes/lm_stored_procedures.php`