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
        return $this->sucursalPorAlmacenId($this->almacenIdSesion());
    }

    private function aplicarFiltroAlmacenAgotados(
        string &$sql,
        array &$params,
        string $filtroAlmacen,
        int $almacenId = 0
    ): void {
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
            $sql .= " AND UPPER(COALESCE(pe.sucursal, '')) COLLATE utf8mb4_general_ci 
                      IN ('TUXTLA', 'TUXTLA GUTIERREZ', 'TUXTLA GUTIÉRREZ')";
            return;
        }

        if ($almacenId > 0) {
            $sucursales = $this->sucursalesPorAlmacen($almacenId);

            if (!empty($sucursales)) {
                $marks = [];

                foreach ($sucursales as $i => $sucursal) {
                    $key = ":sucursal_permitida_$i";
                    $marks[] = $key;
                    $params[$key] = $sucursal;
                }

                $sql .= " AND (
                    pe.sucursal IS NULL
                    OR TRIM(pe.sucursal) = ''
                    OR UPPER(pe.sucursal) COLLATE utf8mb4_general_ci IN (" . implode(',', $marks) . ")
                )";
            }
        }
    }

    public function almacenes(): array
    {
        $sql = "SELECT id, nombre 
                FROM almacenes 
                WHERE estado = 1 
                ORDER BY nombre ASC";

        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function subqueryInventario(
        array &$params,
        int $almacenId,
        string $filtroAlmacen = '',
        bool $incluirSinAlmacen = true
    ): string {
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
                                WHEN pe.sucursal IS NULL 
                                OR TRIM(pe.sucursal) = ''
                                THEN 1 
                                ELSE 0 
                            END
                        ) AS filas_sin_almacen,

                        SUM(
                            CASE 
                                WHEN pe.sucursal IS NOT NULL 
                                AND TRIM(pe.sucursal) != ''
                                AND COALESCE(pe.existencia, 0) <= 0
                                THEN 1 
                                ELSE 0 
                            END
                        ) AS filas_sin_existencia

                    FROM producto_existencias pe
                    WHERE 1 = 1";

        if ($filtroAlmacen !== '') {
            $this->aplicarFiltroAlmacenAgotados($subquery, $params, $filtroAlmacen, $almacenId);
        } elseif ($almacenId > 0) {
            $this->aplicarFiltroAlmacenAgotados($subquery, $params, '', $almacenId);
        } elseif (!$incluirSinAlmacen) {
            $subquery .= " AND pe.sucursal IS NOT NULL AND TRIM(pe.sucursal) != '' ";
        }

        $subquery .= " GROUP BY pe.producto_id";

        return $subquery;
    }

    public function listar(array $filtros): array
{
    $params = [];

    $almacenId = (int)($filtros['almacen_id'] ?? 0);
    $tipo = trim($filtros['tipo'] ?? 'sin_existencia');

    if (!in_array($tipo, ['sin_existencia', 'sin_almacen'], true)) {
        $tipo = 'sin_existencia';
    }

    $pagina = max((int)($filtros['pagina'] ?? 1), 1);
    $porPagina = max((int)($filtros['por_pagina'] ?? 25), 1);

    if (!$this->esAdmin()) {
        $almacenId = $this->almacenIdSesion();
    }

    $sucursales = $this->sucursalesPorAlmacen($almacenId);

    $sql = "SELECT
                p.id,
                p.codigo,
                p.codigo_barras,
                p.descripcion,
                COALESCE(c.nombre, 'Sin categoría') AS categoria,
                COALESCE(pr.nombre, 'Sin proveedor') AS proveedor,
                COALESCE(p.laboratorio, '') AS laboratorio,
                COALESCE(stock.sucursal_principal, 'SIN ALMACEN') AS sucursal,
                COALESCE(stock.sucursal_principal, 'SIN ALMACEN') AS sucursales,
                COALESCE(stock.ubicacion_principal, 'SIN UBICACION') AS ubicacion,
                COALESCE(stock.existencia_total, 0) AS existencia,
                COALESCE(stock.filas_con_sucursal, 0) AS filas_con_sucursal,

                CASE
                    WHEN stock.producto_id IS NULL
                         OR COALESCE(stock.filas_con_sucursal, 0) = 0
                    THEN 'SIN ALMACEN'
                    WHEN COALESCE(stock.existencia_total, 0) <= 0
                    THEN 'SIN EXISTENCIA'
                    ELSE 'OK'
                END AS motivo

            FROM productos p
            LEFT JOIN categorias c ON p.categoria_id = c.id
            LEFT JOIN proveedores pr ON p.proveedor_id = pr.id

            LEFT JOIN (
                SELECT
                    pe.producto_id,
                    SUM(COALESCE(pe.existencia, 0)) AS existencia_total,
                    COUNT(*) AS filas_con_sucursal,
                    MAX(pe.sucursal) AS sucursal_principal,
                    MAX(
                        CASE
                            WHEN pe.ubicacion IS NOT NULL
                            AND TRIM(pe.ubicacion) != ''
                            AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')
                            THEN pe.ubicacion
                            ELSE NULL
                        END
                    ) AS ubicacion_principal
                FROM producto_existencias pe
                WHERE pe.sucursal IS NOT NULL
                AND TRIM(pe.sucursal) != ''";

    if (!empty($sucursales)) {
        $marks = [];

        foreach ($sucursales as $i => $sucursal) {
            $key = ":sucursal_stock_$i";
            $marks[] = $key;
            $params[$key] = $sucursal;
        }

        $sql .= " AND UPPER(TRIM(pe.sucursal)) COLLATE utf8mb4_general_ci IN (" . implode(',', $marks) . ")";
    }

    $sql .= "
                GROUP BY pe.producto_id
            ) stock ON stock.producto_id = p.id

            WHERE p.estado = 1";

    if ($tipo === 'sin_existencia') {
        $sql .= " AND stock.producto_id IS NOT NULL
                  AND COALESCE(stock.existencia_total, 0) <= 0";
    }

    if ($tipo === 'sin_almacen') {
        $sql .= " AND EXISTS (
                    SELECT 1
                    FROM producto_existencias pe0
                    WHERE pe0.producto_id = p.id
                    AND (
                        pe0.sucursal IS NULL
                        OR TRIM(pe0.sucursal) = ''
                    )
                    AND (
                        pe0.ubicacion IS NULL
                        OR TRIM(pe0.ubicacion) = ''
                        OR UPPER(TRIM(pe0.ubicacion)) COLLATE utf8mb4_general_ci IN ('SIN UBICACION', 'SIN UBICACIÓN')
                    )
                    AND COALESCE(pe0.existencia, 0) <= 0
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

    return [
        'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total' => $total,
        'pagina' => $pagina,
        'por_pagina' => $porPagina,
        'total_paginas' => max((int)ceil($total / $porPagina), 1),
    ];
}
   public function resumen(array $filtros): array
{
    $params = [];

    $almacenId = (int)($filtros['almacen_id'] ?? 0);

    if (!$this->esAdmin()) {
        $almacenId = $this->almacenIdSesion();
    }

    $sucursales = $this->sucursalesPorAlmacen($almacenId);

    /*
    |--------------------------------------------------------------------------
    | SIN EXISTENCIA
    | Productos del almacén actual con existencia total <= 0
    |--------------------------------------------------------------------------
    */

    $sqlSinExistencia = "SELECT COUNT(*) AS total
                         FROM (
                            SELECT p.id
                            FROM productos p
                            INNER JOIN producto_existencias pe
                                ON pe.producto_id = p.id
                            WHERE p.estado = 1";

    if (!empty($sucursales)) {

        $placeholders = [];

        foreach ($sucursales as $i => $sucursal) {
            $key = ":sx_$i";
            $placeholders[] = $key;
            $params[$key] = strtoupper($sucursal);
        }

        $sqlSinExistencia .= " 
            AND UPPER(TRIM(pe.sucursal)) COLLATE utf8mb4_general_ci 
            IN (" . implode(',', $placeholders) . ")";
    }

    $sqlSinExistencia .= "
                            GROUP BY p.id
                            HAVING SUM(COALESCE(pe.existencia, 0)) <= 0
                         ) t";

    $stmtSinExistencia = $this->conn->prepare($sqlSinExistencia);
    $stmtSinExistencia->execute($params);

    $sinExistencia = (int)$stmtSinExistencia->fetch(PDO::FETCH_ASSOC)['total'];

    /*
    |--------------------------------------------------------------------------
    | SIN ALMACEN
    | Sin sucursal + sin ubicación + existencia 0
    |--------------------------------------------------------------------------
    */

    $sqlSinAlmacen = "SELECT COUNT(*) AS total
                      FROM (
                        SELECT DISTINCT p.id
                        FROM productos p
                        LEFT JOIN producto_existencias pe
                            ON pe.producto_id = p.id
                        WHERE p.estado = 1
                        AND (
                            pe.sucursal IS NULL
                            OR TRIM(pe.sucursal) = ''
                        )
                        AND (
                            pe.ubicacion IS NULL
                            OR TRIM(pe.ubicacion) = ''
                            OR UPPER(TRIM(pe.ubicacion)) IN (
                                'SIN UBICACION',
                                'SIN UBICACIÓN'
                            )
                        )
                        AND COALESCE(pe.existencia, 0) <= 0
                      ) t";

    $stmtSinAlmacen = $this->conn->prepare($sqlSinAlmacen);
    $stmtSinAlmacen->execute();

    $sinAlmacen = (int)$stmtSinAlmacen->fetch(PDO::FETCH_ASSOC)['total'];

    /*
    |--------------------------------------------------------------------------
    | STOCK CORRECTO
    |--------------------------------------------------------------------------
    */

    $paramsStock = [];

    $sqlStock = "SELECT COUNT(*) AS total
                 FROM (
                    SELECT p.id
                    FROM productos p
                    INNER JOIN producto_existencias pe
                        ON pe.producto_id = p.id
                    WHERE p.estado = 1
                    AND pe.ubicacion IS NOT NULL
                    AND TRIM(pe.ubicacion) != ''
                    AND UPPER(TRIM(pe.ubicacion)) NOT IN (
                        'SIN UBICACION',
                        'SIN UBICACIÓN'
                    )";

    if (!empty($sucursales)) {

        $placeholders = [];

        foreach ($sucursales as $i => $sucursal) {
            $key = ":sc_$i";
            $placeholders[] = $key;
            $paramsStock[$key] = strtoupper($sucursal);
        }

        $sqlStock .= "
            AND UPPER(TRIM(pe.sucursal)) COLLATE utf8mb4_general_ci 
            IN (" . implode(',', $placeholders) . ")";
    }

    $sqlStock .= "
                    GROUP BY p.id
                    HAVING SUM(COALESCE(pe.existencia, 0)) > 0
                 ) t";

    $stmtStock = $this->conn->prepare($sqlStock);
    $stmtStock->execute($paramsStock);

    $productosConExistencia = (int)$stmtStock->fetch(PDO::FETCH_ASSOC)['total'];

    /*
    |--------------------------------------------------------------------------
    | INVENTARIO TOTAL
    |--------------------------------------------------------------------------
    */

    $sqlInventarioTotal = "SELECT COUNT(*) AS total
                           FROM productos
                           WHERE estado = 1";

    $stmtInventarioTotal = $this->conn->prepare($sqlInventarioTotal);
    $stmtInventarioTotal->execute();

    $inventarioTotal = (int)$stmtInventarioTotal->fetch(PDO::FETCH_ASSOC)['total'];

    return [
        'sin_ubicacion' => 0,
        'sin_existencia' => $sinExistencia,
        'ambas' => $sinAlmacen,
        'sin_almacen' => $sinAlmacen,
        'agotados_total' => $sinExistencia + $sinAlmacen,
        'productos_con_existencia' => $productosConExistencia,
        'inventario_total' => $inventarioTotal,
    ];
}

    public function actualizarUbicacion(array $data): array
    {
        $productoId = (int)($data['producto_id'] ?? 0);
        $sucursal = $this->limpiarTexto($data['sucursal'] ?? '');
        $ubicacionNueva = $this->limpiarUbicacion($data['ubicacion_nueva'] ?? '');
        $existencia = (int)($data['existencia'] ?? 0);

        if ($productoId <= 0) {
            return ['success' => false, 'message' => 'Producto inválido.'];
        }

        if (!$this->esAdmin()) {
            $sucursal = $this->sucursalSesion();
        }

        if ($sucursal === '' || $sucursal === 'SIN ALMACEN') {
            $sucursal = $this->sucursalSesion();
        }

        if ($sucursal === '') {
            return ['success' => false, 'message' => 'Sucursal inválida.'];
        }

        if ($ubicacionNueva === 'SIN UBICACION') {
            return ['success' => false, 'message' => 'Debes escribir una ubicación válida.'];
        }

        if ($existencia <= 0) {
            return ['success' => false, 'message' => 'La existencia debe ser mayor a 0 para asignar ubicación.'];
        }

        try {
            $this->conn->beginTransaction();

            $sqlRegistroBase = "SELECT id
                                FROM producto_existencias
                                WHERE producto_id = :producto_id
                                AND (
                                    sucursal IS NULL
                                    OR TRIM(sucursal) = ''
                                    OR UPPER(TRIM(sucursal)) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
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
            return ['success' => false, 'message' => 'Producto inválido.'];
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