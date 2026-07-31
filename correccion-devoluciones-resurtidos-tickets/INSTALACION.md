# Corrección de devoluciones en Resurtidos y Tickets

## Archivos que debe reemplazar

Copie estos tres archivos conservando exactamente las mismas carpetas:

1. `app/models/Resurtido.php`
2. `app/controllers/DevolucionController.php`
3. `public/devoluciones.php`

## Instalación

1. Haga una copia de seguridad de los tres archivos actuales del servidor.
2. Extraiga este paquete.
3. Copie las carpetas `app` y `public` sobre la carpeta original del sistema.
4. Confirme el reemplazo únicamente de los tres archivos indicados.
5. En las páginas Devoluciones, Resurtidos y Tickets presione `Ctrl + F5`.

No es necesario ejecutar SQL ni reiniciar Apache.

## Corrección del error al enviar

El archivo también corrige el mensaje `No se pudo registrar la solicitud de ticket` que aparecía después de autorizarla. La causa era que el generador de folios esperaba la tabla auxiliar `control_folios_solicitudes`.

Ahora el sistema obtiene el siguiente consecutivo directamente de los registros existentes:

- Resurtidos continúa con el mayor folio `RES` de su almacén.
- Tickets continúa con el mayor folio `TKT` de su almacén.
- Los dos conteos permanecen separados.
- No es necesario crear la tabla auxiliar.

## Nueva regla

- Devolución sin palomita: continúa pendiente, aparta sus piezas y reduce la cantidad disponible para Resurtidos y Tickets.
- Devolución con palomita: significa que ya fue retirada del sistema; permanece visible y amarilla en la tabla, pero ya no aparta piezas ni aparece como cantidad en devolución al solicitar.
- Si se quita la palomita, las piezas vuelven a apartarse inmediatamente.

## Ejemplo

Si existen 300 piezas y hay una devolución de 2 piezas:

- Sin palomita: disponible para solicitar = 298.
- Con palomita: disponible para solicitar = 300.

La misma regla se aplica al buscar el producto y al validar nuevamente el envío. Esto evita que la pantalla muestre una cantidad y el servidor rechace la solicitud con otra diferente.

## Prueba recomendada

1. Deje una devolución sin palomita.
2. Busque el producto en Resurtidos y confirme que sus piezas aparecen apartadas.
3. Intente solicitar más de la cantidad disponible y confirme que el sistema lo impide.
4. Marque la palomita en Devoluciones.
5. Vuelva a buscar el producto en Resurtidos y Tickets: la cantidad en devolución debe desaparecer.
6. Envíe una solicitud para confirmar que ya se registra correctamente.
