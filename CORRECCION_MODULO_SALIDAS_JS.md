# Corrección del módulo Salidas

## Problema encontrado
En `public/salidas.php` la constante JavaScript `modoEdicionSalida` quedó declarada dos veces después de la corrección de stock.

El navegador genera un error equivalente a:

`Uncaught SyntaxError: Identifier 'modoEdicionSalida' has already been declared`

Al existir ese error, el navegador deja de ejecutar todo el JavaScript restante del módulo Salidas. Esto impide utilizar funciones como buscar productos, agregar productos, cargar datos de resurtidos/tickets y guardar la salida.

## Corrección aplicada
- Se eliminó la declaración duplicada de `modoEdicionSalida`.
- Se conservó una sola declaración, la que ya existía originalmente junto a `csrfSalida`.
- Se conservó la corrección de almacén para que un Resurtido/Ticket consulte las existencias del almacén que realmente surte.
- Se validó `public/salidas.php` con `php -l`.
- Se extrajo/renderizó de forma representativa el bloque JavaScript y se validó con `node --check` sin errores.

## Archivo principal corregido
`public/salidas.php`

No requiere cambios adicionales de base de datos por esta corrección.
