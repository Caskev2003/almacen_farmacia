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

                INNER JOIN producto_existencias pe
                    ON pe.producto_id = p.id
                    AND UPPER(COALESCE(pe.sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                    AND COALESCE(pe.existencia, 0) > 0
                    AND pe.ubicacion IS NOT NULL
                    AND TRIM(pe.ubicacion) != ''
                    AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')

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

                HAVING SUM(pe.existencia) > 0

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
                               AND UPPER(COALESCE(sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                               AND COALESCE(existencia, 0) > 0
                               AND ubicacion IS NOT NULL
                               AND TRIM(ubicacion) != ''
                               AND UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')
                               ORDER BY existencia ASC, ubicacion ASC";

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
            }
        }

        unset($producto);

        return $productos;
    }

    public function getProductosParaSalida(): array
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

                    COALESCE(
                        MAX(
                            CASE
                                WHEN UPPER(TRIM(COALESCE(pe.sucursal, ''))) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                                AND pe.ubicacion IS NOT NULL
                                AND TRIM(pe.ubicacion) != ''
                                AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')
                                AND COALESCE(pe.existencia, 0) > 0
                                THEN pe.ubicacion
                                ELSE NULL
                            END
                        ),
                        'SIN UBICACION'
                    ) AS ubicacion,

                    COALESCE(SUM(
                        CASE
                            WHEN UPPER(TRIM(COALESCE(pe.sucursal, ''))) COLLATE utf8mb4_general_ci = UPPER(:sucursal2)
                            THEN COALESCE(pe.existencia, 0)
                            ELSE 0
                        END
                    ), 0) AS existencia_actual,

                    COALESCE(SUM(
                        CASE
                            WHEN UPPER(TRIM(COALESCE(pe.sucursal, ''))) COLLATE utf8mb4_general_ci = UPPER(:sucursal3)
                            THEN COALESCE(pe.existencia, 0)
                            ELSE 0
                        END
                    ), 0) AS existencia_bodega

                FROM productos p

                LEFT JOIN producto_existencias pe
                    ON pe.producto_id = p.id

                WHERE p.estado = 1

                GROUP BY 
                    p.id,
                    p.codigo,
                    p.codigo_barras,
                    p.descripcion,
                    p.precio_compra,
                    p.precio_venta,
                    p.unidad_medida

                ORDER BY p.descripcion ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':sucursal' => $sucursal,
            ':sucursal2' => $sucursal,
            ':sucursal3' => $sucursal
        ]);

        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($productos as &$producto) {
            $productoId = (int)$producto['id'];

            $sqlUbicaciones = "SELECT 
                                    COALESCE(NULLIF(TRIM(ubicacion), ''), 'SIN UBICACION') AS ubicacion,
                                    COALESCE(existencia, 0) AS existencia_actual
                               FROM producto_existencias
                               WHERE producto_id = :producto_id
                               AND UPPER(TRIM(COALESCE(sucursal, ''))) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                               AND ubicacion IS NOT NULL
                               AND TRIM(ubicacion) != ''
                               AND UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')
                               ORDER BY 
                                    CASE WHEN COALESCE(existencia, 0) > 0 THEN 0 ELSE 1 END,
                                    existencia ASC,
                                    ubicacion ASC";

            $stmtUbicaciones = $this->conn->prepare($sqlUbicaciones);
            $stmtUbicaciones->execute([
                ':producto_id' => $productoId,
                ':sucursal' => $sucursal
            ]);

            $producto['ubicaciones'] = $stmtUbicaciones->fetchAll(PDO::FETCH_ASSOC);

            if (empty($producto['ubicaciones'])) {
                $producto['ubicaciones'] = [[
                    'ubicacion' => 'SIN UBICACION',
                    'existencia_actual' => 0
                ]];

                $producto['ubicacion'] = 'SIN UBICACION';
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
        $sucursal = strtoupper(trim($sucursal));
        $ubicacion = $this->limpiarUbicacion($ubicacion);

        if ($productoId <= 0 || $sucursal === '' || $cantidad <= 0) {
            return;
        }

        $sqlExisteExacta = "SELECT id
                            FROM producto_existencias
                            WHERE producto_id = :producto_id
                            AND UPPER(TRIM(sucursal)) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                            AND UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci = UPPER(:ubicacion)
                            LIMIT 1";

        $stmtExisteExacta = $this->conn->prepare($sqlExisteExacta);
        $stmtExisteExacta->execute([
            ':producto_id' => $productoId,
            ':sucursal' => $sucursal,
            ':ubicacion' => $ubicacion
        ]);

        $existenciaExacta = $stmtExisteExacta->fetch(PDO::FETCH_ASSOC);

        if ($existenciaExacta) {
            $sqlUpdate = "UPDATE producto_existencias
                          SET existencia = COALESCE(existencia, 0) + :cantidad,
                              sucursal = :sucursal,
                              ubicacion = :ubicacion,
                              updated_at = CURRENT_TIMESTAMP
                          WHERE id = :id";

            $stmtUpdate = $this->conn->prepare($sqlUpdate);
            $stmtUpdate->execute([
                ':cantidad' => $cantidad,
                ':sucursal' => $sucursal,
                ':ubicacion' => $ubicacion,
                ':id' => $existenciaExacta['id']
            ]);

            return;
        }

        $sqlSinUbicacion = "SELECT id
                            FROM producto_existencias
                            WHERE producto_id = :producto_id
                            AND UPPER(TRIM(sucursal)) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                            AND (
                                ubicacion IS NULL
                                OR TRIM(ubicacion) = ''
                                OR UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci IN ('SIN UBICACION', 'SIN UBICACIÓN')
                            )
                            AND COALESCE(existencia, 0) <= 0
                            ORDER BY id ASC
                            LIMIT 1";

        $stmtSinUbicacion = $this->conn->prepare($sqlSinUbicacion);
        $stmtSinUbicacion->execute([
            ':producto_id' => $productoId,
            ':sucursal' => $sucursal
        ]);

        $sinUbicacion = $stmtSinUbicacion->fetch(PDO::FETCH_ASSOC);

        if ($sinUbicacion && $ubicacion !== 'SIN UBICACION') {
            $sqlUpdateSin = "UPDATE producto_existencias
                             SET sucursal = :sucursal,
                                 ubicacion = :ubicacion,
                                 existencia = :cantidad,
                                 updated_at = CURRENT_TIMESTAMP
                             WHERE id = :id";

            $stmtUpdateSin = $this->conn->prepare($sqlUpdateSin);
            $stmtUpdateSin->execute([
                ':sucursal' => $sucursal,
                ':ubicacion' => $ubicacion,
                ':cantidad' => $cantidad,
                ':id' => $sinUbicacion['id']
            ]);

            return;
        }

        $sqlInsert = "INSERT INTO producto_existencias (
                        producto_id,
                        sucursal,
                        ubicacion,
                        existencia
                    ) VALUES (
                        :producto_id,
                        :sucursal,
                        :ubicacion,
                        :cantidad
                    )";

        $stmtInsert = $this->conn->prepare($sqlInsert);
        $stmtInsert->execute([
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
                SET existencia = GREATEST(existencia - :cantidad, 0),
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

    private function marcarUbicacionAgotadaSinEliminar(
        int $productoId,
        string $sucursal,
        string $ubicacion = ''
    ): void {
        $ubicacion = $this->limpiarUbicacion($ubicacion);

        $sqlActual = "SELECT id, existencia
                      FROM producto_existencias
                      WHERE producto_id = :producto_id
                      AND sucursal = :sucursal
                      AND ubicacion = :ubicacion
                      LIMIT 1";

        $stmtActual = $this->conn->prepare($sqlActual);
        $stmtActual->execute([
            ':producto_id' => $productoId,
            ':sucursal' => $sucursal,
            ':ubicacion' => $ubicacion
        ]);

        $actual = $stmtActual->fetch(PDO::FETCH_ASSOC);

        if (!$actual) {
            return;
        }

        if ((int)$actual['existencia'] > 0) {
            return;
        }

        if ($ubicacion === 'SIN UBICACION') {
            $sqlUpdateMisma = "UPDATE producto_existencias
                               SET existencia = 0,
                                   ubicacion = 'SIN UBICACION',
                                   updated_at = CURRENT_TIMESTAMP
                               WHERE id = :id";

            $stmtUpdateMisma = $this->conn->prepare($sqlUpdateMisma);
            $stmtUpdateMisma->execute([
                ':id' => $actual['id']
            ]);

            return;
        }

        $sqlExisteSin = "SELECT id
                         FROM producto_existencias
                         WHERE producto_id = :producto_id
                         AND sucursal = :sucursal
                         AND ubicacion IN ('SIN UBICACION', 'SIN UBICACIÓN')
                         LIMIT 1";

        $stmtExisteSin = $this->conn->prepare($sqlExisteSin);
        $stmtExisteSin->execute([
            ':producto_id' => $productoId,
            ':sucursal' => $sucursal
        ]);

        $sinUbicacion = $stmtExisteSin->fetch(PDO::FETCH_ASSOC);

        if ($sinUbicacion) {
            $sqlUpdateSin = "UPDATE producto_existencias
                             SET existencia = 0,
                                 ubicacion = 'SIN UBICACION',
                                 updated_at = CURRENT_TIMESTAMP
                             WHERE id = :id";

            $stmtUpdateSin = $this->conn->prepare($sqlUpdateSin);
            $stmtUpdateSin->execute([
                ':id' => $sinUbicacion['id']
            ]);

            $sqlEliminarDuplicadaVacia = "DELETE FROM producto_existencias
                                          WHERE id = :id
                                          AND existencia <= 0";

            $stmtEliminarDuplicadaVacia = $this->conn->prepare($sqlEliminarDuplicadaVacia);
            $stmtEliminarDuplicadaVacia->execute([
                ':id' => $actual['id']
            ]);
        } else {
            $sqlUpdateActual = "UPDATE producto_existencias
                                SET existencia = 0,
                                    ubicacion = 'SIN UBICACION',
                                    updated_at = CURRENT_TIMESTAMP
                                WHERE id = :id";

            $stmtUpdateActual = $this->conn->prepare($sqlUpdateActual);
            $stmtUpdateActual->execute([
                ':id' => $actual['id']
            ]);
        }
    }

    private function actualizarProductoSiQuedoSinStock(
        int $productoId,
        string $sucursal
    ): void {
        $sql = "SELECT COALESCE(SUM(existencia), 0)
                FROM producto_existencias
                WHERE producto_id = :producto_id
                AND sucursal = :sucursal";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':producto_id' => $productoId,
            ':sucursal' => $sucursal
        ]);

        $total = (int)$stmt->fetchColumn();

        if ($total <= 0) {
            $this->actualizarUbicacionPrincipalProducto($productoId, 'SIN UBICACION');
        }
    }

    public function getProductosCatalogo(): array
    {
        $sql = "SELECT 
                    p.id,
                    p.codigo,
                    p.codigo_barras,
                    p.descripcion,
                    p.precio_compra,
                    p.precio_venta,
                    p.unidad_medida,
                    COALESCE(NULLIF(TRIM(p.ubicacion), ''), 'SIN UBICACION') AS ubicacion,
                    0 AS existencia_actual,
                    0 AS existencia_bodega
                FROM productos p
                WHERE p.estado = 1
                ORDER BY p.descripcion ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                AND UPPER(COALESCE(sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                AND COALESCE(existencia, 0) > 0
                AND ubicacion IS NOT NULL
                AND TRIM(ubicacion) != ''
                AND UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')
                ORDER BY 
                    CASE 
                        WHEN UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci = UPPER(:ubicacion_preferida) THEN 0
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

                if ($cantidad <= 0) {
                    throw new Exception('La cantidad debe ser mayor a 0.');
                }

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

                    $this->marcarUbicacionAgotadaSinEliminar(
                        $productoId,
                        $sucursal,
                        $ubicacionActual
                    );

                    $this->actualizarProductoSiQuedoSinStock(
                        $productoId,
                        $sucursal
                    );

                    if ($cantidadADescontar < $existenciaUbicacion) {
                        $this->actualizarUbicacionPrincipalProducto(
                            $productoId,
                            $ubicacionActual
                        );
                    }

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
                m.almacen_id,
                m.referencia,
                m.observaciones,
                m.cancelado,
                m.fecha_cancelacion,
                m.motivo_cancelacion,
                a.nombre AS almacen_nombre,
                pr.nombre AS proveedor,
                pr.nombre AS proveedor_nombre,
                u.nombre AS usuario_nombre
            FROM movimientos m
            LEFT JOIN almacenes a ON m.almacen_id = a.id
            LEFT JOIN proveedores pr ON m.proveedor_id = pr.id
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
                        md.producto_id,
                        md.cantidad,
                        md.costo_unitario,
                        md.precio_unitario,
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
    private function obtenerSolicitudOrigenSalida(
        array $movimiento
    ): ?array {
        $movimientoId = (int) (
            $movimiento['id'] ?? 0
        );

        if ($movimientoId <= 0) {
            return null;
        }

        $camposSolicitud = "
            SELECT
                r.id AS solicitud_origen_id,
                r.folio AS solicitud_folio,
                r.tipo_solicitud AS solicitud_tipo,
                r.folio_documento AS solicitud_folio_documento,
                r.verificador_id AS solicitud_verificador_id,
                COALESCE(
                    NULLIF(TRIM(r.verificador_nombre), ''),
                    NULLIF(TRIM(vr.nombre), '')
                ) AS solicitud_verificador_nombre,
                u.nombre AS solicitud_gerente_nombre
            FROM resurtidos AS r
            LEFT JOIN verificadores_resurtido AS vr
                ON vr.id = r.verificador_id
            LEFT JOIN usuarios AS u
                ON u.id = r.solicitante_id
        ";

        /*
         * Es la relación principal para las salidas normales y
         * para la última salida generada por una solicitud parcial.
         */
        $sqlPorSalida = $camposSolicitud . "
            WHERE r.salida_id = :salida_id
            ORDER BY r.id DESC
            LIMIT 1
        ";

        $stmtPorSalida = $this->conn->prepare(
            $sqlPorSalida
        );

        $stmtPorSalida->execute([
            ':salida_id' => $movimientoId
        ]);

        $solicitud = $stmtPorSalida->fetch(
            PDO::FETCH_ASSOC
        );

        if ($solicitud) {
            return $solicitud;
        }

        /*
         * En salidas históricas o parciales la columna salida_id
         * puede apuntar a una salida posterior. El folio guardado
         * en Observaciones permite recuperar la solicitud original
         * y conservar el nombre al reimprimir.
         */
        $tipoOperacion = strtoupper(
            trim((string) (
                $movimiento['tipo_operacion'] ?? ''
            ))
        );

        if (!in_array(
            $tipoOperacion,
            ['RESURTIDO', 'TICKET'],
            true
        )) {
            return null;
        }

        $observaciones = (string) (
            $movimiento['observaciones'] ?? ''
        );

        $etiquetaFolio = $tipoOperacion === 'TICKET'
            ? 'ticket'
            : 'resurtido';

        $patronFolio = '/(?:^|\|)\s*Folio\s+'
            . preg_quote($etiquetaFolio, '/')
            . '\s*:\s*([^|]+)/iu';

        if (!preg_match(
            $patronFolio,
            $observaciones,
            $coincidencia
        )) {
            return null;
        }

        $folioSolicitud = strtoupper(
            trim((string) ($coincidencia[1] ?? ''))
        );

        $almacenId = (int) (
            $movimiento['almacen_id'] ?? 0
        );

        if ($folioSolicitud === '' || $almacenId <= 0) {
            return null;
        }

        if ($tipoOperacion === 'TICKET') {
            $condicionFolio = "
                AND (
                    UPPER(TRIM(COALESCE(r.folio_documento, '')))
                        = :folio_solicitud
                    OR UPPER(TRIM(r.folio))
                        = :folio_solicitud
                )
            ";
        } else {
            $condicionFolio = "
                AND UPPER(TRIM(r.folio))
                    = :folio_solicitud
            ";
        }

        $sqlPorFolio = $camposSolicitud . "
            WHERE
                r.almacen_id = :almacen_id
                AND UPPER(TRIM(r.tipo_solicitud))
                    = :tipo_solicitud
                {$condicionFolio}
            ORDER BY r.id DESC
            LIMIT 1
        ";

        $stmtPorFolio = $this->conn->prepare(
            $sqlPorFolio
        );

        $stmtPorFolio->execute([
            ':almacen_id' => $almacenId,
            ':tipo_solicitud' => $tipoOperacion,
            ':folio_solicitud' => $folioSolicitud
        ]);

        $solicitud = $stmtPorFolio->fetch(
            PDO::FETCH_ASSOC
        );

        return $solicitud ?: null;
    }

    public function obtenerSalidaPorId(int $movimientoId): ?array
    {
        $sql = "SELECT 
                    m.id,
                    m.folio,
                    m.fecha,
                    m.tipo_movimiento,
                    m.almacen_id,
                    m.referencia,
                    m.tipo_operacion,
                    m.observaciones,
                    m.cancelado,
                    m.fecha_cancelacion,
                    m.motivo_cancelacion,
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

        $solicitudOrigen =
            $this->obtenerSolicitudOrigenSalida(
                $movimiento
            );

        $movimiento['solicitud_origen_id'] = null;
        $movimiento['solicitud_folio'] = null;
        $movimiento['solicitud_tipo'] = null;
        $movimiento['solicitud_folio_documento'] = null;
        $movimiento['solicitud_verificador_id'] = null;
        $movimiento['solicitud_verificador_nombre'] = null;
        $movimiento['solicitud_gerente_nombre'] = null;

        if ($solicitudOrigen !== null) {
            foreach (
                $solicitudOrigen as $campo => $valor
            ) {
                $movimiento[$campo] = $valor;
            }
        }

        $sqlDetalle = "SELECT 
                            md.producto_id,
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

    /**
     * Obtiene en una sola consulta los productos de varias salidas.
     * Evita ejecutar obtenerSalidaPorId() cientos de veces al cargar
     * el historial completo.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function obtenerDetallesSalidas(
        array $movimientoIds
    ): array {
        $movimientoIds = array_values(
            array_unique(
                array_filter(
                    array_map('intval', $movimientoIds),
                    static fn (int $id): bool => $id > 0
                )
            )
        );

        if (empty($movimientoIds)) {
            return [];
        }

        $placeholders = [];
        $parametros = [];

        foreach ($movimientoIds as $indice => $movimientoId) {
            $placeholder = ':movimiento_' . $indice;
            $placeholders[] = $placeholder;
            $parametros[$placeholder] = $movimientoId;
        }

        $sql = "SELECT
                    md.movimiento_id,
                    md.producto_id,
                    md.cantidad,
                    md.costo_unitario,
                    md.precio_unitario,
                    md.ubicacion,
                    p.codigo,
                    p.descripcion,
                    p.unidad_medida
                FROM movimiento_detalle AS md
                INNER JOIN productos AS p
                    ON p.id = md.producto_id
                WHERE md.movimiento_id IN ("
                    . implode(', ', $placeholders)
                    . ")
                ORDER BY md.movimiento_id DESC, md.id ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($parametros);

        $detallesPorSalida = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $detalle) {
            $movimientoId = (int) (
                $detalle['movimiento_id'] ?? 0
            );

            if ($movimientoId <= 0) {
                continue;
            }

            $detalle['movimiento_id'] = $movimientoId;
            $detalle['producto_id'] = (int) (
                $detalle['producto_id'] ?? 0
            );
            $detalle['cantidad'] = (int) (
                $detalle['cantidad'] ?? 0
            );
            $detalle['costo_unitario'] = (float) (
                $detalle['costo_unitario'] ?? 0
            );
            $detalle['precio_unitario'] = (float) (
                $detalle['precio_unitario'] ?? 0
            );

            $detallesPorSalida[$movimientoId][] = $detalle;
        }

        return $detallesPorSalida;
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

                    m.cancelado,
                    m.fecha_cancelacion,
                    m.motivo_cancelacion,

                    a.nombre AS almacen_nombre,
                    u.nombre AS usuario_nombre,

                    COUNT(md.id) AS total_productos,
                    COALESCE(SUM(md.cantidad), 0) AS total_unidades,
                    COALESCE(SUM(md.cantidad * md.precio_unitario), 0) AS total

                FROM movimientos m

                LEFT JOIN almacenes a
                    ON m.almacen_id = a.id

                INNER JOIN usuarios u
                    ON m.usuario_id = u.id

                LEFT JOIN movimiento_detalle md
                    ON m.id = md.movimiento_id

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
                            INNER JOIN productos p2
                                ON md2.producto_id = p2.id
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

                    m.cancelado,
                    m.fecha_cancelacion,
                    m.motivo_cancelacion,

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
                m.cancelado,
                m.fecha_cancelacion,
                m.motivo_cancelacion,
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
                        AND (p2.codigo LIKE :buscar OR p2.codigo_barras LIKE :buscar OR p2.descripcion LIKE :buscar)
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
                m.cancelado,
                m.fecha_cancelacion,
                m.motivo_cancelacion,
                a.nombre,
                pr.nombre,
                u.nombre
              ORDER BY m.id DESC";

    $stmt = $this->conn->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
    private function obtenerExistenciaUbicacion(int $productoId, string $sucursal, string $ubicacion): int
    {
        $ubicacion = $this->limpiarUbicacion($ubicacion);

        $sql = "SELECT COALESCE(existencia, 0)
                FROM producto_existencias
                WHERE producto_id = :producto_id
                AND UPPER(TRIM(sucursal)) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                AND UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci = UPPER(:ubicacion)
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':producto_id' => $productoId,
            ':sucursal' => $sucursal,
            ':ubicacion' => $ubicacion
        ]);

        return (int)$stmt->fetchColumn();
    }

    public function cancelarSalida(int $movimientoId, int $usuarioId, string $motivo = ''): array
    {
        try {
            $this->conn->beginTransaction();

            $sqlMov = "SELECT id, almacen_id, cancelado
                       FROM movimientos
                       WHERE id = :id
                       AND tipo_movimiento = 'SALIDA'
                       LIMIT 1";

            $stmtMov = $this->conn->prepare($sqlMov);
            $stmtMov->execute([':id' => $movimientoId]);
            $movimiento = $stmtMov->fetch(PDO::FETCH_ASSOC);

            if (!$movimiento) {
                throw new Exception('La salida no existe.');
            }

            if ((int)$movimiento['cancelado'] === 1) {
                throw new Exception('Esta salida ya fue cancelada.');
            }

            $sucursal = $this->obtenerSucursalPorAlmacenId((int)$movimiento['almacen_id']);

            $sqlDetalle = "SELECT producto_id, cantidad, ubicacion
                           FROM movimiento_detalle
                           WHERE movimiento_id = :movimiento_id";

            $stmtDetalle = $this->conn->prepare($sqlDetalle);
            $stmtDetalle->execute([':movimiento_id' => $movimientoId]);
            $detalles = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

            foreach ($detalles as $detalle) {
                $productoId = (int)$detalle['producto_id'];
                $cantidad = (int)$detalle['cantidad'];
                $ubicacion = $this->limpiarUbicacion($detalle['ubicacion'] ?? '');

                $this->aumentarExistencia($productoId, $sucursal, $cantidad, $ubicacion);
                $this->actualizarUbicacionPrincipalProducto($productoId, $ubicacion);
            }

            $sqlCancelar = "UPDATE movimientos
                            SET cancelado = 1,
                                fecha_cancelacion = NOW(),
                                usuario_cancelacion_id = :usuario_id,
                                motivo_cancelacion = :motivo
                            WHERE id = :id";

            $stmtCancelar = $this->conn->prepare($sqlCancelar);
            $stmtCancelar->execute([
                ':usuario_id' => $usuarioId,
                ':motivo' => $motivo,
                ':id' => $movimientoId
            ]);

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Salida cancelada correctamente. El stock fue regresado a sus ubicaciones.'
            ];

        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function cancelarEntrada(int $movimientoId, int $usuarioId, string $motivo = ''): array
    {
        try {
            $this->conn->beginTransaction();

            $sqlMov = "SELECT id, almacen_id, cancelado
                       FROM movimientos
                       WHERE id = :id
                       AND tipo_movimiento = 'ENTRADA'
                       LIMIT 1";

            $stmtMov = $this->conn->prepare($sqlMov);
            $stmtMov->execute([':id' => $movimientoId]);
            $movimiento = $stmtMov->fetch(PDO::FETCH_ASSOC);

            if (!$movimiento) {
                throw new Exception('La entrada no existe.');
            }

            if ((int)$movimiento['cancelado'] === 1) {
                throw new Exception('Esta entrada ya fue cancelada.');
            }

            $sucursal = $this->obtenerSucursalPorAlmacenId((int)$movimiento['almacen_id']);

            $sqlDetalle = "SELECT producto_id, lote_id, cantidad, ubicacion
                           FROM movimiento_detalle
                           WHERE movimiento_id = :movimiento_id";

            $stmtDetalle = $this->conn->prepare($sqlDetalle);
            $stmtDetalle->execute([':movimiento_id' => $movimientoId]);
            $detalles = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

            foreach ($detalles as $detalle) {
                $productoId = (int)$detalle['producto_id'];
                $cantidad = (int)$detalle['cantidad'];
                $ubicacion = $this->limpiarUbicacion($detalle['ubicacion'] ?? '');

                $existenciaActual = $this->obtenerExistenciaUbicacion($productoId, $sucursal, $ubicacion);

                if ($existenciaActual < $cantidad) {
                    throw new Exception(
                        'No se puede cancelar la entrada porque ya se usó parte del stock en la ubicación: ' . $ubicacion
                    );
                }
            }

            foreach ($detalles as $detalle) {
                $productoId = (int)$detalle['producto_id'];
                $loteId = (int)($detalle['lote_id'] ?? 0);
                $cantidad = (int)$detalle['cantidad'];
                $ubicacion = $this->limpiarUbicacion($detalle['ubicacion'] ?? '');

                $this->disminuirExistencia($productoId, $sucursal, $cantidad, $ubicacion);
                $this->marcarUbicacionAgotadaSinEliminar($productoId, $sucursal, $ubicacion);
                $this->actualizarProductoSiQuedoSinStock($productoId, $sucursal);

                if ($loteId > 0) {
                    $sqlLote = "UPDATE lotes
                                SET existencia = GREATEST(existencia - :cantidad, 0)
                                WHERE id = :lote_id";

                    $stmtLote = $this->conn->prepare($sqlLote);
                    $stmtLote->execute([
                        ':cantidad' => $cantidad,
                        ':lote_id' => $loteId
                    ]);
                }
            }

            $sqlCancelar = "UPDATE movimientos
                            SET cancelado = 1,
                                fecha_cancelacion = NOW(),
                                usuario_cancelacion_id = :usuario_id,
                                motivo_cancelacion = :motivo
                            WHERE id = :id";

            $stmtCancelar = $this->conn->prepare($sqlCancelar);
            $stmtCancelar->execute([
                ':usuario_id' => $usuarioId,
                ':motivo' => $motivo,
                ':id' => $movimientoId
            ]);

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Entrada cancelada correctamente. El stock fue descontado.'
            ];

        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function editarSalida(int $movimientoId, array $data, array $detalle, int $usuarioId): array
    {
        try {
            $this->conn->beginTransaction();

            $sqlMov = "SELECT id, folio, almacen_id, cancelado
                       FROM movimientos
                       WHERE id = :id
                       AND tipo_movimiento = 'SALIDA'
                       LIMIT 1
                       FOR UPDATE";

            $stmtMov = $this->conn->prepare($sqlMov);
            $stmtMov->execute([':id' => $movimientoId]);
            $movimiento = $stmtMov->fetch(PDO::FETCH_ASSOC);

            if (!$movimiento) {
                throw new Exception('La salida no existe.');
            }

            if ((int)$movimiento['cancelado'] === 1) {
                throw new Exception('No puedes editar una salida cancelada.');
            }

            $almacenId = (int)$movimiento['almacen_id'];
            $sucursal = $this->obtenerSucursalPorAlmacenId($almacenId);

            if ($sucursal === '') {
                throw new Exception('No se pudo identificar la sucursal.');
            }

            $sqlDetalleOriginal = "SELECT producto_id, cantidad, ubicacion
                                   FROM movimiento_detalle
                                   WHERE movimiento_id = :movimiento_id";

            $stmtDetalleOriginal = $this->conn->prepare($sqlDetalleOriginal);
            $stmtDetalleOriginal->execute([':movimiento_id' => $movimientoId]);
            $detallesOriginales = $stmtDetalleOriginal->fetchAll(PDO::FETCH_ASSOC);

            foreach ($detallesOriginales as $item) {
                $productoId = (int)$item['producto_id'];
                $cantidad = (int)$item['cantidad'];
                $ubicacion = $this->limpiarUbicacion($item['ubicacion'] ?? '');

                $this->aumentarExistencia($productoId, $sucursal, $cantidad, $ubicacion);
                $this->actualizarUbicacionPrincipalProducto($productoId, $ubicacion);
            }

            $sqlDeleteDetalle = "DELETE FROM movimiento_detalle
                                 WHERE movimiento_id = :movimiento_id";

            $stmtDeleteDetalle = $this->conn->prepare($sqlDeleteDetalle);
            $stmtDeleteDetalle->execute([':movimiento_id' => $movimientoId]);

            $sqlUpdateMov = "UPDATE movimientos
                             SET fecha = :fecha,
                                 almacen_id = :almacen_id,
                                 usuario_id = :usuario_id,
                                 referencia = :referencia,
                                 tipo_operacion = :tipo_operacion,
                                 observaciones = :observaciones
                             WHERE id = :id";

            $stmtUpdateMov = $this->conn->prepare($sqlUpdateMov);
            $stmtUpdateMov->execute([
                ':fecha' => $data['fecha'],
                ':almacen_id' => $almacenId,
                ':usuario_id' => $usuarioId,
                ':referencia' => $data['referencia'] ?: null,
                ':tipo_operacion' => $data['tipo_operacion'] ?: null,
                ':observaciones' => $data['observaciones'] ?: null,
                ':id' => $movimientoId
            ]);

            $sqlProducto = "SELECT id, descripcion, precio_compra, precio_venta, ubicacion
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

                $stmtProducto->execute([':id' => $productoId]);
                $producto = $stmtProducto->fetch(PDO::FETCH_ASSOC);

                if (!$producto) {
                    throw new Exception('Producto no encontrado.');
                }

                if ($ubicacionPreferida === 'SIN UBICACION') {
                    throw new Exception('Debes seleccionar una ubicación válida para: ' . $producto['descripcion']);
                }

                if ($cantidadSolicitada <= 0) {
                    throw new Exception('La cantidad debe ser mayor a 0.');
                }

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
                        'Stock insuficiente para ' . $producto['descripcion'] .
                        '. Solicitado: ' . $cantidadSolicitada .
                        '. Disponible: ' . $existenciaTotal
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
                        ':ubicacion' => $ubicacionActual
                    ]);

                    $this->disminuirExistencia($productoId, $sucursal, $cantidadADescontar, $ubicacionActual);
                    $this->marcarUbicacionAgotadaSinEliminar($productoId, $sucursal, $ubicacionActual);
                    $this->actualizarProductoSiQuedoSinStock($productoId, $sucursal);

                    $cantidadPendiente -= $cantidadADescontar;
                }
            }

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Salida actualizada correctamente.',
                'movimiento_id' => $movimientoId,
                'folio' => $movimiento['folio']
            ];

        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            return [
                'success' => false,
                'message' => 'Error al editar la salida: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obtiene todas las ubicaciones únicas para una sucursal/almacén
     * @param int|null $almacenId ID del almacén (opcional)
     * @return array Lista de ubicaciones
     */
    public function getTodasUbicacionesPorSucursal(?int $almacenId = null): array
    {
        $sesion = $this->obtenerAlmacenSesion();

        // Usar el almacén proporcionado o el de la sesión
        $almacenId = $almacenId !== null && $almacenId > 0
            ? (int)$almacenId
            : (int)$sesion['almacen_id'];

        $sucursal = $this->obtenerSucursalPorAlmacenId($almacenId);

        if ($sucursal === '') {
            return [];
        }

        $sql = "SELECT DISTINCT 
                    UPPER(TRIM(ubicacion)) AS ubicacion
                FROM producto_existencias
                WHERE UPPER(TRIM(COALESCE(sucursal, ''))) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                AND ubicacion IS NOT NULL
                AND TRIM(ubicacion) != ''
                AND UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')
                ORDER BY ubicacion ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':sucursal' => $sucursal
        ]);

        $resultados = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Si no hay ubicaciones, devolver un array con una por defecto
        if (empty($resultados)) {
            return ['ALMACEN_PRINCIPAL'];
        }
        
        return $resultados;
    }
    /**
 * ACTUALIZAR MOVIMIENTO Y SUS DETALLES (para edición de entradas)
 */
public function actualizarMovimiento(int $movimientoId, array $data, array $detalle): array
{
    try {
        $this->conn->beginTransaction();

        $almacenId = (int)($data['almacen_id'] ?? 0);
        $sucursal = $this->obtenerSucursalPorAlmacenId($almacenId);

        if ($sucursal === '') {
            throw new Exception('No se pudo identificar la sucursal del almacén.');
        }

        // Obtener detalles originales para revertir inventario
        $sqlDetalleOriginal = "SELECT producto_id, cantidad, ubicacion, lote_id
                               FROM movimiento_detalle
                               WHERE movimiento_id = :movimiento_id";

        $stmtDetalleOriginal = $this->conn->prepare($sqlDetalleOriginal);
        $stmtDetalleOriginal->execute([':movimiento_id' => $movimientoId]);
        $detallesOriginales = $stmtDetalleOriginal->fetchAll(PDO::FETCH_ASSOC);

        // Revertir inventario (restar lo que se sumó en la entrada)
        foreach ($detallesOriginales as $item) {
            $productoId = (int)$item['producto_id'];
            $cantidad = (int)$item['cantidad'];
            $ubicacion = $this->limpiarUbicacion($item['ubicacion'] ?? '');

            // Restar existencia (revertir entrada)
            $this->disminuirExistencia($productoId, $sucursal, $cantidad, $ubicacion);
            $this->marcarUbicacionAgotadaSinEliminar($productoId, $sucursal, $ubicacion);
            $this->actualizarProductoSiQuedoSinStock($productoId, $sucursal);

            // Si tiene lote, revertir también
            $loteId = (int)($item['lote_id'] ?? 0);
            if ($loteId > 0) {
                $sqlLote = "UPDATE lotes
                            SET existencia = GREATEST(existencia - :cantidad, 0)
                            WHERE id = :lote_id";

                $stmtLote = $this->conn->prepare($sqlLote);
                $stmtLote->execute([
                    ':cantidad' => $cantidad,
                    ':lote_id' => $loteId
                ]);
            }
        }

        // Eliminar detalles antiguos
        $sqlDeleteDetalle = "DELETE FROM movimiento_detalle
                             WHERE movimiento_id = :movimiento_id";

        $stmtDeleteDetalle = $this->conn->prepare($sqlDeleteDetalle);
        $stmtDeleteDetalle->execute([':movimiento_id' => $movimientoId]);

        // Actualizar movimiento
        $sqlUpdateMov = "UPDATE movimientos
                         SET fecha = :fecha,
                             almacen_id = :almacen_id,
                             usuario_id = :usuario_id,
                             proveedor_id = :proveedor_id,
                             referencia = :referencia,
                             observaciones = :observaciones
                         WHERE id = :id";

        $stmtUpdateMov = $this->conn->prepare($sqlUpdateMov);
        $stmtUpdateMov->execute([
            ':fecha' => $data['fecha'],
            ':almacen_id' => $almacenId,
            ':usuario_id' => $data['usuario_id'],
            ':proveedor_id' => $data['proveedor_id'] ?: null,
            ':referencia' => $data['referencia'] ?: null,
            ':observaciones' => $data['observaciones'] ?: null,
            ':id' => $movimientoId
        ]);

        // Insertar nuevos detalles
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

            if ($cantidad <= 0) {
                throw new Exception('La cantidad debe ser mayor a 0.');
            }

            // Crear lote si tiene número
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

            // Sumar existencia (es una entrada)
            $this->aumentarExistencia($productoId, $sucursal, $cantidad, $ubicacion);

            $stmtActualizarProducto->execute([
                ':precio_compra' => $item['costo_unitario'],
                ':ubicacion' => $ubicacion,
                ':producto_id' => $productoId,
            ]);
        }

        $this->conn->commit();

        return [
            'success' => true,
            'message' => 'Entrada actualizada correctamente.',
            'folio' => $data['folio'] ?? '',
            'movimiento_id' => $movimientoId
        ];

    } catch (Throwable $e) {
        if ($this->conn->inTransaction()) {
            $this->conn->rollBack();
        }

        return [
            'success' => false,
            'message' => 'Error al actualizar: ' . $e->getMessage()
        ];
    }
}

public function generarKardex(
    int $productoId,
    int $almacenId = 0,
    string $fechaInicio = '',
    string $fechaFinal = ''
): array {
    if ($productoId <= 0) {
        return [];
    }

    $params = [
        ':producto_id' => $productoId
    ];

    $sql = "SELECT
                m.id,
                m.fecha,
                m.folio,
                m.tipo_movimiento,
                m.referencia,
                m.tipo_operacion,
                m.observaciones,
                a.nombre AS almacen_nombre,
                u.nombre AS usuario_nombre,
                md.cantidad,
                md.ubicacion
            FROM movimientos m
            INNER JOIN movimiento_detalle md
                ON m.id = md.movimiento_id
            LEFT JOIN almacenes a
                ON m.almacen_id = a.id
            INNER JOIN usuarios u
                ON m.usuario_id = u.id
            WHERE md.producto_id = :producto_id
            AND COALESCE(m.cancelado, 0) = 0";

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

    $sql .= " ORDER BY m.fecha ASC, m.id ASC, md.id ASC";

    $stmt = $this->conn->prepare($sql);
    $stmt->execute($params);

    $movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $kardex = [];
    $saldo = 0;

    foreach ($movimientos as $mov) {
        $cantidad = (int)$mov['cantidad'];
        $tipo = strtoupper(trim($mov['tipo_movimiento'] ?? ''));

        $inventarioInicial = $saldo;

        if ($tipo === 'ENTRADA') {
            $saldo += $cantidad;
            $efecto = '+';
        } elseif ($tipo === 'SALIDA') {
            $saldo -= $cantidad;
            if ($saldo < 0) {
                $saldo = 0;
            }
            $efecto = '-';
        } else {
            $efecto = '';
        }

        $kardex[] = [
            'fecha' => $mov['fecha'],
            'folio' => $mov['folio'],
            'tipo_movimiento' => $tipo,
            'almacen_afectado' => $mov['almacen_nombre'] ?? '',
            'almacen_destino' => $mov['tipo_operacion'] ?: ($mov['referencia'] ?? ''),
            'inventario_inicial' => $inventarioInicial,
            'cantidad' => $cantidad,
            'inventario_final' => $saldo,
            'efecto' => $efecto,
            'notas' => trim(($mov['observaciones'] ?? '') . ' ' . ($mov['ubicacion'] ?? '')),
            'usuario' => $mov['usuario_nombre'] ?? ''
        ];
    }

    return $kardex;
}
}
