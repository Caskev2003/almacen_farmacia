<?php

require_once __DIR__ . '/../config/database.php';

class DashboardController
{
    private PDO $conn;
    private string $rol = '';
    private int $almacenId = 0;
    private int $stockMinimoDashboard = 120;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        date_default_timezone_set('America/Mexico_City');

        $database = new Database();
        $this->conn = $database->connect();
        $this->conn->query("SET time_zone = '-06:00'");

        $usuario = $_SESSION['user'] ?? [];

        $this->rol = strtoupper(trim($usuario['rol'] ?? ''));
        $this->almacenId = (int)($usuario['almacen_id'] ?? 0);
    }

    private function esAdmin(): bool
    {
        return in_array($this->rol, ['ADMINISTRADOR', 'ADMIN'], true);
    }

    private function sucursalesPermitidas(): array
    {
        if ($this->esAdmin()) {
            return [];
        }

        if ($this->almacenId === 1) {
            return ['CIUDAD HIDALGO', 'CD HIDALGO'];
        }

        if ($this->almacenId === 2 || $this->almacenId === 3) {
            return ['TUXTLA', 'TUXTLA GUTIERREZ', 'TUXTLA GUTIÉRREZ'];
        }

        return [];
    }

    private function agregarFiltroSucursal(string &$sql, array &$params, string $alias = 'pe'): void
    {
        $sucursales = $this->sucursalesPermitidas();

        if (empty($sucursales)) {
            return;
        }

        $marks = [];

        foreach ($sucursales as $i => $sucursal) {
            $key = ":sucursal_{$alias}_{$i}";
            $marks[] = $key;
            $params[$key] = $sucursal;
        }

        $sql .= " AND UPPER(COALESCE({$alias}.sucursal, '')) COLLATE utf8mb4_general_ci IN (" . implode(',', $marks) . ") ";
    }

    private function agregarFiltroMovimientos(string &$sql, array &$params): void
    {
        if (!$this->esAdmin() && $this->almacenId > 0) {
            $sql .= " AND m.almacen_id = :almacen_id ";
            $params[':almacen_id'] = $this->almacenId;
        }
    }

    public function getIndicadores(): array
    {
        $stockMinimo = $this->stockMinimoDashboard;

        $stmtTotal = $this->conn->prepare("SELECT COUNT(*) AS total FROM productos WHERE estado = 1");
        $stmtTotal->execute();
        $totalProductos = (int)($stmtTotal->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $paramsSinAlmacen = [];

        $sqlSinAlmacen = "SELECT COUNT(DISTINCT p.id) AS total
                          FROM productos p
                          LEFT JOIN producto_existencias pe
                              ON pe.producto_id = p.id
                          WHERE p.estado = 1
                          AND (
                              pe.producto_id IS NULL
                              OR pe.sucursal IS NULL
                              OR TRIM(pe.sucursal) = ''
                          )";

        $stmtSinAlmacen = $this->conn->prepare($sqlSinAlmacen);
        $stmtSinAlmacen->execute($paramsSinAlmacen);
        $sinAlmacen = (int)($stmtSinAlmacen->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $paramsSinExistencia = [];

        $sqlSinExistencia = "SELECT COUNT(*) AS total
                             FROM (
                                SELECT p.id
                                FROM productos p
                                INNER JOIN producto_existencias pe
                                    ON pe.producto_id = p.id
                                WHERE p.estado = 1
                                AND pe.sucursal IS NOT NULL
                                AND TRIM(pe.sucursal) != ''";

        $this->agregarFiltroSucursal($sqlSinExistencia, $paramsSinExistencia, 'pe');

        $sqlSinExistencia .= " GROUP BY p.id
                               HAVING SUM(COALESCE(pe.existencia, 0)) <= 0
                             ) AS t";

        $stmtSinExistencia = $this->conn->prepare($sqlSinExistencia);
        $stmtSinExistencia->execute($paramsSinExistencia);
        $sinExistencia = (int)($stmtSinExistencia->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $paramsStock = [];

        $sqlStock = "SELECT COUNT(*) AS total
                     FROM (
                        SELECT p.id, SUM(COALESCE(pe.existencia, 0)) AS existencia_total
                        FROM productos p
                        INNER JOIN producto_existencias pe
                            ON pe.producto_id = p.id
                        WHERE p.estado = 1
                        AND pe.sucursal IS NOT NULL
                        AND TRIM(pe.sucursal) != ''
                        AND pe.ubicacion IS NOT NULL
                        AND TRIM(pe.ubicacion) != ''
                        AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')";

        $this->agregarFiltroSucursal($sqlStock, $paramsStock, 'pe');

        $sqlStock .= " GROUP BY p.id
                       HAVING SUM(COALESCE(pe.existencia, 0)) > 0
                     ) AS t";

        $stmtStock = $this->conn->prepare($sqlStock);
        $stmtStock->execute($paramsStock);
        $conStockCorrecto = (int)($stmtStock->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $paramsBajo = [];

        $sqlBajo = "SELECT COUNT(*) AS total
                    FROM (
                        SELECT p.id, SUM(COALESCE(pe.existencia, 0)) AS existencia_total
                        FROM productos p
                        INNER JOIN producto_existencias pe
                            ON pe.producto_id = p.id
                        WHERE p.estado = 1
                        AND pe.sucursal IS NOT NULL
                        AND TRIM(pe.sucursal) != ''
                        AND pe.ubicacion IS NOT NULL
                        AND TRIM(pe.ubicacion) != ''
                        AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')";

        $this->agregarFiltroSucursal($sqlBajo, $paramsBajo, 'pe');

        $sqlBajo .= " GROUP BY p.id
                      HAVING SUM(COALESCE(pe.existencia, 0)) BETWEEN 1 AND {$stockMinimo}
                    ) AS t";

        $stmtBajo = $this->conn->prepare($sqlBajo);
        $stmtBajo->execute($paramsBajo);
        $bajoStock = (int)($stmtBajo->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $enStock = max($conStockCorrecto - $bajoStock, 0);

        $inicioHoy = date('Y-m-d') . ' 00:00:00';
        $finHoy = date('Y-m-d') . ' 23:59:59';

        $paramsEntrada = [
            ':inicio' => $inicioHoy,
            ':fin' => $finHoy
        ];

        $sqlEntradasHoy = "SELECT COUNT(*) AS total
                           FROM movimientos m
                           WHERE m.tipo_movimiento = 'ENTRADA'
                           AND m.fecha BETWEEN :inicio AND :fin";

        $this->agregarFiltroMovimientos($sqlEntradasHoy, $paramsEntrada);

        $stmtEntradasHoy = $this->conn->prepare($sqlEntradasHoy);
        $stmtEntradasHoy->execute($paramsEntrada);
        $entradasHoy = (int)($stmtEntradasHoy->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $paramsSalida = [
            ':inicio' => $inicioHoy,
            ':fin' => $finHoy
        ];

        $sqlSalidasHoy = "SELECT COUNT(*) AS total
                          FROM movimientos m
                          WHERE m.tipo_movimiento = 'SALIDA'
                          AND m.fecha BETWEEN :inicio AND :fin";

        $this->agregarFiltroMovimientos($sqlSalidasHoy, $paramsSalida);

        $stmtSalidasHoy = $this->conn->prepare($sqlSalidasHoy);
        $stmtSalidasHoy->execute($paramsSalida);
        $salidasHoy = (int)($stmtSalidasHoy->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        return [
            'total_productos' => $totalProductos,
            'agotados' => $sinExistencia,
            'sin_existencia' => $sinExistencia,
            'sin_almacen' => $sinAlmacen,
            'bajo_stock' => $bajoStock,
            'en_stock' => $enStock,
            'stock_correcto' => $conStockCorrecto,
            'entradas_hoy' => $entradasHoy,
            'salidas_hoy' => $salidasHoy,
        ];
    }

    public function getProductosCriticos(): array
    {
        $params = [];
        $stockMinimo = $this->stockMinimoDashboard;

        $sql = "SELECT
                    p.id,
                    p.codigo,
                    p.descripcion,
                    {$stockMinimo} AS stock_minimo,
                    MIN(pe.ubicacion) AS ubicacion,
                    SUM(COALESCE(pe.existencia, 0)) AS existencia_actual
                FROM productos p
                INNER JOIN producto_existencias pe
                    ON pe.producto_id = p.id
                WHERE p.estado = 1
                AND pe.sucursal IS NOT NULL
                AND TRIM(pe.sucursal) != ''
                AND pe.ubicacion IS NOT NULL
                AND TRIM(pe.ubicacion) != ''
                AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')";

        $this->agregarFiltroSucursal($sql, $params, 'pe');

        $sql .= " GROUP BY p.id, p.codigo, p.descripcion
                  HAVING SUM(COALESCE(pe.existencia, 0)) BETWEEN 1 AND {$stockMinimo}
                  ORDER BY existencia_actual ASC, p.descripcion ASC
                  LIMIT 12";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getMovimientosRecientes(): array
    {
        $params = [];

        $sql = "SELECT
                    m.id,
                    m.folio,
                    m.tipo_movimiento,
                    m.fecha,
                    a.nombre AS almacen,
                    u.nombre AS usuario
                FROM movimientos m
                LEFT JOIN almacenes a
                    ON m.almacen_id = a.id
                INNER JOIN usuarios u
                    ON m.usuario_id = u.id
                WHERE 1 = 1";

        $this->agregarFiltroMovimientos($sql, $params);

        $sql .= " ORDER BY m.fecha DESC, m.id DESC LIMIT 8";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getProductosPorUbicacion(): array
    {
        $params = [];

        $sql = "SELECT
                    COALESCE(NULLIF(TRIM(pe.ubicacion), ''), 'SIN UBICACION') AS ubicacion,
                    SUM(COALESCE(pe.existencia, 0)) AS total
                FROM producto_existencias pe
                INNER JOIN productos p
                    ON p.id = pe.producto_id
                WHERE p.estado = 1
                AND COALESCE(pe.existencia, 0) > 0
                AND pe.sucursal IS NOT NULL
                AND TRIM(pe.sucursal) != ''
                AND pe.ubicacion IS NOT NULL
                AND TRIM(pe.ubicacion) != ''
                AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')";

        $this->agregarFiltroSucursal($sql, $params, 'pe');

        $sql .= " GROUP BY COALESCE(NULLIF(TRIM(pe.ubicacion), ''), 'SIN UBICACION')
                  ORDER BY total DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getDocumentosMasUsados(): array
    {
        $params = [];

        $sql = "SELECT 
                    COALESCE(NULLIF(TRIM(m.tipo_operacion), ''), 'SIN DOCUMENTO') AS documento,
                    COUNT(*) AS total
                FROM movimientos m
                WHERE m.tipo_movimiento = 'SALIDA'";

        $this->agregarFiltroMovimientos($sql, $params);

        $sql .= " GROUP BY COALESCE(NULLIF(TRIM(m.tipo_operacion), ''), 'SIN DOCUMENTO')
                  ORDER BY total DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getEstadoProducto(array $item): array
    {
        $existencia = (int)($item['existencia_actual'] ?? 0);

        if ($existencia <= 0) {
            return [
                'texto' => 'SIN EXISTENCIA',
                'clase' => 'badge-danger'
            ];
        }

        if ($existencia <= $this->stockMinimoDashboard) {
            return [
                'texto' => 'BAJO STOCK',
                'clase' => 'badge-warning'
            ];
        }

        return [
            'texto' => 'EN STOCK',
            'clase' => 'badge-success'
        ];
    }
}