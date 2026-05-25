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

        $sql .= " AND UPPER(pe.sucursal) COLLATE utf8mb4_general_ci IN (" . implode(',', $marks) . ") ";
    }

    public function almacenes(): array
    {
        $sql = "SELECT id, nombre 
                FROM almacenes 
                WHERE estado = 1 
                ORDER BY nombre ASC";

        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function subqueryInventario(array &$params, int $almacenId): string
    {
        $subquery = "SELECT 
                        pe.producto_id,
                        SUM(COALESCE(pe.existencia, 0)) AS existencia_total,
                        GROUP_CONCAT(
                            DISTINCT COALESCE(NULLIF(TRIM(pe.ubicacion), ''), 'SIN UBICACION')
                            ORDER BY pe.ubicacion ASC
                            SEPARATOR ', '
                        ) AS ubicaciones,
                        MIN(pe.sucursal) AS sucursal
                    FROM producto_existencias pe
                    WHERE 1 = 1";

        if ($almacenId > 0) {
            $this->aplicarFiltroSucursal($subquery, $params, $almacenId);
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

        $subquery = $this->subqueryInventario($params, $almacenId);

        $sql = "SELECT
                    p.id,
                    p.codigo,
                    p.codigo_barras,
                    p.descripcion,
                    c.nombre AS categoria,
                    pr.nombre AS proveedor,
                    p.laboratorio,
                    p.unidad_medida,
                    stock.sucursal AS sucursal,
                    COALESCE(stock.ubicaciones, 'SIN UBICACION') AS ubicacion,
                    COALESCE(stock.existencia_total, 0) AS existencia,

                    CASE
                        WHEN stock.producto_id IS NULL THEN 'SIN ALMACEN'

                        WHEN COALESCE(stock.existencia_total, 0) <= 0
                        AND (
                            COALESCE(stock.ubicaciones, '') COLLATE utf8mb4_general_ci = ''
                            OR UPPER(COALESCE(stock.ubicaciones, '')) COLLATE utf8mb4_general_ci IN ('SIN UBICACION', 'SIN UBICACIÓN')
                        )
                        THEN 'SIN UBICACIÓN Y SIN EXISTENCIA'

                        WHEN COALESCE(stock.existencia_total, 0) <= 0
                        THEN 'SIN EXISTENCIA'

                        WHEN (
                            COALESCE(stock.ubicaciones, '') COLLATE utf8mb4_general_ci = ''
                            OR UPPER(COALESCE(stock.ubicaciones, '')) COLLATE utf8mb4_general_ci IN ('SIN UBICACION', 'SIN UBICACIÓN')
                        )
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
                      AND COALESCE(stock.existencia_total, 0) > 0
                      AND (
                            COALESCE(stock.ubicaciones, '') COLLATE utf8mb4_general_ci = ''
                            OR UPPER(COALESCE(stock.ubicaciones, '')) COLLATE utf8mb4_general_ci IN ('SIN UBICACION', 'SIN UBICACIÓN')
                      )";
        } elseif ($tipo === 'sin_existencia') {
            $sql .= " AND stock.producto_id IS NOT NULL
                      AND COALESCE(stock.existencia_total, 0) <= 0
                      AND COALESCE(stock.ubicaciones, '') COLLATE utf8mb4_general_ci != ''
                      AND UPPER(COALESCE(stock.ubicaciones, '')) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')";
        } else {
            $sql .= " AND (
                        stock.producto_id IS NULL
                        OR (
                            COALESCE(stock.existencia_total, 0) <= 0
                            AND (
                                COALESCE(stock.ubicaciones, '') COLLATE utf8mb4_general_ci = ''
                                OR UPPER(COALESCE(stock.ubicaciones, '')) COLLATE utf8mb4_general_ci IN ('SIN UBICACION', 'SIN UBICACIÓN')
                            )
                        )
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
        $ambas = $this->listar($baseFiltros)['total'];

        $params = [];

        $almacenId = (int)($filtros['almacen_id'] ?? 0);

        if (!$this->esAdmin()) {
            $almacenId = $this->almacenIdSesion();
        }

        $subquery = $this->subqueryInventario($params, $almacenId);

        $sqlExistencias = "SELECT COUNT(*) AS total
                           FROM productos p
                           INNER JOIN ({$subquery}) stock
                               ON stock.producto_id = p.id
                           WHERE p.estado = 1
                           AND COALESCE(stock.existencia_total, 0) > 0
                           AND COALESCE(stock.ubicaciones, '') COLLATE utf8mb4_general_ci != ''
                           AND UPPER(COALESCE(stock.ubicaciones, '')) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')";

        $stmtExistencias = $this->conn->prepare($sqlExistencias);
        $stmtExistencias->execute($params);

        $productosConExistencia = (int)($stmtExistencias->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $agotadosTotal = $sinUbicacion + $sinExistencia + $ambas;

        return [
            'sin_ubicacion' => $sinUbicacion,
            'sin_existencia' => $sinExistencia,
            'ambas' => $ambas,
            'agotados_total' => $agotadosTotal,
            'productos_con_existencia' => $productosConExistencia,
            'inventario_total' => $productosConExistencia + $agotadosTotal,
        ];
    }

    public function actualizarUbicacion(array $data): array
    {
        $productoId = (int)($data['producto_id'] ?? 0);
        $sucursal = strtoupper(trim($data['sucursal'] ?? ''));
        $ubicacionNueva = strtoupper(trim($data['ubicacion_nueva'] ?? ''));
        $existencia = (int)($data['existencia'] ?? 0);

        if ($productoId <= 0) {
            return [
                'success' => false,
                'message' => 'Producto inválido.'
            ];
        }

        if ($sucursal === '') {
            $almacenId = $this->almacenIdSesion();

            if ($almacenId === 1) {
                $sucursal = 'CIUDAD HIDALGO';
            } elseif ($almacenId === 2 || $almacenId === 3) {
                $sucursal = 'TUXTLA';
            }
        }

        if ($sucursal === '') {
            return [
                'success' => false,
                'message' => 'Sucursal inválida.'
            ];
        }

        if (
            $ubicacionNueva === '' ||
            $ubicacionNueva === 'SIN UBICACION' ||
            $ubicacionNueva === 'SIN UBICACIÓN'
        ) {
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

            $sqlDeleteSinUbicacion = "DELETE FROM producto_existencias
                                      WHERE producto_id = :producto_id
                                      AND UPPER(sucursal) COLLATE utf8mb4_general_ci = UPPER(:sucursal)
                                      AND (
                                          existencia <= 0
                                          OR ubicacion IS NULL
                                          OR TRIM(ubicacion) COLLATE utf8mb4_general_ci = ''
                                          OR UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci IN ('SIN UBICACION', 'SIN UBICACIÓN')
                                      )";

            $stmtDelete = $this->conn->prepare($sqlDeleteSinUbicacion);
            $stmtDelete->execute([
                ':producto_id' => $productoId,
                ':sucursal' => $sucursal
            ]);

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
                        )
                        ON DUPLICATE KEY UPDATE
                            existencia = VALUES(existencia),
                            updated_at = CURRENT_TIMESTAMP";

            $stmtInsert = $this->conn->prepare($sqlInsert);
            $stmtInsert->execute([
                ':producto_id' => $productoId,
                ':sucursal' => $sucursal,
                ':ubicacion' => $ubicacionNueva,
                ':existencia' => $existencia
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
                'message' => 'Ubicación asignada correctamente. El producto salió de Agotados.'
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            return [
                'success' => false,
                'message' => 'No se pudo asignar la ubicación.'
            ];
        }
    }
}