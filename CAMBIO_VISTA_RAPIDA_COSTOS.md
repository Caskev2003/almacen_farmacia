# Vista rápida de costos en Existencias

Se compactó la tabla del módulo **Existencias**.

## Cambio visual

Para usuarios ADMINISTRADOR, las columnas:

- Costo último
- Costo promedio
- Valor a costo promedio

se sustituyeron por una sola columna **Costos** con el botón **Vista rápida**.

## Modal de costos

Al pulsar el botón se abre una ventana modal que muestra:

- Código
- Código de barras
- Descripción
- Almacén
- Ubicación
- Existencia
- Costo último
- Costo promedio
- Valor a costo promedio

La ventana puede cerrarse con el botón Cerrar, la X, haciendo clic fuera de la ventana o con la tecla Escape.

## Archivos modificados

- `public/existencias.php`
- `public/assets/css/existencias.css`

No requiere cambios SQL.
