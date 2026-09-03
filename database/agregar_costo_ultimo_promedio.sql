-- =========================================================
-- COSTO ULTIMO Y COSTO PROMEDIO PONDERADO
-- Ejecutar una sola vez sobre la base almacen_farmacia.
-- =========================================================

USE almacen_farmacia;

ALTER TABLE productos
    ADD COLUMN costo_ultimo DECIMAL(14,4) NOT NULL DEFAULT 0.0000
        AFTER precio_compra,
    ADD COLUMN costo_promedio DECIMAL(14,4) NOT NULL DEFAULT 0.0000
        AFTER costo_ultimo;

-- Inicializa los nuevos campos con el costo que ya tiene cada producto.
UPDATE productos
SET costo_ultimo = COALESCE(precio_compra, 0),
    costo_promedio = COALESCE(precio_compra, 0);

-- precio_compra se conserva por compatibilidad con módulos existentes.
-- Desde esta actualización representa el mismo valor que costo_ultimo.
