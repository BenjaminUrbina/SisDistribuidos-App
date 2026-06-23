# Documentación Técnica — Libre Mercado

Sistema distribuido de comercio electrónico con arquitectura **CP** (Consistency + Partition Tolerance), transacciones distribuidas **2PC** y almacenamiento particionado en 3 nodos MySQL. Desarrollado para la asignatura de Sistemas Distribuidos.

---

## Índice

1. [Arquitectura](#1-arquitectura)
2. [Modelo Distribuido](#2-modelo-distribuido)
3. [CAP Elegido](#3-cap-elegido)
4. [Manejo de Fallos](#4-manejo-de-fallos)
5. [Conclusiones](#5-conclusiones)

---

## 1. Arquitectura

### 1.1 Topología General

El sistema se compone de **tres nodos MySQL 8** independientes y un frontal **PHP 8.2 + Apache**, todos orquestados con Docker Compose:

```
┌─────────────────────────────────────────┐
│            FRONTAL WEB                   │
│     php:8.2-apache + pdo_mysql          │
│     Puerto 8080 → 80                    │
│     Volumen: ./front:/var/www/html      │
└─────────────────────────────────────────┘
                    │
    ┌───────────────┼───────────────┐
    ▼               ▼               ▼
┌──────────┐  ┌──────────┐  ┌──────────┐
│ CENTRAL  │  │SUCURSAL 1│  │SUCURSAL 2│
│ mysql:8  │  │ mysql:8  │  │ mysql:8  │
│ 3307→3306│  │ 3308→3306│  │ 3309→3306│
│          │  │          │  │          │
│ root:root│  │ root:root│  │ root:root│
└──────────┘  └──────────┘  └──────────┘
```

### 1.2 Componentes del Sistema

| Componente | Tecnología | Propósito |
|------------|-----------|-----------|
| Servidor web | Apache 2.4 + PHP 8.2 | Interfaz HTTP, lógica de negocio, conexión a bases de datos |
| Nodo central | MySQL 8 | Catálogo, clientes, usuarios, ventas, compras, carrito, logs |
| Nodo sucursal 1 | MySQL 8 | Stock y movimientos de Sucursal La Serena (id_suc=2) |
| Nodo sucursal 2 | MySQL 8 | Stock y movimientos de Sucursal Coquimbo (id_suc=3) |
| Orquestación | Docker Compose | Despliegue, redes, volúmenes persistentes |

### 1.3 Conexiones entre Componentes

El frontal PHP se conecta a los tres nodos MySQL mediante **PDO**. Cada nodo tiene credenciales y constantes definidas en `config.php`:

```
PHP → mysql-central:3306  (tablas maestras)
PHP → mysql-sucursal1:3306 (stock La Serena)
PHP → mysql-sucursal2:3306 (stock Coquimbo)
```

Las conexiones se resuelven por nombre de contenedor Docker (`mysql-central`, `mysql-sucursal1`, `mysql-sucursal2`), gracias a la red interna de Docker Compose.

### 1.4 Sesión Multitab

El sistema implementa sesiones independientes por pestaña mediante un token en la URL:

```
GET /test_concurrencia.php?token=USER1  → Sesión USER1
GET /test_concurrencia.php?token=USER2  → Sesión USER2
```

Cada sesión tiene su propio `session_name()`, permitiendo probar concurrencia real con dos usuarios simultáneos desde un mismo navegador.

---

## 2. Modelo Distribuido

### 2.1 Particionamiento de Datos

Los datos se distribuyen entre los nodos según su función:

**Nodo Central** — Base de datos maestra con 13 tablas:

| Tabla | Contenido |
|-------|-----------|
| `productos` | Catálogo de productos con precio y descripción |
| `clientes` | Datos de clientes (RUT, email, teléfono, dirección) |
| `usuarios` | Cuentas de usuario con roles (admin, vendedor, cliente) |
| `sucursales` | Catálogo de sucursales |
| `proveedores` | Catálogo de proveedores |
| `ventas` | Cabecera de ventas (cliente, sucursal, total, estado) |
| `detalle_ventas` | Líneas de detalle de cada venta |
| `compras` | Cabecera de compras a proveedores |
| `detalle_compras` | Líneas de detalle de cada compra |
| `carrito` | Carritos de compra activos/pagados/cancelados |
| `detalle_carrito` | Items en cada carrito |
| `stock` | Stock consolidado (referencial) |
| `movimientos_stock` | Movimientos de stock (referencial) |
| `log_transacciones` | Auditoría de operaciones distribuidas |

**Cada Nodo Sucursal** — Datos locales de stock:

| Tabla | Contenido |
|-------|-----------|
| `stock` | Stock real por producto (id_prod, id_suc, cantidad) |
| `movimientos_stock` | Historial de entrada/salida/ajuste |
| `log_transacciones` | Auditoría local de operaciones |

### 2.2 Mapeo Sucursal → Nodo

| id_suc | Sucursal | Nodo |
|--------|----------|------|
| 1 | Central | `central` |
| 2 | La Serena | `sucursal1` |
| 3 | Coquimbo | `sucursal2` |

El mapeo se realiza mediante la clase `LmDatabase`:

```php
LmDatabase::stockNodeForSucursal(2);  // → 'sucursal1'
LmDatabase::sucursalIdForNode('sucursal2');  // → 3
```

### 2.3 Transacciones Distribuidas (2PC)

Las operaciones de venta y compra involucran dos nodos (central + una sucursal). Se implementa un protocolo **Two-Phase Commit** (2PC) para garantizar atomicidad.

#### Flujo de Venta

```
FASE 1 — PREPARE (Central)
─────────────────────────────────────────────────
PHP llama a sp_venta_2pc()
  ├── START TRANSACTION
  ├── Validar cliente con FOR UPDATE
  ├── Validar sucursal con FOR UPDATE
  ├── INSERT INTO ventas (estado='pendiente')
  ├── INSERT INTO detalle_ventas (por cada item)
  ├── Validar cada producto con FOR UPDATE
  ├── UPDATE ventas SET estado='preparada'
  ├── Log: estado='preparada'
  └── Transacción queda ABIERTA

FASE 2 — COMMIT/ROLLBACK (Sucursal)
─────────────────────────────────────────────────
PHP inicia su propia transacción en nodo sucursal
  Por cada item:
    sp_descontar_stock_venta()
      ├── START TRANSACTION
      ├── SELECT ... FOR UPDATE sobre stock
      ├── Verificar stock suficiente
      ├── UPDATE stock (descontar)
      ├── INSERT INTO movimientos_stock
      └── Transacción queda ABIERTA

  Si TODO ok → PHP hace COMMIT en sucursal
  Si FALLA  → PHP hace ROLLBACK en sucursal
              → sp_venta_revertir() compensa en central

FASE 3 — COMMIT (Central)
─────────────────────────────────────────────────
PHP llama a sp_venta_confirmar()
  ├── UPDATE ventas SET estado='confirmada'
  ├── UPDATE carrito SET estado='pagado'
  ├── Log: estado='confirmada'
  └── COMMIT (cierra transacción de Fase 1)
```

#### Flujo de Compra

Simétrico al de venta, pero con `sp_compra_2pc()` en central y `sp_reponer_stock_compra()` en sucursal. La reposición de stock usa `UPDATE stock SET cantidad = cantidad + p_cantidad`.

#### Rollback por Falla

```
FASE 2 falla (stock insuficiente)
  └── ROLLBACK en sucursal (libera locks)
  └── sp_venta_revertir($idVenta)
        ├── UPDATE ventas SET estado='cancelada'
        ├── Log: tipo='rollback', estado='rollback'
        └── COMMIT (libera locks en central)
```

### 2.4 Mecanismo de Locking

Todas las operaciones críticas usan **`SELECT ... FOR UPDATE`** para obtener locks exclusivos de fila:

```sql
-- Bloquea el registro de stock mientras se procesa la venta
SELECT id_stock, cantidad INTO v_id_stock, v_stock_anterior
FROM stock
WHERE id_prod = p_id_prod AND id_suc = p_id_suc
FOR UPDATE;
```

Esto garantiza que dos transacciones concurrentes sobre el mismo producto se serialicen en el motor de base de datos, evitando condiciones de carrera.

### 2.5 Registro de Auditoría

Cada operación distribuida genera entradas en `log_transacciones` con trazabilidad completa:

| Campo | Ejemplo |
|-------|---------|
| `id_transaccion` | `VENTA-1782251875-816313` |
| `tipo_operacion` | `venta`, `compra`, `stock`, `carrito`, `rollback` |
| `estado` | `inicio` → `preparada` → `confirmada` / `fallida` / `rollback` |
| `nodo_origen` | `central`, `sucursal` |
| `datos_adicionales` | `{"id_venta": 1, "items_count": 2}` |
| `mensaje_error` | `Stock insuficiente. Actual: 5, Requerido: 10` |

---

## 3. CAP Elegido

### 3.1 Decisión: CP (Consistency + Partition Tolerance)

El sistema opta por **CP** según el teorema de Brewer:

```
           CONSISTENCIA
               │
               │
               ▼
    ┌─────────────────────┐
    │  LIBRE MERCADO      │
    │  ● Consistencia     │
    │  ● Tolerancia a     │
    │    particiones      │
    │  ✗ Disponibilidad   │
    │    (sacrificada)    │
    └─────────────────────┘
               ▲
               │
               │
     TOLERANCIA A PARTICIÓN
```

### 3.2 Justificación

El dominio del problema (comercio electrónico con stock real) exige que:
- **No se puedan vender productos sin stock**: una venta debe reflejar el stock real siempre.
- **No se puedan sobregirar existencias**: dos compradores simultáneos del mismo producto deben serializarse correctamente.
- **Un nodo caído no debe generar datos inconsistentes**: si una sucursal no responde, la operación se rechaza antes de comenzar.

### 3.3 Implementación de Consistencia

1. **Locks explícitos**: `SELECT ... FOR UPDATE` en todos los accesos a stock.
2. **Transacciones 2PC**: las ventas y compras cruzan dos nodos con atomicidad garantizada.
3. **Validación previa**: antes de descontar stock se verifica disponibilidad dentro de la misma transacción.
4. **Rollback automático**: si la fase 2 falla, la fase 1 se revierte con `sp_venta_revertir()`.

### 3.4 Implementación de Tolerancia a Partición

```php
// Verificar disponibilidad antes de operar (CP)
if (LmDatabase::isSimulatedDown('central') || LmDatabase::isSimulatedDown($stockNode)) {
    throw new RuntimeException("Nodo no disponible. Operación cancelada (CP).");
}
```

El monitoreo de nodos se implementa con archivos temporales:

```php
$file = sys_get_temp_dir() . "/lm_node_down_{$key}";
file_exists($file);  // true = nodo simulado como caído
```

### 3.5 Disponibilidad Sacrificada

| Escenario | Comportamiento |
|-----------|---------------|
| Sucursal 1 caída | Ventas en La Serena rechazadas; Coquimbo funciona normal |
| Nodo central caído | Toda venta/compra rechazada; consultas de solo lectura pueden funcionar |
| Sucursal 2 caída | Reposición de stock en Coquimbo imposible; La Serena funciona normal |

---

## 4. Manejo de Fallos

### 4.1 Fallo en Transacción Distribuida (2PC)

| Fase donde falla | Comportamiento |
|-----------------|---------------|
| Fase 1 (Central) | `sp_venta_2pc` lanza SIGNAL SQLSTATE; EXIT HANDLER hace ROLLBACK; no hay efectos secundarios |
| Fase 2 (Sucursal) — stock insuficiente | `sp_descontar_stock_venta` lanza SIGNAL; PHP captura la excepción, hace ROLLBACK en sucursal y llama a `sp_venta_revertir()` en central |
| Fase 2 — error de conexión | PHP captura `Throwable`, rollback en sucursal, compensación en central |
| Fase 3 — error en confirmación | `sp_venta_confirmar` tiene su propio EXIT HANDLER con ROLLBACK |

### 4.2 Fallo de Nodo (Partición de Red)

El sistema detecta nodos caídos mediante `LmDatabase::ping()` y `LmDatabase::isSimulatedDown()`:

| Función | Mecanismo |
|---------|-----------|
| `ping(node)` | `SELECT 1` vía PDO; retorna false si hay excepción |
| `isSimulatedDown(node)` | Verifica archivo `/tmp/lm_node_down_<node>` |
| `simulateNodeDown(node, bool)` | Crea/elimina el archivo de flag |

El monitor de nodos (`nodos.php`) permite:
- Ver estado en tiempo real de los 3 nodos
- Simular caída de cualquier nodo
- Restaurar nodos individual o masivamente
- Explicación visual del teorema CAP

### 4.3 Caída de Nodo y Mecanismo de Defensa

```
Nodo Sucursal 1 cae
        │
        ▼
┌──────────────────────────────┐
│  Verificación en cada        │
│  operación:                  │
│  lm_sucursal_operativa(2)    │
│  └─ LmDatabase::ping()       │
│     └─ SELECT 1 → EXCEPCIÓN  │
│        → return false         │
└──────────────────────────────┘
        │
        ▼
┌──────────────────────────────┐
│  sp_registrar_venta_2pc():   │
│  if (isSimulatedDown(suc1))  │
│    throw RuntimeException    │
│    "Nodo no disponible"      │
└──────────────────────────────┘
        │
        ▼
┌──────────────────────────────┐
│  frontend: alerta de error   │
│  "❌ Error: Nodo no          │
│   disponible. Operación      │
│   cancelada (CP)."           │
└──────────────────────────────┘
```

### 4.4 Recuperación Post-Caída

El procedimiento `sp_simular_recuperacion_post_caida()` en cada nodo sucursal:

1. Crea tabla temporal `tmp_reconciliacion`
2. Reconoce stock actual vs. stock calculado desde movimientos
3. Reporta discrepancias
4. Cuenta movimientos pendientes
5. Registra evento de recuperación en `log_transacciones`

```sql
INSERT INTO tmp_reconciliacion
SELECT s.id_prod,
       s.cantidad AS stock_actual,
       SUM(CASE WHEN m.tipo = 'entrada' THEN m.cantidad ELSE -m.cantidad END) AS stock_calculado,
       s.cantidad - SUM(...) AS diferencia,
       COUNT(*) AS movimientos_revisados
FROM stock s
LEFT JOIN movimientos_stock m ON m.id_prod = s.id_prod
GROUP BY s.id_prod, s.cantidad;
```

### 4.5 Manejo de Errores en Capa PHP

Toda la capa de servicios PHP utiliza `try/catch` con `Throwable`:

```php
try {
    $pdoStock->beginTransaction();
    // ... operaciones ...
    $pdoStock->commit();
} catch (Throwable $e) {
    if ($pdoStock->inTransaction()) {
        $pdoStock->rollBack();
    }
    // Compensar en central
    $stmt = $pdoCentral->prepare('CALL sp_venta_revertir(?)');
    $stmt->execute([$idVenta]);
    throw $e;  // Propagar al frontend
}
```

### 4.6 Consistencia en Lectura

`lm_sucursal_operativa()` verifica que el nodo responda antes de operar:

```php
function lm_sucursal_operativa(int $idSuc): bool {
    $node = LmDatabase::stockNodeForSucursal($idSuc);
    if (!LmDatabase::ping($node)) return false;
    return in_array($idSuc, [2, 3], true);
}
```

---

## 5. Conclusiones

### 5.1 Logros Alcanzados

1. **Arquitectura distribuida funcional**: tres nodos MySQL independientes con datos particionados por dominio (central → maestro, sucursales → stock local).

2. **Transacciones 2PC operativas**: el protocolo de dos fases para ventas y compras funciona correctamente, incluyendo:
   - Commit exitoso cuando ambos nodos están disponibles y hay stock suficiente.
   - Rollback automático con compensación cuando el stock es insuficiente.
   - Rollback con compensación cuando un nodo falla durante la operación.

3. **Concurrencia controlada**: los locks `SELECT ... FOR UPDATE` en procedimientos almacenados serializan correctamente las operaciones concurrentes, evitando condiciones de carrera.

4. **Electiva CP implementada**: el sistema rechaza operaciones cuando un nodo está caído, priorizando consistencia sobre disponibilidad. El monitor de nodos permite simular y verificar este comportamiento.

5. **Auditoría completa**: todas las operaciones distribuidas quedan registradas en `log_transacciones` con trazabilidad de principio a fin (inicio → preparación → confirmación/fallo).

6. **Mecanismo de recuperación**: el sistema puede reconciliar stock contra movimientos después de una caída, detectando discrepancias.

7. **20 procedimientos almacenados en central + 13 por sucursal**, todos con manejo de errores, transacciones y locks explícitos.

### 5.2 Limitaciones

| Aspecto | Limitación | Mejora Posible |
|---------|-----------|----------------|
| Disponibilidad | Operaciones rechazadas si un nodo falla | Implementar réplicas asíncronas o caché local |
| Escalabilidad | 3 nodos fijos | Agregar más sucursales requiriendo nuevos contenedores MySQL |
| 2PC | Bloquea recursos durante la transacción | Implementar Saga Pattern o compensaciones asíncronas |
| Consistencia de lectura | Stock referencial en central puede diferir | Implementar lectura desde nodo sucursal siempre |
| Alta disponibilidad | Un solo nodo central | Replicación maestro-maestro o cluster InnoDB |

### 5.3 Verificación Experimental

El sistema se verificó con las siguientes pruebas:

| Prueba | Resultado | Evidencia |
|--------|-----------|-----------|
| Venta 2PC exitosa | ✅ Venta #1 creada, stock descontado 17→15, log confirmada | `test_concurrencia.php` |
| Venta con stock insuficiente | ❌ Error "Stock insuficiente", venta cancelada, stock intacto (15), log rollback | `test_concurrencia.php` |
| Nodo simulado caído | ❌ Error "Nodo no disponible. Operación cancelada (CP)" | `nodos.php` |
| Instalación de SPs | ✅ 20 SPs central + 13 SPs sucursal1 + 13 SPs sucursal2 | `seeder.php` |
| Seeder completo | ✅ 10 productos, 5 usuarios, 3 clientes, 20 registros stock/sucursal | `seeder.php` |

### 5.4 Resumen

Libre Mercado demuestra un sistema distribuido CP funcional con:

- **3 nodos MySQL** con datos particionados por dominio
- **Protocolo 2PC** para transacciones atómicas entre nodos
- **Locks pesimistas** (`FOR UPDATE`) para concurrencia
- **Tolerancia a particiones** con rechazo de operaciones
- **Auditoría completa** de todas las operaciones distribuidas
- **Recuperación post-caída** con reconciliación de datos

El sistema cumple con los requisitos de un sistema distribuido real: consistencia fuerte, tolerancia a fallos de red y atomicidad en operaciones multi-nodo, sacrificando disponibilidad en escenarios de partición (arquitectura CP).

---

Documentación generada el 23 de junio de 2026.
Proyecto: Libre Mercado — Sistemas Distribuidos (5to año).
