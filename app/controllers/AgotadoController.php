<?php

require_once __DIR__ . '/../config/database.php';

class AgotadoController
{
    private PDO $conn;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $database = new Database();
        $this->conn = $database->connect();
    }

    private function esAdmin(): bool
    {
        $rol = strtoupper(trim($_SESSION['user']['rol'] ?? ''));
        return in_array($rol, ['ADMINISTRADOR', 'ADMIN'], true);
    }

    private function almacenIdSesion(): int
    {
        return (int)($_SESSION['user']['almacen_id'] ?? 0);
    }

    private function limpiarTexto(?string $texto): string
    {
        return strtoupper(trim((string)$texto));
    }

    private function limpiarUbicacion(?string $ubicacion): string
    {
        $ubicacion = $this->limpiarTexto($ubicacion);
        $ubicacion = str_replace('SIN UBICACIÓN', 'SIN UBICACION', $ubicacion);

        return $ubicacion !== '' ? $ubicacion : 'SIN UBICACION';
    }

    private function sucursalPorAlmacenId(int $almacenId): string
    {
        if ($almacenId === 1) {
            return 'CIUDAD HIDALGO';
        }

        if ($almacenId === 2 || $almacenId === 3) {
            return 'TUXTLA';
        }

        return '';
    }

    private function sucursalesPorAlmacen(int $almacenId): array
    {
        if ($almacenId === 1) {
            return ['CIUDAD HIDALGO', 'CD HIDALGO'];
        }

        if ($almacenId === 2 || $almacenId === 3) {
            return ['TUXTLA', 'TUXTLA GUTIERREZ', 'TUXTLA GUTIÉRREZ'];
        }

        return [];
    }

    private function sucursalSesion(): string
    {
        $almacenId = $this->almacenIdSesion();
        return $this->sucursalPorAlmacenId($almacenId);
    }

    private function aplicarFiltroSucursal(string &$sql, array &$params, int $almacenId): void
    {
        $sucursales = $this->sucursalesPorAlmacen($almacenId);

        if (empty($sucursales)) {
            return;
        }

        $marks = [];

        foreach ($sucursales as $i => $sucursal) {
            $key = ":sucursal_$i";
            $marks[] = $key;
            $params[$key] = $sucursal;
        }

        $sql .= " AND UPPER(COALESCE(pe.sucursal, '')) COLLATE utf8mb4_general_ci IN (" . implode(',', $marks) . ") ";
    }

    public function almacenes(): array
    {
        $sql = "SELECT id, nombre 
                FROM almacenes 
                WHERE estado = 1 
                ORDER BY nombre ASC";

        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function subqueryInventario(array &$params, int $almacenId, bool $incluirSinAlmacen = true): string
    {
        $subquery = "SELECT 
                        pe.producto_id,

                        SUM(COALESCE(pe.existencia, 0)) AS existencia_total,

                        GROUP_CONCAT(
                            DISTINCT COALESCE(NULLIF(TRIM(pe.ubicacion), ''), 'SIN UBICACION')
                            ORDER BY pe.ubicacion ASC
                            SEPARATOR ', '
                        ) AS ubicaciones,

                        GROUP_CONCAT(
                            DISTINCT COALESCE(NULLIF(TRIM(pe.sucursal), ''), 'SIN ALMACEN')
                            ORDER BY pe.sucursal ASC
                            SEPARATOR ', '
                        ) AS sucursales,

                        MIN(NULLIF(TRIM(pe.sucursal), '')) AS sucursal,

                        SUM(
                            CASE 
                                WHEN pe.sucursal IS NOT NULL 
                                AND TRIM(pe.sucursal) != ''
                                THEN 1 
                                ELSE 0 
                            END
                        ) AS filas_con_sucursal,

                        SUM(
                            CASE 
                                WHEN pe.sucursal IS NOT NULL 
                                AND TRIM(pe.sucursal) != ''
                                AND (
                                    pe.ubicacion IS NULL
                                    OR TRIM(pe.ubicacion) = ''
                                    OR UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci IN ('SIN UBICACION', 'SIN UBICACIÓN')
                                )
                                THEN 1 
                                ELSE 0 
                            END
                        ) AS filas_sin_ubicacion,

                        SUM(
                            CASE 
                                WHEN pe.sucursal IS NULL 
                                OR TRIM(pe.sucursal) = ''
                                THEN 1 
                                ELSE 0 
                            END
                        ) AS filas_sin_almacen

                    FROM producto_existencias pe
                    WHERE 1 = 1";

        $filtroAlmacen = trim($_GET['filtro_almacen'] ?? '');

if ($filtroAlmacen !== '') {
    $this->aplicarFiltroAlmacenAgotados($subquery, $params, $filtroAlmacen);
} elseif ($almacenId > 0) {
    $this->aplicarFiltroSucursal($subquery, $params, $almacenId);
}
        elseif (!$incluirSinAlmacen) {
            $subquery .= " AND pe.sucursal IS NOT NULL AND TRIM(pe.sucursal) != '' ";
        }

        $subquery .= " GROUP BY pe.producto_id";

        return $subquery;
    }

    public function listar(array $filtros): array
    {
        $params = [];

        $esAdmin = $this->esAdmin();
        $almacenId = (int)($filtros['almacen_id'] ?? 0);
        $tipo = trim($filtros['tipo'] ?? 'sin_ubicacion');

        $pagina = max((int)($filtros['pagina'] ?? 1), 1);
        $porPagina = max((int)($filtros['por_pagina'] ?? 25), 1);

        if (!$esAdmin) {
            $almacenId = $this->almacenIdSesion();
        }

        $subquery = $this->subqueryInventario($params, $almacenId, true);

        $sql = "SELECT
                    p.id,
                    p.codigo,
                    p.codigo_barras,
                    p.descripcion,
                    c.nombre AS categoria,
                    pr.nombre AS proveedor,
                    p.laboratorio,
                    p.unidad_medida,

                    COALESCE(stock.sucursal, 'SIN ALMACEN') AS sucursal,
                    COALESCE(stock.sucursales, 'SIN ALMACEN') AS sucursales,
                    COALESCE(stock.ubicaciones, 'SIN UBICACION') AS ubicacion,
                    COALESCE(stock.existencia_total, 0) AS existencia,

                    COALESCE(stock.filas_con_sucursal, 0) AS filas_con_sucursal,
                    COALESCE(stock.filas_sin_ubicacion, 0) AS filas_sin_ubicacion,
                    COALESCE(stock.filas_sin_almacen, 0) AS filas_sin_almacen,

                    CASE
                        WHEN stock.producto_id IS NULL
                             OR COALESCE(stock.filas_con_sucursal, 0) = 0
                        THEN 'SIN ALMACEN'

                        WHEN COALESCE(stock.existencia_total, 0) <= 0
                             AND COALESCE(stock.filas_sin_ubicacion, 0) > 0
                        THEN 'SIN UBICACIÓN Y SIN EXISTENCIA'

                        WHEN COALESCE(stock.existencia_total, 0) <= 0
                        THEN 'SIN EXISTENCIA'

                        WHEN COALESCE(stock.filas_sin_ubicacion, 0) > 0
                        THEN 'SIN UBICACIÓN'

                        ELSE 'NORMAL'
                    END AS motivo

                FROM productos p

                LEFT JOIN categorias c 
                    ON p.categoria_id = c.id

                LEFT JOIN proveedores pr 
                    ON p.proveedor_id = pr.id

                LEFT JOIN ({$subquery}) AS stock 
                    ON stock.producto_id = p.id

                WHERE p.estado = 1";

        if ($tipo === 'sin_ubicacion') {
            $sql .= " AND stock.producto_id IS NOT NULL
                      AND COALESCE(stock.filas_con_sucursal, 0) > 0
                      AND COALESCE(stock.filas_sin_ubicacion, 0) > 0";
        } elseif ($tipo === 'sin_existencia') {
            $sql .= " AND stock.producto_id IS NOT NULL
                      AND COALESCE(stock.filas_con_sucursal, 0) > 0
                      AND COALESCE(stock.existencia_total, 0) <= 0";
        } else {
            $sql .= " AND (
                        stock.producto_id IS NULL
                        OR COALESCE(stock.filas_con_sucursal, 0) = 0
                      )";
        }

        if (!empty($filtros['buscar'])) {
            $sql .= " AND (
                        p.codigo COLLATE utf8mb4_general_ci LIKE :buscar
                        OR p.codigo_barras COLLATE utf8mb4_general_ci LIKE :buscar
                        OR p.descripcion COLLATE utf8mb4_general_ci LIKE :buscar
                        OR c.nombre COLLATE utf8mb4_general_ci LIKE :buscar
                        OR pr.nombre COLLATE utf8mb4_general_ci LIKE :buscar
                        OR p.laboratorio COLLATE utf8mb4_general_ci LIKE :buscar
                        OR stock.ubicaciones COLLATE utf8mb4_general_ci LIKE :buscar
                        OR stock.sucursales COLLATE utf8mb4_general_ci LIKE :buscar
                        OR stock.sucursal COLLATE utf8mb4_general_ci LIKE :buscar
                    )";

            $params[':buscar'] = '%' . trim($filtros['buscar']) . '%';
        }

        $sqlCount = "SELECT COUNT(*) AS total FROM ({$sql}) AS tabla";

        $stmtCount = $this->conn->prepare($sqlCount);
        $stmtCount->execute($params);

        $total = (int)($stmtCount->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $offset = ($pagina - 1) * $porPagina;

        $sql .= " ORDER BY p.descripcion COLLATE utf8mb4_general_ci ASC
                  LIMIT {$offset}, {$porPagina}";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => $items,
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total_paginas' => max((int)ceil($total / $porPagina), 1),
        ];
    }

    public function resumen(array $filtros): array
    {
        $baseFiltros = $filtros;
        $baseFiltros['pagina'] = 1;
        $baseFiltros['por_pagina'] = 1;

        $baseFiltros['tipo'] = 'sin_ubicacion';
        $sinUbicacion = $this->listar($baseFiltros)['total'];

        $baseFiltros['tipo'] = 'sin_existencia';
        $sinExistencia = $this->listar($baseFiltros)['total'];

        $baseFiltros['tipo'] = 'ambas';
        $sinAlmacen = $this->listar($baseFiltros)['total'];

        $params = [];

        $almacenId = (int)($filtros['almacen_id'] ?? 0);

        if (!$this->esAdmin()) {
            $almacenId = $this->almacenIdSesion();
        }

        $subquery = $this->subqueryInventario($params, $almacenId, false);

        $sqlExistencias = "SELECT COUNT(*) AS total
                           FROM productos p
                           INNER JOIN ({$subquery}) stock
                               ON stock.producto_id = p.id
                           WHERE p.estado = 1
                           AND COALESCE(stock.filas_con_sucursal, 0) > 0
                           AND COALESCE(stock.existencia_total, 0) > 0
                           AND COALESCE(stock.filas_sin_ubicacion, 0) = 0";

        $stmtExistencias = $this->conn->prepare($sqlExistencias);
        $stmtExistencias->execute($params);

        $productosConExistencia = (int)($stmtExistencias->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $sqlInventarioTotal = "SELECT COUNT(*) AS total
                       FROM productos
                       WHERE estado = 1";

$stmtInventarioTotal = $this->conn->prepare($sqlInventarioTotal);
$stmtInventarioTotal->execute();

$inventarioTotal = (int)($stmtInventarioTotal->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

$agotadosTotal = $sinUbicacion + $sinExistencia + $sinAlmacen;

return [
    'sin_ubicacion' => $sinUbicacion,
    'sin_existencia' => $sinExistencia,
    'ambas' => $sinAlmacen,
    'sin_almacen' => $sinAlmacen,
    'agotados_total' => $agotadosTotal,
    'productos_con_existencia' => $productosConExistencia,
    'inventario_total' => $inventarioTotal,
];
    }


    private function aplicarFiltroAlmacenAgotados(string &$sql, array &$params, string $filtroAlmacen): void
{
    $filtroAlmacen = strtolower(trim($filtroAlmacen));

    if ($filtroAlmacen === 'sin_almacen') {
        $sql .= " AND (
            pe.sucursal IS NULL
            OR TRIM(pe.sucursal) = ''
        )";
        return;
    }

    if ($filtroAlmacen === 'ciudad_hidalgo') {
        $sql .= " AND UPPER(COALESCE(pe.sucursal, '')) COLLATE utf8mb4_general_ci 
                  IN ('CIUDAD HIDALGO', 'CD HIDALGO')";
        return;
    }

    if ($filtroAlmacen === 'tuxtla') {
        $sql .= " AND (
            pe.sucursal IS NULL
            OR TRIM(pe.sucursal) = ''
            OR UPPER(COALESCE(pe.sucursal, '')) COLLATE utf8mb4_general_ci 
               IN ('TUXTLA', 'TUXTLA GUTIERREZ', 'TUXTLA GUTIÉRREZ')
        )";
        return;
    }
}
    public function actualizarUbicacion(array $data): array
    {
        $productoId = (int)($data['producto_id'] ?? 0);
        $sucursal = $this->limpiarTexto($data['sucursal'] ?? '');
        $ubicacionNueva = $this->limpiarUbicacion($data['ubicacion_nueva'] ?? '');
        $existencia = (int)($data['existencia'] ?? 0);

        if ($productoId <= 0) {
            return [
                'success' => false,
                'message' => 'Producto inválido.'
            ];
        }

        if (!$this->esAdmin()) {
            $sucursal = $this->sucursalSesion();
        }

        if ($sucursal === '' || $sucursal === 'SIN ALMACEN') {
            $sucursal = $this->sucursalSesion();
        }

        if ($sucursal === '') {
            return [
                'success' => false,
                'message' => 'Sucursal inválida.'
            ];
        }

        if ($ubicacionNueva === 'SIN UBICACION') {
            return [
                'success' => false,
                'message' => 'Debes escribir una ubicación válida.'
            ];
        }

        if ($existencia <= 0) {
            return [
                'success' => false,
                'message' => 'La existencia debe ser mayor a 0 para asignar ubicación.'
            ];
        }

        try {
            $this->conn->beginTransaction();

            $sqlExisteUbicacion = "SELECT id
                                   FROM producto_existencias
                                   WHERE producto_id = :producto_id
                                   AND UPPER(COALESCE(sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                                   AND UPPER(COALESCE(ubicacion, '')) COLLATE utf8mb4_general_ci = UPPER(:ubicacion)
                                   LIMIT 1";

            $stmtExiste = $this->conn->prepare($sqlExisteUbicacion);
            $stmtExiste->execute([
                ':producto_id' => $productoId,
                ':sucursal' => $sucursal,
                ':ubicacion' => $ubicacionNueva
            ]);

            $existeUbicacion = $stmtExiste->fetch(PDO::FETCH_ASSOC);

            if ($existeUbicacion) {
                $sqlUpdate = "UPDATE producto_existencias
                              SET existencia = :existencia,
                                  ubicacion = :ubicacion,
                                  sucursal = :sucursal,
                                  updated_at = CURRENT_TIMESTAMP
                              WHERE id = :id";

                $stmtUpdate = $this->conn->prepare($sqlUpdate);
                $stmtUpdate->execute([
                    ':existencia' => $existencia,
                    ':ubicacion' => $ubicacionNueva,
                    ':sucursal' => $sucursal,
                    ':id' => $existeUbicacion['id']
                ]);
            } else {
                $sqlRegistroBase = "SELECT id
                                    FROM producto_existencias
                                    WHERE producto_id = :producto_id
                                    AND (
                                        sucursal IS NULL
                                        OR TRIM(sucursal) = ''
                                        OR UPPER(TRIM(sucursal)) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                                    )
                                    AND (
                                        existencia <= 0
                                        OR ubicacion IS NULL
                                        OR TRIM(ubicacion) = ''
                                        OR UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci IN ('SIN UBICACION', 'SIN UBICACIÓN')
                                    )
                                    ORDER BY 
                                        CASE 
                                            WHEN sucursal IS NULL OR TRIM(sucursal) = '' THEN 0 
                                            ELSE 1 
                                        END,
                                        id ASC
                                    LIMIT 1";

                $stmtBase = $this->conn->prepare($sqlRegistroBase);
                $stmtBase->execute([
                    ':producto_id' => $productoId,
                    ':sucursal' => $sucursal
                ]);

                $base = $stmtBase->fetch(PDO::FETCH_ASSOC);

                if ($base) {
                    $sqlUpdateBase = "UPDATE producto_existencias
                                      SET sucursal = :sucursal,
                                          ubicacion = :ubicacion,
                                          existencia = :existencia,
                                          updated_at = CURRENT_TIMESTAMP
                                      WHERE id = :id";

                    $stmtUpdateBase = $this->conn->prepare($sqlUpdateBase);
                    $stmtUpdateBase->execute([
                        ':sucursal' => $sucursal,
                        ':ubicacion' => $ubicacionNueva,
                        ':existencia' => $existencia,
                        ':id' => $base['id']
                    ]);
                } else {
                    $sqlInsert = "INSERT INTO producto_existencias (
                                    producto_id,
                                    sucursal,
                                    ubicacion,
                                    existencia
                                ) VALUES (
                                    :producto_id,
                                    :sucursal,
                                    :ubicacion,
                                    :existencia
                                )";

                    $stmtInsert = $this->conn->prepare($sqlInsert);
                    $stmtInsert->execute([
                        ':producto_id' => $productoId,
                        ':sucursal' => $sucursal,
                        ':ubicacion' => $ubicacionNueva,
                        ':existencia' => $existencia
                    ]);
                }
            }

            $sqlLimpiarDuplicados = "DELETE FROM producto_existencias
                                     WHERE producto_id = :producto_id
                                     AND UPPER(COALESCE(sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                                     AND (
                                        ubicacion IS NULL
                                        OR TRIM(ubicacion) = ''
                                        OR UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci IN ('SIN UBICACION', 'SIN UBICACIÓN')
                                     )
                                     AND existencia <= 0";

            $stmtLimpiar = $this->conn->prepare($sqlLimpiarDuplicados);
            $stmtLimpiar->execute([
                ':producto_id' => $productoId,
                ':sucursal' => $sucursal
            ]);

            $sqlProducto = "UPDATE productos
                            SET ubicacion = :ubicacion
                            WHERE id = :producto_id";

            $stmtProducto = $this->conn->prepare($sqlProducto);
            $stmtProducto->execute([
                ':ubicacion' => $ubicacionNueva,
                ':producto_id' => $productoId
            ]);

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Ubicación y existencia asignadas correctamente.'
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            return [
                'success' => false,
                'message' => 'No se pudo asignar la ubicación: ' . $e->getMessage()
            ];
        }
    }

    public function darDeBaja(array $data): array
    {
        $productoId = (int)($data['producto_id'] ?? 0);

        if ($productoId <= 0) {
            return [
                'success' => false,
                'message' => 'Producto inválido.'
            ];
        }

        try {
            $this->conn->beginTransaction();

            $sqlProductoExiste = "SELECT id, descripcion
                                  FROM productos
                                  WHERE id = :producto_id
                                  AND estado = 1
                                  LIMIT 1";

            $stmtProductoExiste = $this->conn->prepare($sqlProductoExiste);
            $stmtProductoExiste->execute([
                ':producto_id' => $productoId
            ]);

            $producto = $stmtProductoExiste->fetch(PDO::FETCH_ASSOC);

            if (!$producto) {
                throw new Exception('Producto no encontrado.');
            }

            if ($this->esAdmin()) {
                $sqlExiste = "SELECT COUNT(*)
                              FROM producto_existencias
                              WHERE producto_id = :producto_id";

                $stmtExiste = $this->conn->prepare($sqlExiste);
                $stmtExiste->execute([
                    ':producto_id' => $productoId
                ]);

                $existe = (int)$stmtExiste->fetchColumn();

                if ($existe > 0) {
                    $sqlBaja = "UPDATE producto_existencias
                                SET existencia = 0,
                                    ubicacion = 'SIN UBICACION',
                                    sucursal = NULL,
                                    updated_at = CURRENT_TIMESTAMP
                                WHERE producto_id = :producto_id";

                    $stmtBaja = $this->conn->prepare($sqlBaja);
                    $stmtBaja->execute([
                        ':producto_id' => $productoId
                    ]);
                } else {
                    $sqlInsertBaja = "INSERT INTO producto_existencias (
                                        producto_id,
                                        sucursal,
                                        ubicacion,
                                        existencia
                                      ) VALUES (
                                        :producto_id,
                                        NULL,
                                        'SIN UBICACION',
                                        0
                                      )";

                    $stmtInsertBaja = $this->conn->prepare($sqlInsertBaja);
                    $stmtInsertBaja->execute([
                        ':producto_id' => $productoId
                    ]);
                }
            } else {
                $sucursal = $this->sucursalSesion();

                if ($sucursal === '') {
                    throw new Exception('No se pudo identificar la sucursal de la sesión.');
                }

                $sqlExisteSucursal = "SELECT COUNT(*)
                                      FROM producto_existencias
                                      WHERE producto_id = :producto_id
                                      AND UPPER(COALESCE(sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal)";

                $stmtExisteSucursal = $this->conn->prepare($sqlExisteSucursal);
                $stmtExisteSucursal->execute([
                    ':producto_id' => $productoId,
                    ':sucursal' => $sucursal
                ]);

                $existeSucursal = (int)$stmtExisteSucursal->fetchColumn();

                if ($existeSucursal > 0) {
                    $sqlBajaSucursal = "UPDATE producto_existencias
                                        SET existencia = 0,
                                            ubicacion = 'SIN UBICACION',
                                            sucursal = NULL,
                                            updated_at = CURRENT_TIMESTAMP
                                        WHERE producto_id = :producto_id
                                        AND UPPER(COALESCE(sucursal, '')) COLLATE utf8mb4_general_ci = UPPER(:sucursal)";

                    $stmtBajaSucursal = $this->conn->prepare($sqlBajaSucursal);
                    $stmtBajaSucursal->execute([
                        ':producto_id' => $productoId,
                        ':sucursal' => $sucursal
                    ]);
                } else {
                    $sqlInsertBajaSucursal = "INSERT INTO producto_existencias (
                                                producto_id,
                                                sucursal,
                                                ubicacion,
                                                existencia
                                              ) VALUES (
                                                :producto_id,
                                                NULL,
                                                'SIN UBICACION',
                                                0
                                              )";

                    $stmtInsertBajaSucursal = $this->conn->prepare($sqlInsertBajaSucursal);
                    $stmtInsertBajaSucursal->execute([
                        ':producto_id' => $productoId
                    ]);
                }
            }

            $sqlProducto = "UPDATE productos
                            SET ubicacion = 'SIN UBICACION'
                            WHERE id = :producto_id";

            $stmtProducto = $this->conn->prepare($sqlProducto);
            $stmtProducto->execute([
                ':producto_id' => $productoId
            ]);

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Producto dado de baja correctamente. Ahora aparece en Sin almacén.'
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            return [
                'success' => false,
                'message' => 'No se pudo dar de baja el producto: ' . $e->getMessage()
            ];
        }
    }
}