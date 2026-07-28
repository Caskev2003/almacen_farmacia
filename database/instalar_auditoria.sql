-- ============================================================
-- BITÁCORA DETALLADA DE MOVIMIENTOS
-- Ejecutar una sola vez en la base de datos almacen_farmacia.
-- Es seguro volver a ejecutarlo porque usa IF NOT EXISTS.
-- ============================================================

USE almacen_farmacia;

CREATE TABLE IF NOT EXISTS auditoria_movimientos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT NULL,
    usuario_nombre VARCHAR(150) NULL,
    usuario_login VARCHAR(100) NULL,
    usuario_rol VARCHAR(40) NULL,
    almacen_id INT NULL,
    almacen_nombre VARCHAR(150) NULL,
    modulo VARCHAR(100) NOT NULL,
    accion VARCHAR(80) NOT NULL,
    entidad VARCHAR(100) NULL,
    registro_id VARCHAR(100) NULL,
    descripcion TEXT NOT NULL,
    datos_anteriores JSON NULL,
    datos_nuevos JSON NULL,
    metadata JSON NULL,
    direccion_ip VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    metodo_http VARCHAR(10) NULL,
    url VARCHAR(1000) NULL,
    creado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_auditoria_fecha (creado_en),
    KEY idx_auditoria_usuario (usuario_id, creado_en),
    KEY idx_auditoria_modulo (modulo, creado_en),
    KEY idx_auditoria_accion (accion, creado_en),
    KEY idx_auditoria_almacen (almacen_id, creado_en),
    KEY idx_auditoria_entidad (entidad, registro_id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
