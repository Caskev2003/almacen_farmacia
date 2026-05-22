<?php

require_once __DIR__ . '/../config/database.php';

class Movimiento
{
    private PDO $conn;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->connect();
    }

    public function getConnection(): PDO
    {
        return $this->conn;
    }

    private function limpiarUbicacion(?string $ubicacion): string
    {
        $ubicacion = strtoupper(trim((string)$ubicacion));
        $ubicacion = str_replace('SIN UBICACIÓN', 'SIN UBICACION', $ubicacion);

        return $ubicacion !== '' ? $ubicacion : 'SIN UBICACION';
    }

    private function obtenerSucursalPorAlmacenId(?int $almacenId): string
    {
        $almacenId = (int)$almacenId;

        if ($almacenId === 1) {
            return 'CIUDAD HIDALGO';
        }

        if ($almacenId === 2 || $almacenId === 3) {
            return 'TUXTLA';
        }

        return '';
    }

    private function obtenerAlmacenSesion(): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $usuario = $_SESSION['user'] ?? [];

        $rol = strtoupper(trim($usuario['rol'] ?? ''));
        $almacenId = (int)($usuario['almacen_id'] ?? 0);
        $almacenNombre = strtoupper(trim($usuario['almacen_nombre'] ?? ''));

        if (str_contains($almacenNombre, 'HIDALGO')) {
            $sucursal = 'CIUDAD HIDALGO';
        } elseif (str_contains($almacenNombre, 'TUXTLA')) {
            $sucursal = 'TUXTLA';
        } else {
            $sucursal = $this->obtenerSucursalPorAlmacenId($almacenId);
        }

        return [
            'rol' => $rol,
            'almacen_id' => $almacenId,
            'sucursal' => $sucursal
        ];
    }

    public function getAlmacenes(): array
    {
        $sesion = $this->obtenerAlmacenSesion();

        if ($sesion['rol'] === 'ADMINISTRADOR') {
            $sql = "SELECT id, nombre
                    FROM almacenes
                    WHERE estado = 1
                    ORDER BY nombre ASC";

            $stmt = $this->conn->query($sql);
        } else {
            $sql = "SELECT id, nombre
                    FROM almacenes
                    WHERE estado = 1
                    AND id = :almacen_id
                    LIMIT 1";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':almacen_id' => $sesion['almacen_id']
            ]);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProveedores(): array
    {
        $sql = "SELECT id, nombre 
                FROM proveedores 
                WHERE estado = 1 
                ORDER BY nombre ASC";

        $stmt = $this->conn->query($sql);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProductosActivos(): array
    {
        $sesion = $this->obtenerAlmacenSesion();
        $sucursal = $sesion['sucursal'];

        $sql = "SELECT 
                    p.id,
                    p.codigo,
                    p.codigo_barras,
                    p.descripcion,
                    p.precio_compra,
                    p.precio_venta,
                    p.unidad_medida,
                    p.ubicacion,
                    COALESCE(SUM(pe.existencia), 0) AS existencia_actual,
                    COALESCE(SUM(pe.existencia), 0) AS existencia_bodega
                FROM productos p
                LEFT JOIN producto_existencias pe
                    ON pe.producto_id = p.id
                    AND pe.sucursal = :sucursal
                WHERE p.estado = 1
                GROUP BY 
                    p.id,
                    p.codigo,
                    p.codigo_barras,
                    p.descripcion,
                    p.precio_compra,
                    p.precio_venta,
                    p.unidad_medida,
                    p.ubicacion
                ORDER BY p.descripcion ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':sucursal' => $sucursal
        ]);

        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($productos as &$producto) {
            $productoId = (int)$producto['id'];

            $sqlUbicaciones = "SELECT 
                                    COALESCE(ubicacion, 'SIN UBICACION') AS ubicacion,
                                    existencia AS existencia_actual
                               FROM producto_existencias
                               WHERE producto_id = :producto_id
                               AND sucursal = :sucursal
                               ORDER BY 
                                    CASE 
                                        WHEN existencia > 0 THEN 0 
                                        ELSE 1 
                                    END,
                                    existencia ASC,
                                    ubicacion ASC";

            $stmtUbicaciones = $this->conn->prepare($sqlUbicaciones);
            $stmtUbicaciones->execute([
                ':producto_id' => $productoId,
                ':sucursal' => $sucursal
            ]);

            $ubicaciones = $stmtUbicaciones->fetchAll(PDO::FETCH_ASSOC);

            $producto['ubicaciones'] = [];

            foreach ($ubicaciones as $ubi) {
                $producto['ubicaciones'][] = [
                    'ubicacion' => $this->limpiarUbicacion($ubi['ubicacion'] ?? ''),
                    'existencia_actual' => (int)$ubi['existencia_actual']
                ];
            }

            if (!empty($producto['ubicaciones'])) {
                $producto['ubicacion'] = $producto['ubicaciones'][0]['ubicacion'];
            } else {
                $producto['ubicaciones'][] = [
                    'ubicacion' => $this->limpiarUbicacion($producto['ubicacion'] ?? ''),
                    'existencia_actual' => (int)$producto['existencia_actual']
                ];
            }
        }

        unset($producto);

        return $productos;
    }

    private function generarFolioPorAlmacen(string $tipoMovimiento, int $almacenId): string
    {
        $tipoMovimiento = strtoupper(trim($tipoMovimiento));
        $almacenId = (int)$almacenId;

        if ($almacenId <= 0) {
            $sesion = $this->obtenerAlmacenSesion();
            $almacenId = (int)$sesion['almacen_id'];
        }

        if ($almacenId <= 0) {
            $almacenId = 0;
        }

        $prefijo = $tipoMovimiento === 'SALIDA' ? 'SAL' : 'ENT';

        $sql = "SELECT COUNT(*) AS total
                FROM movimientos
                WHERE tipo_movimiento = :tipo_movimiento
                AND almacen_id = :almacen_id";

        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            ':tipo_movimiento' => $tipoMovimiento,
            ':almacen_id' => $almacenId
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $numero = ((int)($row['total'] ?? 0)) + 1;

        return $prefijo . '-' . $almacenId . '-' . str_pad((string)$numero, 4, '0', STR_PAD_LEFT);
    }

    public function generarFolioEntrada(?int $almacenId = null): string
    {
        return $this->generarFolioPorAlmacen('ENTRADA', (int)$almacenId);
    }

    public function generarFolioSalida(?int $almacenId = null): string
    {
        return $this->generarFolioPorAlmacen('SALIDA', (int)$almacenId);
    }

    public function ultimoFolioSalida(?int $almacenId = null): string
    {
        $params = [];

        $sql = "SELECT folio 
                FROM movimientos 
                WHERE tipo_movimiento = 'SALIDA'";

        if ($almacenId !== null && (int)$almacenId > 0) {
            $sql .= " AND almacen_id = :almacen_id";
            $params[':almacen_id'] = (int)$almacenId;
        }

        $sql .= " ORDER BY id DESC LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $row['folio'] : '';
    }

    public function ultimoFolioEntrada(?int $almacenId = null): string
    {
        $params = [];

        $sql = "SELECT folio 
                FROM movimientos 
                WHERE tipo_movimiento = 'ENTRADA'";

        if ($almacenId !== null && (int)$almacenId > 0) {
            $sql .= " AND almacen_id = :almacen_id";
            $params[':almacen_id'] = (int)$almacenId;
        }

        $sql .= " ORDER BY id DESC LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $row['folio'] : '';
    }

    private function aumentarExistencia(
        int $productoId,
        string $sucursal,
        int $cantidad,
        string $ubicacion = ''
    ): void {
        $ubicacion = $this->limpiarUbicacion($ubicacion);

        $sql = "INSERT INTO producto_existencias (
                    producto_id,
                    sucursal,
                    ubicacion,
                    existencia
                ) VALUES (
                    :producto_id,
                    :sucursal,
                    :ubicacion,
                    :cantidad
                )
                ON DUPLICATE KEY UPDATE
                    existencia = existencia + VALUES(existencia),
                    updated_at = CURRENT_TIMESTAMP";

        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            ':producto_id' => $productoId,
            ':sucursal' => $sucursal,
            ':ubicacion' => $ubicacion,
            ':cantidad' => $cantidad
        ]);
    }

    private function disminuirExistencia(
        int $productoId,
        string $sucursal,
        int $cantidad,
        string $ubicacion = ''
    ): void {
        $ubicacion = $this->limpiarUbicacion($ubicacion);

        $sql = "UPDATE producto_existencias
                SET existencia = existencia - :cantidad,
                    updated_at = CURRENT_TIMESTAMP
                WHERE producto_id = :producto_id
                AND sucursal = :sucursal
                AND ubicacion = :ubicacion";

        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            ':cantidad' => $cantidad,
            ':producto_id' => $productoId,
            ':sucursal' => $sucursal,
            ':ubicacion' => $ubicacion
        ]);
    }

    private function actualizarUbicacionPrincipalProducto(
        int $productoId,
        string $ubicacion
    ): void {
        $ubicacion = $this->limpiarUbicacion($ubicacion);

        if ($ubicacion === 'SIN UBICACION') {
            return;
        }

        $sql = "UPDATE productos
                SET ubicacion = :ubicacion
                WHERE id = :producto_id";

        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            ':ubicacion' => $ubicacion,
            ':producto_id' => $productoId
        ]);
    }

   private function moverSinUbicacionAUbicacion(
    int $productoId,
    string $sucursal,
    string $ubicacionNueva
): void {
    $ubicacionNueva = $this->limpiarUbicacion($ubicacionNueva);

    if ($ubicacionNueva === 'SIN UBICACION') {
        return;
    }

    $sqlExisteNueva = "SELECT COUNT(*)
                       FROM producto_existencias
                       WHERE producto_id = :producto_id
                         AND sucursal = :sucursal
                         AND ubicacion = :ubicacion";

    $stmtExisteNueva = $this->conn->prepare($sqlExisteNueva);
    $stmtExisteNueva->execute([
        ':producto_id' => $productoId,
        ':sucursal' => $sucursal,
        ':ubicacion' => $ubicacionNueva
    ]);

    $yaExisteNueva = (int)$stmtExisteNueva->fetchColumn();

    if ($yaExisteNueva > 0) {
        $this->actualizarUbicacionPrincipalProducto($productoId, $ubicacionNueva);
        return;
    }

    $sqlSinUbicacion = "SELECT id, existencia
                        FROM producto_existencias
                        WHERE producto_id = :producto_id
                          AND sucursal = :sucursal
                          AND ubicacion IN ('SIN UBICACION', 'SIN UBICACIÓN')
                          AND existencia > 0
                        LIMIT 1";

    $stmtSin = $this->conn->prepare($sqlSinUbicacion);
    $stmtSin->execute([
        ':producto_id' => $productoId,
        ':sucursal' => $sucursal
    ]);

    $sinUbicacion = $stmtSin->fetch(PDO::FETCH_ASSOC);

    if (!$sinUbicacion) {
        $this->actualizarUbicacionPrincipalProducto($productoId, $ubicacionNueva);
        return;
    }

    $sqlUpdate = "UPDATE producto_existencias
                  SET ubicacion = :ubicacion_nueva,
                      updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id";

    $stmtUpdate = $this->conn->prepare($sqlUpdate);
    $stmtUpdate->execute([
        ':ubicacion_nueva' => $ubicacionNueva,
        ':id' => $sinUbicacion['id']
    ]);

    $this->actualizarUbicacionPrincipalProducto($productoId, $ubicacionNueva);
}
    private function eliminarUbicacionSiEstaVacia(
        int $productoId,
        string $sucursal,
        string $ubicacion = ''
    ): void {
        $ubicacion = $this->limpiarUbicacion($ubicacion);

        $sql = "DELETE FROM producto_existencias
                WHERE producto_id = :producto_id
                AND sucursal = :sucursal
                AND ubicacion = :ubicacion
                AND existencia <= 0";

        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            ':producto_id' => $productoId,
            ':sucursal' => $sucursal,
            ':ubicacion' => $ubicacion
        ]);
    }

    private function obtenerUbicacionesDisponiblesProducto(
        int $productoId,
        string $sucursal,
        string $ubicacionPreferida = ''
    ): array {
        $ubicacionPreferida = $this->limpiarUbicacion($ubicacionPreferida);

        $sql = "SELECT 
                    ubicacion,
                    existencia
                FROM producto_existencias
                WHERE producto_id = :producto_id
                AND sucursal = :sucursal
                AND existencia > 0
                ORDER BY 
                    CASE 
                        WHEN ubicacion = :ubicacion_preferida THEN 0
                        ELSE 1
                    END,
                    existencia ASC,
                    ubicacion ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':producto_id' => $productoId,
            ':sucursal' => $sucursal,
            ':ubicacion_preferida' => $ubicacionPreferida
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function crearMovimiento(array $data, array $detalle): array
    {
        try {
            $this->conn->beginTransaction();

            $almacenId = (int)($data['almacen_id'] ?? 0);
            $sucursal = $this->obtenerSucursalPorAlmacenId($almacenId);

            if ($sucursal === '') {
                throw new Exception('No se pudo identificar la sucursal del almacén.');
            }

            if (empty($data['folio'])) {
                $data['folio'] = $this->generarFolioEntrada($almacenId);
            }

            $sqlMovimiento = "INSERT INTO movimientos (
                                folio, tipo_movimiento, fecha, almacen_id,
                                usuario_id, proveedor_id, referencia, observaciones
                              ) VALUES (
                                :folio, :tipo_movimiento, :fecha, :almacen_id,
                                :usuario_id, :proveedor_id, :referencia, :observaciones
                              )";

            $stmtMovimiento = $this->conn->prepare($sqlMovimiento);

            $stmtMovimiento->execute([
                ':folio' => $data['folio'],
                ':tipo_movimiento' => $data['tipo_movimiento'],
                ':fecha' => $data['fecha'],
                ':almacen_id' => $almacenId ?: null,
                ':usuario_id' => $data['usuario_id'],
                ':proveedor_id' => $data['proveedor_id'] ?: null,
                ':referencia' => $data['referencia'] ?: null,
                ':observaciones' => $data['observaciones'] ?: null,
            ]);

            $movimientoId = (int)$this->conn->lastInsertId();

            $sqlDetalle = "INSERT INTO movimiento_detalle (
                                movimiento_id, producto_id, lote_id, cantidad,
                                costo_unitario, precio_unitario, ubicacion
                           ) VALUES (
                                :movimiento_id, :producto_id, :lote_id, :cantidad,
                                :costo_unitario, :precio_unitario, :ubicacion
                           )";

            $stmtDetalle = $this->conn->prepare($sqlDetalle);

            $sqlLote = "INSERT INTO lotes (
                            producto_id, numero_lote, fecha_caducidad,
                            existencia, costo_unitario, almacen_id, ubicacion, estado
                        ) VALUES (
                            :producto_id, :numero_lote, :fecha_caducidad,
                            :existencia, :costo_unitario, :almacen_id, :ubicacion, 1
                        )";

            $stmtLote = $this->conn->prepare($sqlLote);

            $sqlActualizarProducto = "UPDATE productos
                                      SET precio_compra = :precio_compra,
                                          ubicacion = :ubicacion
                                      WHERE id = :producto_id";

            $stmtActualizarProducto = $this->conn->prepare($sqlActualizarProducto);

            foreach ($detalle as $item) {
                $loteId = null;

                $productoId = (int)$item['producto_id'];
                $cantidad = (int)$item['cantidad'];
                $ubicacion = $this->limpiarUbicacion($item['ubicacion'] ?? '');

                if (!empty($item['numero_lote'])) {
                    $stmtLote->execute([
                        ':producto_id' => $productoId,
                        ':numero_lote' => $item['numero_lote'],
                        ':fecha_caducidad' => $item['fecha_caducidad'] ?: null,
                        ':existencia' => $cantidad,
                        ':costo_unitario' => $item['costo_unitario'],
                        ':almacen_id' => $almacenId ?: null,
                        ':ubicacion' => $ubicacion,
                    ]);

                    $loteId = (int)$this->conn->lastInsertId();
                }

                $stmtDetalle->execute([
                    ':movimiento_id' => $movimientoId,
                    ':producto_id' => $productoId,
                    ':lote_id' => $loteId,
                    ':cantidad' => $cantidad,
                    ':costo_unitario' => $item['costo_unitario'],
                    ':precio_unitario' => $item['precio_unitario'] ?? 0,
                    ':ubicacion' => $ubicacion,
                ]);

                if ($data['tipo_movimiento'] === 'ENTRADA') {
                    $this->aumentarExistencia($productoId, $sucursal, $cantidad, $ubicacion);

                    $stmtActualizarProducto->execute([
                        ':precio_compra' => $item['costo_unitario'],
                        ':ubicacion' => $ubicacion,
                        ':producto_id' => $productoId,
                    ]);
                }
            }

            $this->conn->commit();

            return [
                'success' => true,
                'message' => ucfirst(strtolower($data['tipo_movimiento'])) . ' registrada correctamente.',
                'folio' => $data['folio'],
                'movimiento_id' => $movimientoId
            ];

        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            return [
                'success' => false,
                'message' => 'Error al guardar el movimiento: ' . $e->getMessage()
            ];
        }
    }

    public function crearSalida(array $data, array $detalle): array
    {
        try {
            $this->conn->beginTransaction();

            $almacenId = (int)($data['almacen_id'] ?? 0);
            $sucursal = $this->obtenerSucursalPorAlmacenId($almacenId);

            if ($sucursal === '') {
                throw new Exception('No se pudo identificar la sucursal del almacén.');
            }

            if (empty($data['folio'])) {
                $data['folio'] = $this->generarFolioSalida($almacenId);
            }

            $sqlMovimiento = "INSERT INTO movimientos (
                                folio, tipo_movimiento, fecha, almacen_id,
                                usuario_id, proveedor_id, referencia, tipo_operacion, observaciones
                              ) VALUES (
                                :folio, 'SALIDA', :fecha, :almacen_id,
                                :usuario_id, NULL, :referencia, :tipo_operacion, :observaciones
                              )";

            $stmtMovimiento = $this->conn->prepare($sqlMovimiento);

            $stmtMovimiento->execute([
                ':folio' => $data['folio'],
                ':fecha' => $data['fecha'],
                ':almacen_id' => $almacenId ?: null,
                ':usuario_id' => $data['usuario_id'],
                ':referencia' => $data['referencia'] ?: null,
                ':tipo_operacion' => $data['tipo_operacion'] ?: null,
                ':observaciones' => $data['observaciones'] ?: null,
            ]);

            $movimientoId = (int)$this->conn->lastInsertId();

            $sqlProducto = "SELECT 
                                id,
                                descripcion,
                                precio_compra,
                                precio_venta,
                                ubicacion
                            FROM productos 
                            WHERE id = :id 
                            AND estado = 1
                            LIMIT 1";

            $stmtProducto = $this->conn->prepare($sqlProducto);

            $sqlDetalle = "INSERT INTO movimiento_detalle (
                                movimiento_id, producto_id, lote_id, cantidad,
                                costo_unitario, precio_unitario, ubicacion
                           ) VALUES (
                                :movimiento_id, :producto_id, NULL, :cantidad,
                                :costo_unitario, :precio_unitario, :ubicacion
                           )";

            $stmtDetalle = $this->conn->prepare($sqlDetalle);

            foreach ($detalle as $item) {
                $productoId = (int)$item['producto_id'];
                $cantidadSolicitada = (int)$item['cantidad'];
                $ubicacionPreferida = $this->limpiarUbicacion($item['ubicacion'] ?? '');

                $stmtProducto->execute([
                    ':id' => $productoId
                ]);

                $producto = $stmtProducto->fetch(PDO::FETCH_ASSOC);

                if (!$producto) {
                    throw new Exception('Producto no encontrado.');
                }

                if ($ubicacionPreferida === 'SIN UBICACION') {
                    throw new Exception(
                        'Debes seleccionar o escribir una ubicación válida para el producto: ' .
                        $producto['descripcion']
                    );
                }

                if ($cantidadSolicitada <= 0) {
                    throw new Exception('La cantidad debe ser mayor a 0.');
                }

                $this->moverSinUbicacionAUbicacion(
                    $productoId,
                    $sucursal,
                    $ubicacionPreferida
                );

                $ubicacionesDisponibles = $this->obtenerUbicacionesDisponiblesProducto(
                    $productoId,
                    $sucursal,
                    $ubicacionPreferida
                );

                $existenciaTotal = 0;

                foreach ($ubicacionesDisponibles as $ubi) {
                    $existenciaTotal += (int)$ubi['existencia'];
                }

                if ($existenciaTotal < $cantidadSolicitada) {
                    throw new Exception(
                        'Stock insuficiente en ' . $sucursal .
                        ' para el producto: ' . $producto['descripcion'] .
                        '. Solicitado: ' . $cantidadSolicitada .
                        '. Disponible total: ' . $existenciaTotal
                    );
                }

                $cantidadPendiente = $cantidadSolicitada;

                foreach ($ubicacionesDisponibles as $ubi) {
                    if ($cantidadPendiente <= 0) {
                        break;
                    }

                    $ubicacionActual = $this->limpiarUbicacion($ubi['ubicacion'] ?? '');
                    $existenciaUbicacion = (int)$ubi['existencia'];

                    if ($existenciaUbicacion <= 0) {
                        continue;
                    }

                    $cantidadADescontar = min($cantidadPendiente, $existenciaUbicacion);

                    $stmtDetalle->execute([
                        ':movimiento_id' => $movimientoId,
                        ':producto_id' => $productoId,
                        ':cantidad' => $cantidadADescontar,
                        ':costo_unitario' => $item['costo_unitario'],
                        ':precio_unitario' => $item['precio_unitario'],
                        ':ubicacion' => $ubicacionActual,
                    ]);

                    $this->disminuirExistencia(
                        $productoId,
                        $sucursal,
                        $cantidadADescontar,
                        $ubicacionActual
                    );

                    $this->actualizarUbicacionPrincipalProducto(
                        $productoId,
                        $ubicacionActual
                    );

                    $this->eliminarUbicacionSiEstaVacia(
                        $productoId,
                        $sucursal,
                        $ubicacionActual
                    );

                    $cantidadPendiente -= $cantidadADescontar;
                }
            }

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Salida registrada correctamente.',
                'folio' => $data['folio'],
                'movimiento_id' => $movimientoId
            ];

        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            return [
                'success' => false,
                'message' => 'Error al guardar la salida: ' . $e->getMessage()
            ];
        }
    }

    public function obtenerEntradaPorId(int $movimientoId): ?array
    {
        $sql = "SELECT 
                    m.id,
                    m.folio,
                    m.fecha,
                    m.tipo_movimiento,
                    m.referencia,
                    m.observaciones,
                    a.nombre AS almacen_nombre,
                    p.nombre AS proveedor_nombre,
                    u.nombre AS usuario_nombre
                FROM movimientos m
                LEFT JOIN almacenes a ON m.almacen_id = a.id
                LEFT JOIN proveedores p ON m.proveedor_id = p.id
                INNER JOIN usuarios u ON m.usuario_id = u.id
                WHERE m.id = :id
                AND m.tipo_movimiento = 'ENTRADA'
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':id' => $movimientoId
        ]);

        $movimiento = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$movimiento) {
            return null;
        }

        $sqlDetalle = "SELECT 
                            md.cantidad,
                            md.costo_unitario,
                            md.ubicacion,
                            p.codigo,
                            p.descripcion,
                            p.unidad_medida,
                            l.numero_lote,
                            l.fecha_caducidad
                       FROM movimiento_detalle md
                       INNER JOIN productos p ON md.producto_id = p.id
                       LEFT JOIN lotes l ON md.lote_id = l.id
                       WHERE md.movimiento_id = :movimiento_id
                       ORDER BY md.id ASC";

        $stmtDetalle = $this->conn->prepare($sqlDetalle);
        $stmtDetalle->execute([
            ':movimiento_id' => $movimientoId
        ]);

        $movimiento['detalles'] = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

        return $movimiento;
    }

    public function obtenerSalidaPorId(int $movimientoId): ?array
    {
        $sql = "SELECT 
                    m.id,
                    m.folio,
                    m.fecha,
                    m.tipo_movimiento,
                    m.referencia,
                    m.tipo_operacion,
                    m.observaciones,
                    a.nombre AS almacen_nombre,
                    u.nombre AS usuario_nombre
                FROM movimientos m
                LEFT JOIN almacenes a ON m.almacen_id = a.id
                INNER JOIN usuarios u ON m.usuario_id = u.id
                WHERE m.id = :id
                AND m.tipo_movimiento = 'SALIDA'
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':id' => $movimientoId
        ]);

        $movimiento = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$movimiento) {
            return null;
        }

        $sqlDetalle = "SELECT 
                            md.cantidad,
                            md.costo_unitario,
                            md.precio_unitario,
                            md.ubicacion,
                            p.codigo,
                            p.descripcion,
                            p.unidad_medida
                       FROM movimiento_detalle md
                       INNER JOIN productos p ON md.producto_id = p.id
                       WHERE md.movimiento_id = :movimiento_id
                       ORDER BY md.id ASC";

        $stmtDetalle = $this->conn->prepare($sqlDetalle);
        $stmtDetalle->execute([
            ':movimiento_id' => $movimientoId
        ]);

        $movimiento['detalles'] = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

        return $movimiento;
    }

    public function historialSalidas(
        string $buscar = '',
        int $almacenId = 0,
        string $fechaInicio = '',
        string $fechaFinal = ''
    ): array {
        $sql = "SELECT 
                    m.id,
                    m.folio,
                    m.fecha,
                    m.referencia,
                    m.tipo_operacion,
                    m.observaciones,
                    a.nombre AS almacen_nombre,
                    u.nombre AS usuario_nombre,
                    COUNT(md.id) AS total_productos,
                    COALESCE(SUM(md.cantidad), 0) AS total_unidades,
                    COALESCE(SUM(md.cantidad * md.precio_unitario), 0) AS total
                FROM movimientos m
                LEFT JOIN almacenes a ON m.almacen_id = a.id
                INNER JOIN usuarios u ON m.usuario_id = u.id
                LEFT JOIN movimiento_detalle md ON m.id = md.movimiento_id
                WHERE m.tipo_movimiento = 'SALIDA'";

        $params = [];

        if ($buscar !== '') {
            $sql .= " AND (
                        m.folio LIKE :buscar
                        OR m.referencia LIKE :buscar
                        OR m.tipo_operacion LIKE :buscar
                        OR a.nombre LIKE :buscar
                        OR u.nombre LIKE :buscar
                        OR EXISTS (
                            SELECT 1
                            FROM movimiento_detalle md2
                            INNER JOIN productos p2 ON md2.producto_id = p2.id
                            WHERE md2.movimiento_id = m.id
                            AND (
                                p2.codigo LIKE :buscar
                                OR p2.codigo_barras LIKE :buscar
                                OR p2.descripcion LIKE :buscar
                            )
                        )
                    )";

            $params[':buscar'] = '%' . $buscar . '%';
        }

        if ($almacenId > 0) {
            $sql .= " AND m.almacen_id = :almacen_id";
            $params[':almacen_id'] = $almacenId;
        }

        if ($fechaInicio !== '') {
            $sql .= " AND m.fecha >= :fecha_inicio";
            $params[':fecha_inicio'] = $fechaInicio . ' 00:00:00';
        }

        if ($fechaFinal !== '') {
            $sql .= " AND m.fecha <= :fecha_final";
            $params[':fecha_final'] = $fechaFinal . ' 23:59:59';
        }

        $sql .= " GROUP BY 
                    m.id,
                    m.folio,
                    m.fecha,
                    m.referencia,
                    m.tipo_operacion,
                    m.observaciones,
                    a.nombre,
                    u.nombre
                  ORDER BY m.id DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function historialEntradas(
        string $buscar = '',
        int $almacenId = 0,
        string $fechaInicio = '',
        string $fechaFinal = ''
    ): array {
        $sql = "SELECT 
                    m.id,
                    m.folio,
                    m.fecha,
                    m.referencia,
                    m.observaciones,
                    a.nombre AS almacen_nombre,
                    pr.nombre AS proveedor_nombre,
                    u.nombre AS usuario_nombre,
                    COUNT(md.id) AS total_productos,
                    COALESCE(SUM(md.cantidad), 0) AS total_unidades,
                    COALESCE(SUM(md.cantidad * md.costo_unitario), 0) AS total
                FROM movimientos m
                LEFT JOIN almacenes a ON m.almacen_id = a.id
                LEFT JOIN proveedores pr ON m.proveedor_id = pr.id
                INNER JOIN usuarios u ON m.usuario_id = u.id
                LEFT JOIN movimiento_detalle md ON m.id = md.movimiento_id
                WHERE m.tipo_movimiento = 'ENTRADA'";

        $params = [];

        if ($buscar !== '') {
            $sql .= " AND (
                        m.folio LIKE :buscar
                        OR m.referencia LIKE :buscar
                        OR m.observaciones LIKE :buscar
                        OR a.nombre LIKE :buscar
                        OR pr.nombre LIKE :buscar
                        OR u.nombre LIKE :buscar
                        OR EXISTS (
                            SELECT 1
                            FROM movimiento_detalle md2
                            INNER JOIN productos p2 ON md2.producto_id = p2.id
                            WHERE md2.movimiento_id = m.id
                            AND (
                                p2.codigo LIKE :buscar
                                OR p2.codigo_barras LIKE :buscar
                                OR p2.descripcion LIKE :buscar
                            )
                        )
                    )";

            $params[':buscar'] = '%' . $buscar . '%';
        }

        if ($almacenId > 0) {
            $sql .= " AND m.almacen_id = :almacen_id";
            $params[':almacen_id'] = $almacenId;
        }

        if ($fechaInicio !== '') {
            $sql .= " AND DATE(m.fecha) >= :fecha_inicio";
            $params[':fecha_inicio'] = $fechaInicio;
        }

        if ($fechaFinal !== '') {
            $sql .= " AND DATE(m.fecha) <= :fecha_final";
            $params[':fecha_final'] = $fechaFinal;
        }

        $sql .= " GROUP BY 
                    m.id,
                    m.folio,
                    m.fecha,
                    m.referencia,
                    m.observaciones,
                    a.nombre,
                    pr.nombre,
                    u.nombre
                  ORDER BY m.id DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}