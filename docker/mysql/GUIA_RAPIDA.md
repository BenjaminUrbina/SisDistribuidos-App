# 🚀 Guía Rápida - Procedimientos Almacenados Libre Mercado

## ⚡ Inicio Rápido

### 1. Instalar Procedimientos

```bash
# Opción A: Usar Seeder (Recomendado)
# Abrir en navegador:
http://localhost:8080/seeder.php

# Opción B: Manual
cd /path/to/libre_mercado
mysql -u root -p libremercado_central < docker/mysql/procedures_central.sql
mysql -u root -h 127.0.0.1 -P 3308 -p libremercado_sucursal1 < docker/mysql/procedures_sucursal.sql
mysql -u root -h 127.0.0.1 -P 3309 -p libremercado_sucursal2 < docker/mysql/procedures_sucursal.sql
```

### 2. Probar Concurrencia (2 Usuarios)

```bash
# Abrir 2 pestañas con diferentes usuarios
http://localhost:8080/test_concurrencia.php?token=USER1
http://localhost:8080/test_concurrencia.php?token=USER2

# En ambas pestañas:
# 1. Seleccionar mismo producto y sucursal
# 2. Hacer clic en "Comprar" simultáneamente
# 3. Observar locks y rollbacks en los logs
```

### 3. Probar Caída de Nodos

```bash
# Abrir monitor de nodos
http://localhost:8080/nodos.php

# Simular caída:
# 1. Click en "Caer La Serena"
# 2. Intentar comprar productos de esa sucursal
# 3. El sistema rechazará la venta (CP)
# 4. Click en "Restaurar" para recuperar
```

## 📋 Funciones Principales

### ✅ Funciones que se Mantienen

| Función Original             | Reemplazo con SP            | Estado        |
| ---------------------------- | --------------------------- | ------------- |
| `lm_registrar_venta()`       | `sp_registrar_venta_2pc()`  | ✅ Compatible |
| `lm_registrar_compra()`      | `sp_registrar_compra_2pc()` | ✅ Compatible |
| `lm_stock_actualizar()`      | `sp_stock_actualizar()`     | ✅ Compatible |
| `lm_carrito_activo()`        | `sp_carrito_activo()`       | ✅ Compatible |
| `lm_carrito_agregar_item()`  | `sp_carrito_agregar()`      | ✅ Compatible |
| `lm_carrito_eliminar_item()` | `sp_carrito_eliminar()`     | ✅ Compatible |
| `lm_carrito_vaciar()`        | `sp_carrito_vaciar()`       | ✅ Compatible |

**Nota:** Las funciones originales siguen funcionando (hay wrappers de compatibilidad en `lm_stored_procedures.php`)

### 🆕 Nuevas Funciones

```php
// Consultar stock
$stock = sp_stock_consultar();
$stockBajo = sp_stock_bajo();

// Ver logs
$logs = sp_logs_transacciones(50, 'venta', 'confirmada');
sp_logs_limpiar(30); // Limpiar logs > 30 días

// Testing
$testResultado = sp_testear_concurrencia(1, 10, 2);
$recuperacion = sp_simular_recuperacion_post_caida();

// Reportes
$ventas = sp_ventas_listar();
$detalleVenta = sp_ventas_detalle(123);
$compras = sp_compras_listar();
$detalleCompra = sp_compras_detalle(456);
```

## 🔍 Verificación de Instalación

### Desde PHP

```php
<?php
require_once 'includes/config.php';
require_once 'includes/lm_stored_procedures.php';

// Verificar SPs instalados
try {
    $pdo = lm_pdo('central');
    $stmt = $pdo->query("
        SELECT ROUTINE_NAME
        FROM information_schema.ROUTINES
        WHERE ROUTINE_SCHEMA = 'libremercado_central'
        AND ROUTINE_TYPE = 'PROCEDURE'
    ");
    $procs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Procedimientos centrales: " . count($procs) . "\n";
    print_r($procs);
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage();
}
```

### Desde MySQL

```sql
-- Ver procedimientos en nodo central
USE libremercado_central;
SHOW PROCEDURE STATUS WHERE Db = 'libremercado_central';

-- Ver procedimientos en sucursales
USE libremercado_sucursal1;
SHOW PROCEDURE STATUS WHERE Db = 'libremercado_sucursal1';

-- Ver tabla de logs
SELECT COUNT(*) FROM log_transacciones;
SELECT * FROM log_transacciones ORDER BY fecha_creacion DESC LIMIT 10;
```

## 🧪 Casos de Prueba

### Test 1: Venta Normal

```php
$items = [
    ['id_prod' => 1, 'cantidad' => 2, 'precio_unitario' => 899990],
];

try {
    $resultado = sp_registrar_venta_2pc(1, 2, $items, null);
    echo "✅ Venta exitosa: #{$resultado['id_venta']}";
} catch (Throwable $e) {
    echo "❌ Error: " . $e->getMessage();
}
```

