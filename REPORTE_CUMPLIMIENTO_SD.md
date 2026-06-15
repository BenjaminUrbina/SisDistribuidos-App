# Reporte de Cumplimiento - Proyecto "Libre Mercado"
**Asignatura:** Sistemas Distribuidos
**Fecha de Revisión:** 10 de junio de 2026

Este documento detalla el estado actual del proyecto frente a los requisitos de la "Segunda Evaluación SD_2026".

## Resumen de Evaluación General

El proyecto presenta un alto nivel de avance, con una arquitectura distribuida funcional basada en Docker, una interfaz pulida y una lógica de negocio modular en PHP. Cumple con la mayoría de los requisitos de nivel "Competente" y varios de nivel "Excelente".

---

## 1. Operaciones CRUD (Estado: **COMPLETO**)
- [x] **Productos:** Implementado en `vendedor.php` y `lm_services.php`. Maneja ID, nombre, precio y descripción.
- [x] **Clientes y Usuarios:** Implementado en `clientes.php`. Incluye gestión de credenciales y roles (Admin, Vendedor, Cliente).
- [x] **Sucursales y Stock:** Implementado en `sucursales.php`. El stock está físicamente distribuido en los nodos `sucursal1` (La Serena) y `sucursal2` (Coquimbo).
- [x] **Carrito y Ventas:** Implementado en `carrito.php` y `ventas.php`.
- [x] **Compras y Proveedores:** Implementado en `compras.php`.
- [x] **Borrado Lógico:** Las entidades principales utilizan una columna `activo` para desactivación en lugar de eliminación física.

### Oportunidades de Mejora:
* **Validación de Dependencias:** Al desactivar (borrado lógico) una sucursal o producto, se recomienda añadir una validación que impida la acción si existen ventas pendientes o stock remanente para asegurar la integridad referencial distribuida.

---

## 2. Transacciones ACID (Estado: **COMPLETO - MEJORABLE**)
- [x] **Consistencia Global:** Se utiliza un `lm_transaction_coordinator` que inicia transacciones en múltiples nodos simultáneamente.
- [x] **Atomicidad:** Si falla el descuento de stock o el registro de la venta, el sistema realiza un `rollback` en los nodos involucrados.
- [x] **Uso de PDO:** Implementado correctamente con manejo de excepciones.

### Oportunidades de Mejora (Para alcanzar el 5.0):
* **True Two-Phase Commit (2PC):** La implementación actual realiza un "commit encadenado" (commit secuencial en cada nodo). Para una robustez absoluta ante fallos de red *durante* la fase de commit, se recomienda investigar el uso de **Transacciones XA** nativas de MySQL (`XA START`, `XA PREPARE`, `XA COMMIT`). Esto garantiza que si un nodo está listo pero otro falla en el último segundo, ninguno confirme los datos.

---

## 3. Teorema CAP (Estado: **EXCELENTE**)
- [x] **Elección Explícita:** El sistema prioriza **CP** (Consistencia y Tolerancia a Particiones), sacrificando Disponibilidad en caso de falla de un nodo crítico de stock.
- [x] **Simulación de Fallos:** El archivo `nodos.php` permite simular caídas de red y demuestra cómo el sistema rechaza operaciones inconsistentes.
- [x] **Justificación:** La justificación técnica está presente tanto en el código como en la interfaz de usuario.

### Oportunidades de Mejora:
* **Documento de Arquitectura:** Asegurarse de que el archivo `MAPA_LIBRE_MERCADO.md` sea entregado en un formato formal (o exportado a PDF) para cumplir con el requisito de "Documento de Arquitectura de máximo 2 páginas".

---

## 4. Backend en PHP (Estado: **EXCELENTE**)
- [x] **Modularidad:** Separación clara entre la capa de datos (`lm_database.php`), servicios (`lm_services.php`) y presentación.
- [x] **Seguridad:** Uso de `password_hash` para credenciales y `PDO` con *prepared statements* para evitar Inyección SQL.

### Oportunidades de Mejora:
* **Manejo de Errores Distribuido:** Se recomienda centralizar el log de errores de todos los nodos en una tabla o archivo único para facilitar el debugging en producción.

---

## Lista de "Cosas Pendientes" (Action Items)

1.  **[Prioridad Alta]** Realizar una prueba de estrés simulando la caída del nodo de stock *justo antes* del commit en el nodo central para verificar el estado de inconsistencia potencial (y documentarlo en el informe CAP).
2.  **[Prioridad Media]** Implementar un "Health Check" automático en el Dashboard que avise si un nodo de sucursal está fuera de línea antes de intentar una venta.
3.  **[Prioridad Baja]** Añadir protección CSRF en los formularios de los CRUDs para elevar el estándar de seguridad.

---
**Resultado Final:** El proyecto está en un estado muy sólido (**Nota Estimada: 4.8 - 5.0**). Siguiendo las recomendaciones de 2PC y validación de dependencias, se garantiza la máxima calificación.
