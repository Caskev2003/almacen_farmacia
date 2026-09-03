# Corrección de error en Salidas y stock por almacén

## Qué ocurría
La versión anterior para corregir el stock modificó simultáneamente:
- `public/salidas.php`
- `app/controllers/SalidaController.php`
- `app/models/Movimiento.php`

Eso hacía innecesariamente invasiva la corrección y podía dejar el módulo Salidas incompatible si los archivos instalados no eran exactamente de la misma versión.

## Qué se cambió ahora
Se restauró la interfaz estable de `SalidaController::productos()` y `Movimiento::getProductosParaSalida()`.

La selección del stock se resuelve únicamente desde `public/salidas.php`:
- En Resurtidos/Tickets toma `almacen_id` de la solicitud.
- En edición toma el almacén de la salida original.
- Para ADMINISTRADOR en una salida manual toma el almacén seleccionado.
- Durante la consulta del catálogo cambia temporalmente el contexto de almacén de la sesión y lo restaura inmediatamente después.
- El folio y último folio se consultan con el mismo almacén que surte.

## Instalación
Reemplace la carpeta del sistema con esta versión completa o, como mínimo, asegúrese de reemplazar juntos:
- `public/salidas.php`
- `app/controllers/SalidaController.php`
- `app/models/Movimiento.php`

No requiere cambios SQL adicionales.

## Prueba recomendada
1. Iniciar sesión como ADMINISTRADOR.
2. Abrir el mismo Resurtido/Ticket que antes mostraba stock 0.
3. Confirmar que el campo Almacén que surte corresponde a Ciudad Hidalgo.
4. Buscar el producto y verificar que Stock disponible coincida con Existencias.
