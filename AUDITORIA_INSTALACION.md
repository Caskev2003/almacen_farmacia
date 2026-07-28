# Historial detallado de movimientos

## Instalación

1. Realice un respaldo de la base de datos actual.
2. Abra phpMyAdmin y seleccione la base `almacen_farmacia`.
3. Entre en la pestaña **Importar**.
4. Importe el archivo:

   `database/instalar_auditoria.sql`

5. Copie esta versión del proyecto al servidor.
6. Inicie sesión con un usuario cuyo rol sea `ADMINISTRADOR`.
7. Abra **Historial de Movimientos** desde el menú.

Se recomienda crear la tabla antes de publicar los archivos PHP. Si el código
se publica primero, el sistema seguirá funcionando, pero escribirá en el
registro de errores que la tabla de auditoría todavía no existe.

## Información registrada

- Inicio y cierre de sesión e intentos fallidos.
- Accesos a módulos.
- Búsquedas y filtros.
- Altas y modificaciones de productos.
- Cambios de nombre, código, precios y demás datos del producto.
- Cambios de ubicación y existencia con valores anteriores y nuevos.
- Entradas, salidas, ediciones y cancelaciones, incluyendo el cambio real de
  existencias de cada producto afectado.
- Resurtidos y cambios de estado.
- Inventarios físicos/virtuales.
- Importaciones de Excel, tanto el resumen como cada producto modificado.
- Exportaciones, impresiones y respaldos.
- Usuario, rol, almacén, fecha, hora, IP, método HTTP y URL.

Las contraseñas, tokens de seguridad y datos de sesión son reemplazados por
`[PROTEGIDO]` y nunca se guardan en la bitácora.

## Seguridad

La página `public/historial_movimientos.php` valida el rol en PHP. Ocultar el
enlace del menú no es la única protección: cualquier usuario que no sea
`ADMINISTRADOR` recibe una respuesta de acceso denegado.
