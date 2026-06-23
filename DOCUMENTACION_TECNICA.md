# Documentación Técnica — Libre Mercado

Sistema distribuido de comercio electrónico con arquitectura **CP** (Consistency + Partition Tolerance), transacciones distribuidas **2PC** y almacenamiento particionado en 3 nodos MySQL.

---

## Índice

1. [Arquitectura del Sistema](#1-arquitectura-del-sistema)
2. [Infraestructura Docker](#2-infraestructura-docker)
3. [Modelo de Datos](#3-modelo-de-datos)
4. [Procedimientos Almacenados](#4-procedimientos-almacenados)
5. [Capa PHP](#5-capa-php)
6. [Transacciones Distribuidas (2PC)](#6-transacciones-distribuidas-2pc)
7. [Concurrencia y Locking](#7-concurrencia-y-locking)
8. [Tolerancia a Particiones (CP)](#8-tolerancia-a-particiones-cp)
9. [Seeder: Poblado de Datos](#9-seeder-poblado-de-datos)
10. [Módulo de Testing](#10-módulo-de-testing)
11. [Guía de Despliegue](#11-guía-de-despliegue)
12. [API de Funciones PHP](#12-api-de-funciones-php)

---

## 1. Arquitectura del Sistema

### Topología de Nodos

```
┌─────────────────────────────────────────────────────────────┐
│                     NODO CENTRAL                             │
│  mysql:8 — libremercado_central                              │
│  Tablas: productos, clientes, usuarios, sucursales,          │
│          proveedores, ventas, detalle_ventas, compras,        │
│          detalle_compras, carrito, detalle_carrito,           │
│          log_transacciones                                    │
│  Puerto: 3307 → 3306                                         │
└─────────────────────────────────────────────────────────────┘
                            ▲
                            │
                            ▼
┌────────────────────┐   ┌────────────────────┐
│  SUCURSAL 1        │   │  SUCURSAL 2        │
│  La Serena         │   │  Coquimbo          │
│  libremercado_suc1 │   │  libremercado_suc2 │
│  Tablas: stock,    │   │  Tablas: stock,    │
│  movimientos_stock │   │  movimientos_stock │
│  log_transacciones │   │  log_transacciones │
│  Puerto: 3308→3306 │   │  Puerto: 3309→3306 │
└────────────────────┘   └────────────────────┘
```

### Decisión CAP

El sistema opta por **CP (Consistency + Partition Tolerance)**:
- **Consistencia:** Toda lectura ve los datos más recientes gracias a `SELECT ... FOR UPDATE` y transacciones distribuidas 2PC.
- **Tolerancia a Partición:** Si un nodo de sucursal no responde, la operación se rechaza completamente (no se permite disponibilidad parcial).
- **Disponibilidad sacrificada:** Una sucursal caída impide operaciones en esa sucursal, pero las demás siguen funcionando.

### Mapeo Sucursal → Nodo

| id_suc | Nombre Sucursal | Nodo Base de Datos |
|--------|-----------------|-------------------|
| 1 | Central | `central` |
| 2 | La Serena | `sucursal1` |
| 3 | Coquimbo | `sucursal2` |

---

## 2. Infraestructura Docker

### Servicios

| Servicio | Imagen | Puerto Host | Base de Datos | Volumen |
|----------|--------|-------------|---------------|---------|
| `php-apache` | `php:8.2-apache` + pdo_mysql | `8080:80` | — | `./front:/var/www/html`, `./docker/mysql:/docker/mysql` |
| `mysql-central` | `mysql:8` | `3307:3306` | `libremercado_central` | `lm_central_data` |
| `mysql-sucursal1` | `mysql:8` | `3308:3306` | `libremercado_sucursal1` | `lm_sucursal1_data` |
| `mysql-sucursal2` | `mysql:8` | `3309:3306` | `libremercado_sucursal2` | `lm_sucursal2_data` |

Todas las bases MySQL usan `root:root` como credencial.

### Conexiones desde PHP

Las constantes de conexión se definen en `front/includes/config.php`:

| Constante | Nodo | Host | Puerto |
|-----------|------|------|--------|
| `LM_DB_HOST_CENTRAL` / `LM_DB_PORT_CENTRAL` | Central | `mysql-central` | `3306` |
| `LM_DB_HOST_SUCURSAL1` / `LM_DB_PORT_SUCURSAL1` | Sucursal 1 | `mysql-sucursal1` | `3306` |
| `LM_DB_HOST_SUCURSAL2` / `LM_DB_PORT_SUCURSAL2` | Sucursal 2 | `mysql-sucursal2` | `3306` |
| `LM_DB_USER` | — | `root` | — |
| `LM_DB_PASS` | — | `root` | — |

El sistema detecta automáticamente si corre dentro de Docker (archivo `/.dockerenv`) para ajustar rutas.

---

## 3. Modelo de Datos

### Nodo Central — `libremercado_central`

#### `productos`
| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id_prod` | `INT PK AUTO_INCREMENT` | ID del producto |
| `producto` | `VARCHAR(120)` | Nombre del producto |
| `descripcion` | `TEXT` | Descripción detallada |
| `precio` | `DECIMAL(12,2)` | Precio unitario |
| `activo` | `TINYINT(1) DEFAULT 1` | Soft-delete |

#### `clientes`
| Columna | Tipo |
|---------|------|
| `id_cli` | `INT PK AUTO_INCREMENT` |
| `cliente` | `VARCHAR(120)` |
| `rut` | `VARCHAR(20)` |
| `email` | `VARCHAR(100) UNIQUE` |
| `telefono` | `VARCHAR(20)` |
| `direccion` | `VARCHAR(200)` |
| `activo` | `TINYINT(1) DEFAULT 1` |

#### `usuarios`
| Columna | Tipo |
|---------|------|
| `id_usuario` | `INT PK AUTO_INCREMENT` |
| `nombre` | `VARCHAR(120)` |
| `email` | `VARCHAR(100) UNIQUE` |
| `password` | `VARCHAR(255)` (bcrypt hash) |
| `rol` | `ENUM('admin','vendedor','cliente')` |
| `activo` | `TINYINT(1) DEFAULT 1` |

#### `sucursales`
| Columna | Tipo |
|---------|------|
| `id_suc` | `INT PK AUTO_INCREMENT` |
| `sucursal` | `VARCHAR(100)` |
| `direccion` | `VARCHAR(200)` |
| `activo` | `TINYINT(1) DEFAULT 1` |

#### `proveedores`
| Columna | Tipo |
|---------|------|
| `id_proveedor` | `INT PK AUTO_INCREMENT` |
| `proveedor` | `VARCHAR(120)` |
| `email` | `VARCHAR(100)` |
| `telefono` | `VARCHAR(20)` |
| `direccion` | `VARCHAR(200)` |
| `activo` | `TINYINT(1) DEFAULT 1` |

#### `ventas`
| Columna | Tipo |
|---------|------|
| `id_venta` | `INT PK AUTO_INCREMENT` |
| `id_cli` | `INT` (FK → clientes) |
| `id_suc` | `INT` (FK → sucursales) |
| `total` | `DECIMAL(12,2)` |
| `estado` | `ENUM('pendiente','preparada','confirmada','cancelada')` |
| `fecha_venta` | `DATETIME DEFAULT CURRENT_TIMESTAMP` |

#### `detalle_ventas`
| Columna | Tipo |
|---------|------|
| `id_detalle_venta` | `INT PK AUTO_INCREMENT` |
| `id_venta` | `INT` (FK → ventas) |
| `id_prod` | `INT` (FK → productos) |
| `cantidad` | `INT` |
| `precio_unitario` | `DECIMAL(12,2)` |
| `subtotal` | `DECIMAL(12,2)` |

#### `compras`
| Columna | Tipo |
|---------|------|
| `id_compra` | `INT PK AUTO_INCREMENT` |
| `id_proveedor` | `INT` (FK → proveedores) |
| `id_suc` | `INT` (FK → sucursales) |
| `total` | `DECIMAL(12,2)` |
| `fecha_compra` | `DATETIME DEFAULT CURRENT_TIMESTAMP` |

#### `detalle_compras`
| Columna | Tipo |
|---------|------|
| `id_detalle_compra` | `INT PK AUTO_INCREMENT` |
| `id_compra` | `INT` (FK → compras) |
| `id_prod` | `INT` (FK → productos) |
| `cantidad` | `INT` |
| `precio_unitario` | `DECIMAL(12,2)` |
| `subtotal` | `DECIMAL(12,2)` |

#### `carrito`
| Columna | Tipo |
|---------|------|
| `id_carrito` | `INT PK AUTO_INCREMENT` |
| `id_cli` | `INT` (FK → clientes) |
| `fecha_creacion` | `DATETIME DEFAULT CURRENT_TIMESTAMP` |
| `estado` | `ENUM('activo','pagado','cancelado')` |

#### `detalle_carrito`
| Columna | Tipo |
|---------|------|
| `id_detalle_carrito` | `INT PK AUTO_INCREMENT` |
| `id_carrito` | `INT` (FK → carrito) |
| `id_prod` | `INT` (FK → productos) |
| `cantidad` | `INT` |
| `precio_unitario` | `DECIMAL(12,2)` |

#### `stock` (también existe en nodos sucursales)
| Columna | Tipo |
|---------|------|
| `id_stock` | `INT PK AUTO_INCREMENT` |
| `id_prod` | `INT` (FK → productos) |
| `id_suc` | `INT` (FK → sucursales) |
| `sucursal` | `VARCHAR(100)` |
| `producto` | `VARCHAR(120)` |
| `cantidad` | `INT` |
| `stock_minimo` | `INT DEFAULT 5` |
| `actualizado_en` | `DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

#### `movimientos_stock`
| Columna | Tipo |
|---------|------|
| `id_movimiento` | `INT PK AUTO_INCREMENT` |
| `id_prod` | `INT` |
| `id_suc` | `INT` |
| `tipo` | `VARCHAR(20)` (entrada/salida/ajuste) |
| `cantidad` | `INT` |
| `motivo` | `VARCHAR(200)` |
| `fecha` | `DATETIME DEFAULT CURRENT_TIMESTAMP` |

#### `log_transacciones` (central y sucursales)
| Columna | Tipo |
|---------|------|
| `id_log` | `INT PK AUTO_INCREMENT` |
| `id_transaccion` | `VARCHAR(50)` |
| `tipo_operacion` | `ENUM('venta','compra','stock','carrito','rollback')` |
| `id_usuario` | `INT` |
| `id_cliente` | `INT` |
| `id_sucursal` | `INT` |
| `id_producto` | `INT` |
| `cantidad` | `INT` |
| `monto_total` | `DECIMAL(12,2)` |
| `estado` | `ENUM('inicio','preparada','confirmada','fallida','rollback')` |
| `nodo_origen` | `VARCHAR(50)` |
| `nodo_destino` | `VARCHAR(50)` |
| `mensaje_error` | `TEXT` |
| `datos_adicionales` | `JSON` |
| `fecha_creacion` | `DATETIME DEFAULT CURRENT_TIMESTAMP` |

Índices: `id_transaccion`, `tipo_operacion`, `estado`, `fecha_creacion`.

### Nodos Sucursales — `libremercado_sucursal1` / `libremercado_sucursal2`

Cada nodo sucursal contiene solo las tablas: `stock`, `movimientos_stock` y `log_transacciones` (versión local con ENUM `tipo_operacion` limitado a `venta/compra/stock/rollback`).

---

## 4. Procedimientos Almacenados

### Nodo Central — `procedures_central.sql` (20 SPs + 2 funciones)

#### Funciones

| Nombre | Descripción |
|--------|-------------|
| `fn_nodo_operativo(p_id_sucursal)` | Verifica si la sucursal está operativa |
| `fn_stock_disponible(p_id_prod, p_id_sucursal)` | Stock referencial (el real está en nodos sucursales) |

#### Carrito

| Procedimiento | Parámetros | Descripción |
|--------------|------------|-------------|
| `sp_carrito_activo` | `IN p_id_cli` | Obtiene o crea carrito activo, retorna info + items |
| `sp_carrito_agregar` | `IN p_id_cli, p_id_prod, p_cantidad, p_precio_unitario` | Agrega item con lock `FOR UPDATE` |
| `sp_carrito_eliminar` | `IN p_id_cli, p_id_prod` | Elimina item del carrito |
| `sp_carrito_vaciar` | `IN p_id_cli` | Vacía y cancela el carrito |

#### Ventas (2PC)

| Procedimiento | Parámetros | Descripción |
|--------------|------------|-------------|
| `sp_venta_2pc` | `IN p_id_cli, p_id_suc, p_items_json, p_id_carrito, OUT p_id_venta, p_total, p_error_msg` | Fase 1: crea venta + detalle en central, transacción abierta |
| `sp_venta_confirmar` | `IN p_id_venta, p_id_carrito` | Fase 3: confirma venta, hace COMMIT |
| `sp_venta_revertir` | `IN p_id_venta` | Rollback: cancela venta, hace COMMIT |

#### Compras (2PC)

| Procedimiento | Parámetros |
|--------------|------------|
| `sp_compra_2pc` | `IN p_id_proveedor, p_id_suc, p_id_prod, p_cantidad, p_precio_unitario, OUT p_id_compra, p_total, p_error_msg` |
| `sp_compra_confirmar` | `IN p_id_compra` |
| `sp_compra_revertir` | `IN p_id_compra` |

#### Reportes

| Procedimiento | Descripción |
|--------------|-------------|
| `sp_ventas_listar()` | Lista ventas con cliente y sucursal |
| `sp_ventas_detalle(p_id_venta)` | Detalle de una venta |
| `sp_compras_listar()` | Lista compras agrupadas |
| `sp_compras_detalle(p_id_compra)` | Detalle de una compra |

#### Logs

| Procedimiento | Descripción |
|--------------|-------------|
| `sp_logs_transacciones(p_limite, p_tipo, p_estado)` | Consulta filtrada de logs |
| `sp_logs_limpiar(p_dias_antiguedad)` | Elimina logs antiguos confirmados/rollback |

---

### Nodos Sucursales — `procedures_sucursal.sql` (13 SPs + 2 funciones)

#### Funciones

| Nombre | Descripción |
|--------|-------------|
| `fn_stock_actual(p_id_prod)` | Stock actual con lock |
| `fn_stock_suficiente(p_id_prod, p_cantidad)` | Verifica stock ≥ cantidad |

#### Stock (2PC)

| Procedimiento | Descripción |
|--------------|-------------|
| `sp_descontar_stock_venta(p_id_venta, p_id_prod, p_cantidad, p_id_suc, OUT p_exito, p_error_msg)` | Descuenta stock con `FOR UPDATE`, registra movimiento |
| `sp_reponer_stock_compra(p_id_compra, p_id_prod, p_cantidad, p_id_suc, p_motivo, OUT p_exito, p_error_msg)` | Incrementa stock, registra movimiento |
| `sp_actualizar_stock_manual(p_id_prod, p_id_suc, p_cantidad, p_motivo, OUT p_exito, p_error_msg)` | Ajuste manual, incluye COMMIT |

#### Consultas

| Procedimiento | Descripción |
|--------------|-------------|
| `sp_stock_consultar(p_id_prod)` | Stock de un producto con estado |
| `sp_stock_todos()` | Todos los stocks con alerta |
| `sp_stock_bajo()` | Productos bajo stock mínimo |
| `sp_movimientos_listar(p_id_prod, p_limite)` | Historial de movimientos |
| `sp_sync_stock(p_id_prod, p_nombre_producto)` | Sincroniza nombre desde catálogo |

#### Testing

| Procedimiento | Descripción |
|--------------|-------------|
| `sp_testear_concurrencia(p_id_prod, p_cantidad_operaciones, p_cantidad_por_operacion)` | Simula N operaciones concurrentes con tabla temporal |
| `sp_simular_recuperacion_post_caida()` | Reconoce stock vs movimientos, reporta discrepancias |

#### Logs

| Procedimiento | Descripción |
|--------------|-------------|
| `sp_logs_limpiar(p_dias_antiguedad)` | Igual que en central |

---

## 5. Capa PHP

### Estructura de Archivos

```
front/
├── includes/
│   ├── config.php              # Constantes DB, sesión multitab, detección Docker
│   ├── auth.php                # Autenticación, roles, session management
│   ├── header.php              # Navbar, node status, búsqueda
│   ├── footer.php              # Footer, JS global
│   ├── lm_database.php         # Clase LmDatabase + helpers de conexión
│   ├── lm_services.php         # 46 funciones de negocio activas
│   └── lm_stored_procedures.php # 23 wrappers PHP para SPs
├── seeder.php                  # Poblado inicial de datos (8 pasos)
├── test_concurrencia.php       # UI de test de concurrencia
├── nodos.php                   # Monitor de nodos con simulación de caídas
├── index.php                   # Dashboard admin
├── login.php / logout.php      # Autenticación
├── productos.php               # CRUD productos
├── clientes.php / cliente.php  # CRUD clientes / vista cliente
├── sucursales.php              # CRUD sucursales
├── ventas.php / compras.php    # Historial de ventas/compras
├── carrito.php                 # Carrito de compras con checkout 2PC
├── vendedor.php                # Dashboard vendedor
└── api/detalle_venta.php       # Endpoint JSON
```

### Flujo de Inclusión

```
seeder.php / test_concurrencia.php / nodos.php / index.php ...
  └── config.php
        ├── lm_database.php         (LmDatabase class + global helpers)
        ├── lm_services.php         (46 funciones de negocio)
        └── lm_stored_procedures.php (23 wrappers SP + bridges legacy)
```

### Gestión de Sesión Multitab

Cada pestaña puede usar un token diferente vía `?token=USER1`:

```php
session_name('LM_TEST_' . preg_replace('/[^a-zA-Z0-9]/', '', $sessionToken));
```

Esto permite probar concurrencia real con dos sesiones independientes.

---

## 6. Transacciones Distribuidas (2PC)

### Flujo de Venta (2PC)

```
CLIENTE                          CENTRAL                      SUCURSAL
   │                                │                            │
   │  POST /comprar                 │                            │
   │───────────────────────────────>│                            │
   │                                │                            │
   │         FASE 1: PREPARE        │                            │
   │                                ├── START TRANSACTION        │
   │                                ├── sp_venta_2pc()          │
   │                                │   ├── Validar cliente      │
   │                                │   ├── Validar sucursal     │
   │                                │   ├── INSERT ventas        │
   │                                │   ├── INSERT detalle       │
   │                                │   └── Log: 'preparada'     │
   │                                │                            │
   │         FASE 2: COMMIT SUC     │                            │
   │                                │     sp_descontar_stock()   │
   │                                │───────────────────────────>│
   │                                │     START TRANSACTION      │
   │                                │     SELECT ... FOR UPDATE  │
   │                                │     UPDATE stock           │
   │                                │     INSERT movimientos     │
   │                                │     Log: 'confirmada'      │
   │                                │< ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ │
   │                                │     COMMIT (stock node)    │
   │                                │                            │
   │         FASE 3: COMMIT CENTRAL │                            │
   │                                ├── sp_venta_confirmar()    │
   │                                │   ├── UPDATE ventas        │
   │                                │   │   SET estado='confirm'│
   │                                │   ├── Log: 'confirmada'   │
   │                                │   └── COMMIT              │
   │<───────────────────────────────│                            │
   │  Compra exitosa                │                            │
```

### Flujo de Rollback (Fallo en Stock)

```
         FASE 2: FALLA STOCK
                │
                ├── ¡Stock insuficiente!
                ├── ROLLBACK (stock node)
                │
         FASE 3: COMPENSAR
                ├── sp_venta_revertir()
                │   ├── UPDATE ventas SET estado='cancelada'
                │   ├── Log: 'rollback'
                │   └── COMMIT
                │
                └── Error: "Stock insuficiente"
```

### Implementación en PHP

Las transacciones 2PC se implementan en `lm_stored_procedures.php`:

**`sp_registrar_venta_2pc()`** (líneas 110–205):
```php
// FASE 1: Central (SP maneja su propia transacción)
$stmt = $pdoCentral->prepare('CALL sp_venta_2pc(?, ?, ?, ?, @id, @tot, @err)');
$stmt->execute([...]);

// FASE 2: Sucursal (PHP maneja la transacción)
$pdoStock->beginTransaction();
foreach ($items as $item) {
    $stmt = $pdoStock->prepare('CALL sp_descontar_stock_venta(..., @exito, @err)');
    $stmt->execute([...]);
    if (!$exito) throw new RuntimeException($err);
}
$pdoStock->commit(); // Confirma descuento de stock

// FASE 3: Confirmar central (SP hace COMMIT)
$stmt = $pdoCentral->prepare('CALL sp_venta_confirmar(?, ?)');
$stmt->execute([$idVenta, $idCarrito]);

// Si algo falla en FASE 2:
//   → $pdoStock->rollBack()
//   → CALL sp_venta_revertir($idVenta)  // compensa
```

### Mecanismo de Locking

Cada SP que accede a datos críticos usa `SELECT ... FOR UPDATE` para obtener locks exclusivos de fila:

```sql
-- En sp_descontar_stock_venta (sucursal):
SELECT id_stock, cantidad INTO v_id_stock, v_stock_anterior
FROM stock
WHERE id_prod = p_id_prod AND id_suc = p_id_suc
FOR UPDATE;

-- En sp_venta_2pc (central):
SELECT id_cli INTO v_cliente_valido
FROM clientes
WHERE id_cli = p_id_cli AND activo = 1
FOR UPDATE;
```

---

## 7. Concurrencia y Locking

### Estrategia

1. **Locks explícitos de fila** con `SELECT ... FOR UPDATE` en todos los SPs críticos.
2. **2PC con transacciones abiertas**: `sp_venta_2pc` deja la transacción abierta hasta que `sp_venta_confirmar` hace COMMIT (o `sp_venta_revertir` compensa).
3. **Timeouts de lock**: MySQL maneja deadlocks automáticamente.

### Test de Concurrencia

El archivo `test_concurrencia.php` permite:
- Abrir 2 pestañas con `?token=USER1` y `?token=USER2`
- Comprar el mismo producto simultáneamente
- Verificar que solo una transacción completa y la otra falla con "Stock insuficiente"
- Ver logs detallados en la tabla `log_transacciones`

El SP `sp_testear_concurrencia()` en sucursales ejecuta N operaciones secuenciales con tabla temporal para medir tiempos y conflictos.

### Simulación de Recuperación

`sp_simular_recuperacion_post_caida()` reconcilia stock contra movimientos, reporta discrepancias y registra recuperación en logs.

---

## 8. Tolerancia a Particiones (CP)

### Simulación de Caída de Nodos

El monitor `nodos.php` permite marcar un nodo como "caído" mediante archivos temporales:

```php
// /tmp/lm_node_down_<node_key>
LmDatabase::simulateNodeDown('sucursal1'); // Cae nodo
LmDatabase::simulateNodeDown('sucursal1', false); // Restaura
```

### Comportamiento CP

Cuando un nodo está caído:

```php
// En sp_registrar_venta_2pc():
if (LmDatabase::isSimulatedDown('central') || LmDatabase::isSimulatedDown($stockNode)) {
    throw new RuntimeException("Nodo no disponible. Operación cancelada (CP).");
}
```

- **Venta**: Si la sucursal destino o el central están caídos → operación rechazada.
- **Compra**: Igual, ambos nodos deben estar online.
- **Stock**: Las consultas omiten nodos caídos (mejor esfuerzo, solo lectura).
- **Productos/Clientes/Usuarios**: Operan solo contra central (alta disponibilidad).

### Consistencia de Lectura

`lm_sucursal_operativa()` verifica que el nodo responda antes de operar:

```php
function lm_sucursal_operativa(int $idSuc): bool {
    $node = LmDatabase::stockNodeForSucursal($idSuc);
    if (!LmDatabase::ping($node)) return false;
    return in_array($idSuc, [2, 3], true);
}
```

---

## 9. Seeder: Poblado de Datos

`seeder.php` ejecuta 8 pasos en orden:

| Paso | Acción |
|------|--------|
| 1 | Crear esquemas en los 3 nodos (13 tablas central, 2 por sucursal) |
| 2 | Instalar procedimientos almacenados vía `lm_install_routines_from_file()` |
| 3 | Truncar datos existentes (`FOREIGN_KEY_CHECKS = 0`) |
| 4 | Insertar 2 sucursales + 3 proveedores |
| 5 | Insertar 10 productos en catálogo |
| 6 | Crear 5 usuarios: admin, vendedor, 3 clientes |
| 7 | Distribuir stock: 10–50 unidades por producto por sucursal |
| 8 | Mostrar resumen exitoso |

### Instalación de SPs

La función `lm_install_routines_from_file()` (en `lm_database.php`) es un parser de SQL que:
1. Lee el archivo `.sql` línea por línea
2. Ignora comentarios y líneas `DELIMITER`
3. Extrae cada `CREATE PROCEDURE` / `CREATE FUNCTION` como sentencia individual
4. Ejecuta cada una vía `PDO::exec()`

Esto evita la necesidad del cliente `mysql` CLI dentro del contenedor PHP.

### Datos de Prueba

| Tipo | Cantidad | Detalle |
|------|----------|---------|
| Productos | 10 | Smartphone S23, Laptop Dell, Audífonos Sony, Monitor LG, Teclado Keychron, Mouse MX Master, iPad Air, Apple Watch, Cámara Canon, SSD Crucial |
| Usuarios | 5 | admin@demo.local / admin123, vendedor@demo.local / vendedor123, 3 clientes |
| Sucursales | 2 | La Serena (id_suc=2), Coquimbo (id_suc=3) |
| Proveedores | 3 | TecnoGlobal S.A., Distribuidora El Faro, Importaciones Asia |

---

## 10. Módulo de Testing

### test_concurrencia.php

Interfaz unificada para probar la capa de procedimientos almacenados:

| Acción | SP llamado | Propósito |
|--------|-----------|-----------|
| `comprar` | `sp_registrar_venta_2pc()` | Compra simple con 2PC |
| `test_concurrencia` | `sp_testear_concurrencia()` | Test de N operaciones concurrentes |
| `recuperar` | `sp_simular_recuperacion_post_caida()` | Reconciliación stock vs movimientos |
| `limpiar_logs` | `sp_logs_limpiar()` | Limpieza de logs antiguos |

Características:
- Sesión por token para 2 usuarios simultáneos
- Tabla de stock en vivo por sucursal
- Visor de logs de transacciones (auto-refresh)

### nodos.php

Monitor de estado de nodos con controles para simular caídas:

```
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│  NODO CENTRAL   │  │  SUCURSAL       │  │  SUCURSAL       │
│                 │  │  LA SERENA      │  │  COQUIMBO       │
│    ● ONLINE     │  │    ● ONLINE     │  │    ● ONLINE     │
│  [Simular Caída] │  │  [Simular Caída]│  │  [Simular Caída]│
└─────────────────┘  └─────────────────┘  └─────────────────┘
```

---

## 11. Guía de Despliegue

### Requisitos

- Docker Engine 24+ / Docker Compose v2
- Puerto `8080`, `3307`, `3308`, `3309` libres

### Inicio Rápido

```bash
# Construir e iniciar todos los servicios
docker compose up -d --wait

# Verificar estado
docker compose ps

# Ejecutar seeder (poblado de datos)
curl http://localhost:8080/seeder.php

# Acceder a la aplicación
open http://localhost:8080/login.php
```

### Credenciales por Defecto

| Rol | Email | Password |
|-----|-------|----------|
| Admin | admin@demo.local | admin123 |
| Vendedor | vendedor@demo.local | vendedor123 |
| Cliente | juan.perez@email.com | cliente123 |

### Verificación

```bash
# Ver SPs instalados
docker exec libre_mercado-mysql-central-1 mysql -u root -proot \
  -e "SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='libremercado_central' AND ROUTINE_TYPE='PROCEDURE'"

# Ver stock
docker exec libre_mercado-mysql-sucursal1-1 mysql -u root -proot \
  libremercado_sucursal1 -e "SELECT COUNT(*) FROM stock"

# Test 2PC venta
curl -X POST http://localhost:8080/test_concurrencia.php?token=TEST1 \
  -d "accion=comprar&id_cli=1&id_suc=2&id_prod=1&cantidad=2"
```

### Comandos Útiles

```bash
# Reset completo
docker compose down -v && docker compose up -d --wait

# Logs de un servicio
docker compose logs -f mysql-central

# Acceder a MySQL
docker exec -it libre_mercado-mysql-central-1 mysql -u root -proot libremercado_central

# Re-ejecutar seeder
curl http://localhost:8080/seeder.php
```

---

## 12. API de Funciones PHP

### LmDatabase (estático)

| Método | Retorno | Descripción |
|--------|---------|-------------|
| `nodes()` | `array` | Configuración de los 3 nodos |
| `nodeAliases()` | `array` | Alias → nombre canónico |
| `stockNodeForSucursal(idSuc)` | `string` | id_suc → nombre de nodo |
| `sucursalIdForNode(node)` | `int` | nombre de nodo → id_suc |
| `isSimulatedDown(node)` | `bool` | ¿Nodo simulado como caído? |
| `simulateNodeDown(node, down?)` | `void` | Simular caída/restauración |
| `clearNodeSimulation(node?)` | `void` | Limpiar simulación |
| `connection(node)` | `?PDO` | Obtener conexión PDO |
| `ping(node)` | `bool` | Verificar conectividad |
| `canonicalNode(node)` | `?string` | Resolver alias |

### Funciones Globales (lm_database.php)

| Función | Retorno | Descripción |
|---------|---------|-------------|
| `lm_pdo(node)` | `PDO` | PDO o excepción |
| `conectarNodo(nodo)` | `?PDO` | Alias de connection() |
| `estadoNodos()` | `array` | Estado online/offline de todos |
| `lm_simular_caida_nodo(nodo, caida?)` | `void` | Simular caída |
| `lm_restaurar_nodo(nodo)` | `void` | Restaurar nodo |
| `lm_restaurar_todos_los_nodos()` | `void` | Restaurar todos |
| `lm_install_routines_from_file(pdo, path)` | `array` | Instalar SPs desde archivo SQL |

### Funciones de Negocio (lm_services.php)

#### Productos
| Función | Descripción |
|---------|-------------|
| `lm_catalogo_productos(soloActivos?)` | Listar productos |
| `lm_producto_por_id(idProd)` | Producto por ID |
| `lm_guardar_producto(data)` | Crear/actualizar producto (distribuido) |
| `lm_desactivar_producto(idProd)` | Soft-delete producto |

#### Clientes
| Función | Descripción |
|---------|-------------|
| `lm_clientes_listar(soloActivos?)` | Listar clientes |
| `lm_cliente_por_id(idCli)` | Cliente por ID |
| `lm_guardar_cliente(data)` | Crear/actualizar cliente + usuario |
| `lm_desactivar_cliente(idCli)` | Soft-delete |

#### Usuarios
| Función | Descripción |
|---------|-------------|
| `lm_usuarios_listar(soloActivos?)` | Listar usuarios |
| `lm_usuario_por_email(email)` | Usuario por email |
| `lm_guardar_usuario(data, pdo?)` | Crear/actualizar usuario |
| `lm_desactivar_usuario(idUsuario)` | Soft-delete |

#### Sucursales
| Función | Descripción |
|---------|-------------|
| `lm_sucursales_listar(soloActivas?)` | Listar sucursales |
| `lm_sucursales_operativas()` | Solo las operativas (CP) |
| `lm_sucursal_por_id(idSuc)` | Sucursal por ID |
| `lm_guardar_sucursal(data)` | Crear/actualizar |
| `lm_desactivar_sucursal(idSuc)` | Soft-delete |

#### Proveedores
| Función | Descripción |
|---------|-------------|
| `lm_proveedores_listar(soloActivos?)` | Listar proveedores |
| `lm_proveedor_por_id(idProveedor)` | Proveedor por ID |
| `lm_guardar_proveedor(data)` | Crear/actualizar |
| `lm_desactivar_proveedor(idProveedor)` | Soft-delete |

#### Stock
| Función | Descripción |
|---------|-------------|
| `lm_stock_todos()` | Stock consolidado de ambas sucursales |
| `lm_stock_por_nodo(node)` | Stock de un nodo |
| `lm_stock_actualizar(idSuc, idProd, cantidad, motivo?)` | Actualizar stock con lock |

#### Ventas / Compras
| Función | Descripción |
|---------|-------------|
| `lm_ventas_listar()` | Historial de ventas |
| `lm_venta_por_id(idVenta)` | Venta individual |
| `lm_venta_detalle(idVenta)` | Detalle de venta |
| `lm_ventas_listar_por_cliente(idCli)` | Ventas de un cliente |
| `lm_compras_listar()` | Historial de compras |
| `lm_compra_detalle(idCompra)` | Detalle de compra |

#### Dashboard
| Función | Descripción |
|---------|-------------|
| `lm_dashboard_stats()` | Estadísticas generales |
| `lm_dashboard_ventas_recientes(limite?)` | Últimas ventas |
| `lm_dashboard_stock_bajo()` | Productos con bajo stock |

### Wrappers de Stored Procedures (lm_stored_procedures.php)

| Función | SP subyacente | Descripción |
|---------|---------------|-------------|
| `sp_carrito_activo(idCli)` | `sp_carrito_activo` | Carrito activo con items |
| `sp_carrito_agregar(idCli, idProd, cant, precio)` | `sp_carrito_agregar` | Agregar al carrito |
| `sp_carrito_eliminar(idCli, idProd)` | `sp_carrito_eliminar` | Quitar del carrito |
| `sp_carrito_vaciar(idCli)` | `sp_carrito_vaciar` | Vaciar carrito |
| `sp_registrar_venta_2pc(idCli, idSuc, items, idCarrito?)` | `sp_venta_2pc` + `sp_descontar_stock_venta` + `sp_venta_confirmar` | Venta 2PC completa |
| `sp_registrar_compra_2pc(idProveedor, idSuc, idProd, cant, precio)` | `sp_compra_2pc` + `sp_reponer_stock_compra` + `sp_compra_confirmar` | Compra 2PC completa |
| `sp_stock_actualizar(idProd, idSuc, cant, motivo?)` | `sp_actualizar_stock_manual` | Ajuste manual de stock |
| `sp_stock_consultar(idProd?)` | `sp_stock_todos` | Consultar stock global |
| `sp_stock_bajo()` | `sp_stock_bajo` | Alertas de stock bajo |
| `sp_ventas_listar()` | `sp_ventas_listar` | Listar ventas |
| `sp_ventas_detalle(idVenta)` | `sp_ventas_detalle` | Detalle de venta |
| `sp_compras_listar()` | `sp_compras_listar` | Listar compras |
| `sp_compras_detalle(idCompra)` | `sp_compras_detalle` | Detalle de compra |
| `sp_logs_transacciones(limite, tipo?, estado?)` | `sp_logs_transacciones` | Consultar logs |
| `sp_logs_limpiar(dias?)` | `sp_logs_limpiar` | Limpiar logs antiguos |
| `sp_testear_concurrencia(idProd, ops, cant)` | `sp_testear_concurrencia` | Test de concurrencia |
| `sp_simular_recuperacion_post_caida()` | `sp_simular_recuperacion_post_caida` | Recuperación post caída |

### Bridges de Compatibilidad

| Función Legacy | Delega a |
|----------------|----------|
| `lm_registrar_venta(...)` | `sp_registrar_venta_2pc()` |
| `lm_registrar_compra(...)` | `sp_registrar_compra_2pc()` |
| `lm_carrito_activo(...)` | `sp_carrito_activo()` |
| `lm_carrito_agregar_item(...)` | `sp_carrito_agregar()` |
| `lm_carrito_eliminar_item(...)` | `sp_carrito_eliminar()` |
| `lm_carrito_vaciar(...)` | `sp_carrito_vaciar()` |

---

## Apéndice A: Archivos de Configuración

### Variables de Entorno (config.php)

```php
// Hosts de base de datos (nombres de contenedor Docker)
define('LM_DB_HOST_CENTRAL', 'mysql-central');
define('LM_DB_HOST_SUCURSAL1', 'mysql-sucursal1');
define('LM_DB_HOST_SUCURSAL2', 'mysql-sucursal2');

// Puertos dentro de la red Docker
define('LM_DB_PORT_CENTRAL', '3306');
define('LM_DB_PORT_SUCURSAL1', '3306');
define('LM_DB_PORT_SUCURSAL2', '3306');

// Nombres de bases de datos
define('LM_DB_NAME_CENTRAL', 'libremercado_central');
define('LM_DB_NAME_SUCURSAL1', 'libremercado_sucursal1');
define('LM_DB_NAME_SUCURSAL2', 'libremercado_sucursal2');

// Credenciales
define('LM_DB_USER', 'root');
define('LM_DB_PASS', 'root');
```

### Rutas de Archivos SQL

```php
$spCentralFile  = '/docker/mysql/procedures_central.sql';   // 930 líneas
$spSucursalFile = '/docker/mysql/procedures_sucursal.sql';  // 769 líneas
```

---

## Apéndice B: Comandos de Verificación Rápida

```bash
# 1. Ver contenedores activos
docker ps

# 2. Ver SPs instalados en central
docker exec libre_mercado-mysql-central-1 mysql -u root -proot \
  -e "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='libremercado_central' AND ROUTINE_TYPE='PROCEDURE'"

# 3. Ver SPs en sucursales
for n in sucursal1 sucursal2; do
  docker exec libre_mercado-mysql-$n-1 mysql -u root -proot \
    -e "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='libremercado_$n' AND ROUTINE_TYPE='PROCEDURE'"
done

# 4. Ver productos y stock
docker exec libre_mercado-mysql-central-1 mysql -u root -proot \
  libremercado_central -e "SELECT COUNT(*) AS productos FROM productos"
docker exec libre_mercado-mysql-sucursal1-1 mysql -u root -proot \
  libremercado_sucursal1 -e "SELECT SUM(cantidad) AS stock_total FROM stock"

# 5. Probar venta 2PC
curl -X POST "http://localhost:8080/test_concurrencia.php?token=TEST1" \
  -d "accion=comprar&id_cli=1&id_suc=2&id_prod=1&cantidad=1"

# 6. Ver logs
docker exec libre_mercado-mysql-central-1 mysql -u root -proot \
  libremercado_central -e "SELECT id_transaccion, tipo_operacion, estado, monto_total FROM log_transacciones ORDER BY id_log DESC LIMIT 5"
```

---

Documentación generada el 23 de junio de 2026.
Proyecto: Libre Mercado — Sistemas Distribuidos.
