# Costos en Reportes, Excel y PDF

## Cambios realizados

- En `Reportes` se agregó la columna compacta **Costos** para el usuario ADMINISTRADOR.
- La columna muestra un botón **Vista rápida** en lugar de ocupar tres columnas en pantalla.
- El modal muestra:
  - Costo último.
  - Costo promedio.
  - Valor a costo promedio.
  - Datos básicos del producto, almacén, ubicación y existencia.
- La columna **Costos** queda seleccionada por defecto para ADMINISTRADOR.
- Al exportar a Excel, la columna compacta se convierte en tres columnas independientes:
  - Costo último.
  - Costo promedio.
  - Valor a costo promedio.
- Al exportar/imprimir PDF sucede lo mismo: aparecen los tres campos por separado.
- El valor a costo promedio se calcula como `existencia x costo_promedio` para la existencia incluida por los filtros del reporte.
- El PDF ajusta automáticamente el tamaño de la tabla cuando se seleccionan muchas columnas.

## Archivos modificados

- `app/controllers/ReporteController.php`
- `public/reportes.php`
- `public/exportar_reporte_excel.php`
- `public/exportar_reporte_pdf.php`
- `public/assets/css/reportes.css`

## Base de datos

No requiere ejecutar SQL adicional. Utiliza los campos `costo_ultimo` y `costo_promedio` agregados previamente.