### Test 2: Stock Insuficiente

```php
// Intentar comprar más de lo disponible
$items = [
    ['id_prod' => 1, 'cantidad' => 9999, 'precio_unitario' => 899990],
];

try {
    $resultado = sp_registrar_venta_2pc(1, 2, $items, null);
    echo "✅ Venta exitosa (inesperado!)";
} catch (Throwable $e) {
    echo "✅ Error esperado: " . $e->getMessage();
}
```

### Test 3: Nodo Caído (CP)

```php
// Simular caída de sucursal
lm_simular_caida_nodo('sucursal1', true);

$items = [
    ['id_prod' => 1, 'cantidad' => 1, 'precio_unitario' => 899990],
];

try {
    $resultado = sp_registrar_venta_2pc(1, 2, $items, null);
    echo "✅ Venta exitosa";
} catch (Throwable $e) {
    echo "✅ Error esperado (nodo caído): " . $e->getMessage();
}

// Restaurar nodo
lm_restaurar_nodo('sucursal1');
```

### Test 4: Concurrencia Real

```php
// Ejecutar en 2 procesos paralelos
// Terminal 1:
php -r "require 'includes/config.php'; require 'includes/lm_stored_procedures.php'; sp_registrar_venta_2pc(1, 2, [['id_prod'=>1,'cantidad'=>1,'precio_unitario'=>899990]], null);"

// Terminal 2 (ejecutar simultáneamente):
php -r "require 'includes/config.php'; require 'includes/lm_stored_procedures.php'; sp_registrar_venta_2pc(1, 2, [['id_prod'=>1,'cantidad'=>1,'precio_unitario'=>899990]], null);"

// Solo una debería tener éxito si el stock es limitado
```

## 📊 Monitoreo

### Ver Transacciones en Tiempo Real

```sql
-- Últimas transacciones
SELECT
    id_transaccion,
    tipo_operacion,
    estado,
    monto_total,
    nodo_origen,
    fecha_creacion
FROM log_transacciones
ORDER BY fecha_creacion DESC
LIMIT 20;

-- Transacciones fallidas
SELECT
    id_transaccion,
    tipo_operacion,
    mensaje_error,
    fecha_creacion
FROM log_transacciones
WHERE estado = 'fallida'
ORDER BY fecha_creacion DESC;

-- Estadísticas por tipo
SELECT
    tipo_operacion,
    estado,
    COUNT(*) AS cantidad,
    SUM(monto_total) AS monto_total
FROM log_transacciones
GROUP BY tipo_operacion, estado;
```

### Ver Stock

```sql
-- Stock por sucursal
SELECT
    s.id_prod,
    p.producto,
    s.id_suc,
    s.sucursal,
    s.cantidad,
    s.stock_minimo,
    CASE
        WHEN s.cantidad <= 0 THEN 'SIN STOCK'
        WHEN s.cantidad <= s.stock_minimo THEN 'BAJO STOCK'
        ELSE 'OK'
    END AS estado
FROM stock s
LEFT JOIN productos p ON p.id_prod = s.id_prod
ORDER BY s.id_suc, s.id_prod;
```

## 🆘 Troubleshooting

### Error: "Nodo no disponible"

```php
// Verificar estado
$estado = estadoNodos();
print_r($estado);

// Si hay nodos caídos, restaurar
lm_restaurar_todos_los_nodos();
```

### Error: "Procedure not found"

```bash
# Re-instalar procedimientos
mysql -u root -p libremercado_central < docker/mysql/procedures_central.sql
```

### Error: "Stock insuficiente"

```php
// Ver stock actual
$stock = sp_stock_consultar();
print_r($stock);

// Repone stock
sp_stock_actualizar(1, 2, 50, 'Reposición urgente');
```

## 📚 Recursos Adicionales

- **Documentación completa:** `docker/mysql/README.md`
- **Scripts de test:** `docker/mysql/test_procedures.sql`
- **Wrappers PHP:** `front/includes/lm_stored_procedures.php`
- **UI de testing:** `front/test_concurrencia.php`

## ✅ Checklist de Verificación

- [ ] Seeder ejecutado exitosamente
- [ ] Procedimientos instalados en los 3 nodos
- [ ] Tabla `log_transacciones` creada
- [ ] Test de concurrencia funciona con 2 usuarios
- [ ] Caída de nodos se puede simular desde `nodos.php`
- [ ] Ventas y compras usan 2PC con locks
- [ ] Logs se generan correctamente
- [ ] Rollback funciona ante fallos

---

**🎯 Todo listo para usar!** Los procedimientos almacenados están integrados y mantienen compatibilidad con el código existente.
