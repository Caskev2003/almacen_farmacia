# Actualización: costo último y costo promedio

## Qué cambia

Se agregan dos valores al catálogo de productos:

- **Costo último:** el costo capturado en la entrada más reciente.
- **Costo promedio:** costo promedio ponderado de la existencia anterior y la nueva entrada.

La fórmula aplicada al guardar una entrada es:

`((existencia_anterior × costo_promedio_anterior) + (cantidad_entrada × costo_nuevo)) / (existencia_anterior + cantidad_entrada)`

Ejemplo:

- Existencia anterior: 100 piezas
- Costo promedio anterior: $10.00
- Entrada: 50 piezas
- Costo nuevo: $12.00

Resultado:

- Costo último: $12.00
- Costo promedio: $10.6667
- Existencia total: 150 piezas

## Instalación

1. Haz un respaldo de la base de datos `almacen_farmacia`.
2. En phpMyAdmin selecciona la base `almacen_farmacia`.
3. Ejecuta o importa:
   `database/agregar_costo_ultimo_promedio.sql`
4. Sustituye los archivos del sistema por los incluidos en este ZIP.
5. Abre **Productos**, **Entradas** y **Existencias** y verifica los nuevos campos.

> Importante: ejecuta el SQL antes de usar los PHP actualizados.

## Compatibilidad

El campo existente `precio_compra` se conserva para no romper otros módulos.
Desde esta actualización se mantiene sincronizado con **costo último**.

Los productos que ya existen inicializan:

- `costo_ultimo = precio_compra`
- `costo_promedio = precio_compra`

Por lo tanto, la primera entrada nueva parte del costo y la existencia que ya tienes actualmente.

## Visualización

- **Productos:** Costo último y Costo promedio.
- **Entradas:** costo actual del producto, promedio actual, existencia total, nuevo costo último y promedio estimado.
- **Existencias (Administrador):** Costo último, Costo promedio y valor de inventario calculado con costo promedio.

## Corrección adicional: folio de Entradas por almacén

Se corrigió la generación del folio en `public/entradas.php` para usuarios que pueden seleccionar almacén (por ejemplo, ADMINISTRADOR).

- Ya no se muestra `ENT-0-0001` al abrir una nueva entrada sin almacén seleccionado.
- Al seleccionar un almacén, el folio se consulta y actualiza automáticamente sin recargar la página.
- También cambia el texto de "Último folio registrado" al correspondiente de ese almacén.
- En modo edición se conserva el folio original del movimiento.
