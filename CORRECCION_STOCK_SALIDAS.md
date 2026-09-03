# Corrección de stock en Salidas

## Problema
Al ingresar como ADMINISTRADOR, Salidas consultaba el catálogo utilizando el `almacen_id` de la sesión. Para el administrador ese valor puede ser `0`, por lo que la sucursal quedaba vacía y los productos aparecían con stock `0` aunque sí tuvieran existencia en Ciudad Hidalgo o Tuxtla.

## Corrección
- `Movimiento::getProductosParaSalida()` ahora puede recibir el almacén que realmente surte.
- Un Resurtido/Ticket usa automáticamente su `almacen_id` para consultar las existencias.
- En modo Resurtido/Ticket el almacén que surte queda fijado a la solicitud.
- Una salida manual del ADMINISTRADOR recarga el catálogo al seleccionar el almacén.
- El folio de salida y el último folio se consultan usando el almacén seleccionado.

No requiere cambios de base de datos.
