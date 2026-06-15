Segunda Evaluacion: Sistema de Comercio Electrónico Distribuido "Libre Mercado"

Sistemas distribuídos – Juan Torres O.

1. Objetivos del Taller






Implementar operaciones CRUD funcionales en un entorno distribuido.

Garantizar consistencia y transaccionalidad mediante propiedades ACID.

Analizar y justificar las decisiones arquitectónicas bajo el Teorema CAP.

Desarrollar la lógica del sistema utilizando el lenguaje PHP.

2. Descripción del Proyecto

Cada grupo deberá diseñar e implementar un prototipo de sistema distribuido de comercio electrónico inspirado en el borrador inicial ("Libre Mercado").
El sistema debe gestionar múltiples nodos o bases de datos distribuidas para simular un entorno real de alta disponibilidad o fragmentación.

Componentes Base del Diseño.

El sistema debe contemplar, al menos, los siguientes módulos y entidades:







Productos: id_prod, producto, precio, descripción.

Clientes y Usuarios: id_cli, cliente, credenciales, roles.

Sucursales y Stock: id_suc, sucursal, control de inventario por ubicación.

Carrito y Ventas: id_carrito, detalle_carrito, procesamiento de ventas y detalle_ventas.

Compras y Proveedores: Gestión de reabastecimiento con proveedores y detalle_compras.

3. Requisitos Técnicos y Entregables

Requisito 1: Operaciones CRUD




Crear, leer, actualizar y eliminar registros en las entidades principales.

Las bajas de productos o usuarios críticos deben manejar borrado lógico o validación de dependencias.

Requisito 2: Transacciones ACID





Implementar mecanismos (ej. Two-Phase Commit o transacciones nativas distribuidas) para asegurar que una venta descuente el stock de la
sucursal de forma atómica.

Si el inventario falla en un nodo, la venta completa debe hacer rollback.

Requisito 3: Aplicación del Teorema CAP





El grupo debe elegir explícitamente qué par del teorema priorizará en caso de partición de red: Consistencia y Disponibilidad (CA),
Consistencia y Tolerancia a Particiones (CP) o Disponibilidad y Tolerancia a Particiones (AP).

Entregable: Un documento de arquitectura (máximo 2 páginas) que justifique técnicamente la elección frente a un fallo de conexión entre nodos.

Requisito 4: Backend en PHP




Código limpio, modular y documentado utilizando PHP.

Conexión a bases de datos mediante PDO para asegurar el manejo correcto de transacciones.

4. Rúbrica de Evaluación (Escala del 1 al 5)

Criterio

Excelente (5)

Competente (4)

Necesita Mejorar (3)

Insuficiente (1-2)

Modelado y
CRUD

Base de datos distribuida óptima.
CRUDs completos y funcionales en
todas las entidades del borrador.

CRUDs funcionales, pero falta alguna
entidad secundaria o validación
menor.

Base de datos centralizada o
CRUDs incompletos con errores de
ejecución.

No presenta el modelo de
datos ni los CRUDs
solicitados.

Transacciones
ACID

Garantiza atomicidad y consistencia
global. El control de stock y ventas es
inmune a fallos concurrentes.

Implementa ACID local en cada
nodo, pero carece de robustez ante
fallos de red distribuidos.

Intento de manejo transaccional,
pero permite datos inconsistentes
(ej. sobreventa sin stock).

No implementa mecanismos
transaccionales (ACID).

Justificación
CAP

Demuestra el comportamiento del
sistema ante una partición simulada de
red de acuerdo a la teoría elegida.

Explica la teoría CAP correctamente,
pero el sistema no reacciona
exactamente como se documentó.

Confusión conceptual en la
elección del par CAP o falta de
simulación de fallos.

No incluye la justificación del
Teorema CAP ni arquitectura
distribuida.

Código y PHP

Código PHP estructurado, seguro
(PDO), modular y con manejo de
excepciones distribuido.

Código funcional en PHP, pero con
baja modularidad o manejo de
errores básico.

Código desorganizado, problemas
de conexión frecuentes o malas
prácticas de seguridad.

El backend no está
desarrollado en PHP o no
funciona.

Defensa y
Demo

Explicación clara de la arquitectura.
Respuestas precisas a las preguntas
del profesor.

Demostración fluida, pero debilidad al
responder el trasfondo teórico
distribuido.

Presentación atropellada. El
sistema falla durante la
demostración en vivo.

No se presentan a la defensa
o el sistema no
compila/ejecuta

