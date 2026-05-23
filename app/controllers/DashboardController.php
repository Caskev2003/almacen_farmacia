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

    private function sucursalesPermitidas(): array
    {
        if ($this->rol === 'ADMINISTRADOR') {
            return [];
        }

        if ($this->almacenId <= 0) {
            return [];
        }

        $stmt = $this->conn->prepare("
            SELECT nombre
            FROM almacenes
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $this->almacenId
        ]);

        $almacen = strtoupper(trim($stmt->fetchColumn() ?: ''));

        if ($almacen === '') {
            return [];
        }

        if (str_contains($almacen, 'HIDALGO')) {
            return ['CIUDAD HIDALGO', 'CD HIDALGO'];
        }

        if (str_contains($almacen, 'TUXTLA')) {
            return ['TUXTLA', 'TUXTLA GUTIERREZ', 'TUXTLA GUTIÉRREZ'];
        }

        return [$almacen];
    }

    private function agregarFiltroSucursal(string &$sql, array &$params, string $alias = 'pe'): void
    {
        $sucursales = $this->sucursalesPermitidas();

        if (empty($sucursales)) {
            return;
        }

        $placeholders = [];

        foreach ($sucursales as $index => $sucursal) {
            $key = ":sucursal_{$alias}_{$index}";
            $placeholders[] = $key;
            $params[$key] = $sucursal;
        }

        $sql .= " AND UPPER({$alias}.sucursal) IN (" . implode(',', $placeholders) . ") ";
    }

    private function agregarFiltroMovimientos(string &$sql, array &$params): void
    {
        if ($this->rol !== 'ADMINISTRADOR' && $this->almacenId > 0) {
            $sql .= " AND m.almacen_id = :almacen_id ";
            $params[':almacen_id'] = $this->almacenId;
        }
    }

    public function getIndicadores(): array
    {
        $params = [];
        $stockMinimo = $this->stockMinimoDashboard;

        $subquery = "SELECT
                        pe.producto_id,
                        SUM(COALESCE(pe.existencia, 0)) AS total_existencia
                     FROM producto_existencias pe
                     WHERE 1 = 1";

        $this->agregarFiltroSucursal($subquery, $params, 'pe');

        $subquery .= " GROUP BY pe.producto_id";

        $sql = "SELECT
                    COUNT(p.id) AS total_productos,

                    SUM(CASE
                        WHEN COALESCE(stock.total_existencia, 0) <= 0
                        THEN 1 ELSE 0
                    END) AS agotados,

                    SUM(CASE
                        WHEN COALESCE(stock.total_existencia, 0) > 0
                         AND COALESCE(stock.total_existencia, 0) <= {$stockMinimo}
                        THEN 1 ELSE 0
                    END) AS bajo_stock,

                    SUM(CASE
                        WHEN COALESCE(stock.total_existencia, 0) > {$stockMinimo}
                        THEN 1 ELSE 0
                    END) AS en_stock

                FROM productos p

                LEFT JOIN ({$subquery}) AS stock
                    ON stock.producto_id = p.id

                WHERE p.estado = 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        $productos = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

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
        $entradasHoy = $stmtEntradasHoy->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

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
        $salidasHoy = $stmtSalidasHoy->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        return [
            'total_productos' => (int)($productos['total_productos'] ?? 0),
            'agotados' => (int)($productos['agotados'] ?? 0),
            'bajo_stock' => (int)($productos['bajo_stock'] ?? 0),
            'en_stock' => (int)($productos['en_stock'] ?? 0),
            'entradas_hoy' => (int)$entradasHoy,
            'salidas_hoy' => (int)$salidasHoy,
        ];
    }

    public function getProductosCriticos(): array
    {
        $params = [];
        $stockMinimo = $this->stockMinimoDashboard;

        $subquery = "SELECT
                        pe.producto_id,
                        SUM(COALESCE(pe.existencia, 0)) AS total_existencia,
                        MIN(NULLIF(TRIM(pe.ubicacion), '')) AS ubicacion
                     FROM producto_existencias pe
                     WHERE 1 = 1";

        $this->agregarFiltroSucursal($subquery, $params, 'pe');

        $subquery .= " GROUP BY pe.producto_id";

        $sql = "SELECT
                    p.id,
                    p.codigo,
                    p.descripcion,
                    {$stockMinimo} AS stock_minimo,
                    COALESCE(stock.ubicacion, NULLIF(TRIM(p.ubicacion), ''), 'SIN UBICACIÓN') AS ubicacion,
                    COALESCE(stock.total_existencia, 0) AS existencia_actual

                FROM productos p

                LEFT JOIN ({$subquery}) AS stock
                    ON stock.producto_id = p.id

                WHERE p.estado = 1
                  AND COALESCE(stock.total_existencia, 0) > 0
                  AND COALESCE(stock.total_existencia, 0) <= {$stockMinimo}

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

        $sql .= " ORDER BY m.fecha DESC, m.id DESC
                  LIMIT 8";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getProductosPorUbicacion(): array
    {
        $params = [];

        $sql = "SELECT
                    COALESCE(NULLIF(TRIM(pe.ubicacion), ''), 'SIN UBICACIÓN') AS ubicacion,
                    SUM(COALESCE(pe.existencia, 0)) AS total

                FROM producto_existencias pe

                INNER JOIN productos p
                    ON p.id = pe.producto_id

                WHERE p.estado = 1
                  AND COALESCE(pe.existencia, 0) > 0";

        $this->agregarFiltroSucursal($sql, $params, 'pe');

        $sql .= " GROUP BY COALESCE(NULLIF(TRIM(pe.ubicacion), ''), 'SIN UBICACIÓN')
                  ORDER BY total DESC
                  LIMIT 10";

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
                'texto' => 'AGOTADO',
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