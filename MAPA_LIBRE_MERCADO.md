# Libre Mercado: Mapa Del Proyecto

## Nodos

```text
                    +----------------------+
                    |  Nodo Central        |
                    |  libremercado_central|
                    +----------+-----------+
                               |
             +-----------------+-----------------+
             |                                   |
+------------v------------+         +-----------v------------+
| Sucursal La Serena      |         | Sucursal Coquimbo      |
| libremercado_sucursal1  |         | libremercado_sucursal2 |
| stock + movimientos     |         | stock + movimientos    |
+-------------------------+         +------------------------+
```

## Tablas Por Nodo

- Central: `productos`, `clientes`, `usuarios`, `ventas`, `detalle_ventas`, `carrito`, `detalle_carrito`, `compras`, `detalle_compras`, `proveedores`, `sucursales`
- La Serena: `stock`, `movimientos_stock`
- Coquimbo: `stock`, `movimientos_stock`

## Flujo De Venta

```text
Cliente -> Carrito -> Validacion -> 2PC ->
  1. Insert venta en Central
  2. Insert detalle_ventas en Central
  3. Descuento stock en sucursal destino
  4. Commit en todos los nodos
  5. Si algo falla: ROLLBACK global
```

## Flujo De Compra

```text
Vendedor -> Proveedor -> Compra -> 2PC ->
  1. Insert compra en Central
  2. Insert detalle_compras en Central
  3. Sumar stock en sucursal destino
  4. Insert movimiento_stock
  5. Commit global o rollback total
```

## Como Cumple La Rueda

- CRUD completo: productos, clientes, usuarios, sucursales, stock, carrito, ventas, compras, proveedores
- Distribucion: la BD esta separada por nodo y responsabilidad
- ACID: ventas y compras usan transaccion con rollback
- 2PC inspirado: valida, prepara y confirma solo si todos los nodos responden bien
- PDO + prepared statements: toda la capa de acceso usa PDO
- Excepciones: errores controlados y mostrados sin romper la app
- Borrado logico: entidades criticas se desactivan en vez de eliminarse
- CAP: se prioriza CP para evitar inconsistencia de inventario
- Caida de nodo: se puede simular desde `nodos.php`

## Archivos Clave

- `front/includes/config.php`: credenciales y bootstrap de carga
- `front/includes/lm_database.php`: conexion, ping, simulacion de nodos
- `front/includes/lm_services.php`: CRUD, stock, ventas, compras, 2PC
- `front/nodos.php`: monitor y simulacion de caidas
- `front/productos.php`, `front/clientes.php`, `front/compras.php`, `front/carrito.php`, `front/ventas.php`: UI conectada
