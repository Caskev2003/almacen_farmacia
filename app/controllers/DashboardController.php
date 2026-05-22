<?php

require_once __DIR__ . '/../config/database.php';

class DashboardController
{
    private PDO $conn;
    private string $rol = '';
    private string $sucursal = '';
    private int $stockMinimoDashboard = 120;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $database = new Database();
        $this->conn = $database->connect();

        $usuario = $_SESSION['user'] ?? [];

        $this->rol = $usuario['rol'] ?? '';
        $this->sucursal = trim($usuario['sucursal'] ?? '');
    }

    private function filtroSucursalExistencias(): string
    {
        if ($this->rol === 'ADMINISTRADOR') {
            return '';
        }

        if ($this->sucursal === '') {
            return '';
        }

        return " AND pe.sucursal = " . $this->conn->quote($this->sucursal) . " ";
    }

    private function filtroMovimientos(): string
    {
        if ($this->rol === 'ADMINISTRADOR' || $this->sucursal === '') {
            return '';
        }

        return " AND a.nombre = " . $this->conn->quote($this->sucursal) . " ";
    }

    public function getIndicadores(): array
    {
        $filtroExistencias = $this->filtroSucursalExistencias();
        $filtroMovimientos = $this->filtroMovimientos();
        $stockMinimo = $this->stockMinimoDashboard;

        $sqlProductos = "SELECT
                            COUNT(DISTINCT p.id) AS total_productos,

                            SUM(
                                CASE
                                    WHEN COALESCE(stock.total_existencia, 0) <= 0
                                    THEN 1 ELSE 0
                                END
                            ) AS agotados,

                            SUM(
                                CASE
                                    WHEN COALESCE(stock.total_existencia, 0) > 0
                                     AND COALESCE(stock.total_existencia, 0) <= {$stockMinimo}
                                    THEN 1 ELSE 0
                                END
                            ) AS bajo_stock,

                            SUM(
                                CASE
                                    WHEN COALESCE(stock.total_existencia, 0) > {$stockMinimo}
                                    THEN 1 ELSE 0
                                END
                            ) AS en_stock,

                            SUM(
                                COALESCE(stock.total_existencia, 0) * COALESCE(p.precio_compra, 0)
                            ) AS valor_inventario

                        FROM productos p

                        LEFT JOIN (
                            SELECT
                                pe.producto_id,
                                SUM(COALESCE(pe.existencia, 0)) AS total_existencia
                            FROM producto_existencias pe
                            WHERE 1=1
                            {$filtroExistencias}
                            GROUP BY pe.producto_id
                        ) AS stock
                            ON stock.producto_id = p.id

                        WHERE p.estado = 1";

        $stmtProductos = $this->conn->query($sqlProductos);
        $productos = $stmtProductos->fetch(PDO::FETCH_ASSOC) ?: [];

        $sqlEntradasHoy = "SELECT COUNT(*) AS total
                           FROM movimientos m
                           LEFT JOIN almacenes a ON m.almacen_id = a.id
                           WHERE m.tipo_movimiento = 'ENTRADA'
                             AND DATE(m.fecha) = CURDATE()
                             {$filtroMovimientos}";

        $stmtEntradasHoy = $this->conn->query($sqlEntradasHoy);
        $entradasHoy = $stmtEntradasHoy->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        $sqlSalidasHoy = "SELECT COUNT(*) AS total
                          FROM movimientos m
                          LEFT JOIN almacenes a ON m.almacen_id = a.id
                          WHERE m.tipo_movimiento = 'SALIDA'
                            AND DATE(m.fecha) = CURDATE()
                            {$filtroMovimientos}";

        $stmtSalidasHoy = $this->conn->query($sqlSalidasHoy);
        $salidasHoy = $stmtSalidasHoy->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        return [
            'total_productos' => (int)($productos['total_productos'] ?? 0),
            'agotados' => (int)($productos['agotados'] ?? 0),
            'bajo_stock' => (int)($productos['bajo_stock'] ?? 0),
            'en_stock' => (int)($productos['en_stock'] ?? 0),
            'valor_inventario' => (float)($productos['valor_inventario'] ?? 0),
            'entradas_hoy' => (int)$entradasHoy,
            'salidas_hoy' => (int)$salidasHoy,
        ];
    }

    public function getProductosCriticos(): array
    {
        $filtroExistencias = $this->filtroSucursalExistencias();
        $stockMinimo = $this->stockMinimoDashboard;

        $sql = "SELECT *
                FROM (
                    SELECT
                        p.id,
                        p.codigo,
                        p.descripcion,
                        {$stockMinimo} AS stock_minimo,
                        COALESCE(NULLIF(TRIM(p.ubicacion), ''), 'SIN UBICACIÓN') AS ubicacion,
                        COALESCE(SUM(pe.existencia), 0) AS existencia_actual

                    FROM productos p

                    LEFT JOIN producto_existencias pe
                        ON pe.producto_id = p.id
                        {$filtroExistencias}

                    WHERE p.estado = 1

                    GROUP BY
                        p.id,
                        p.codigo,
                        p.descripcion,
                        p.ubicacion
                ) AS inventario

                WHERE
                    inventario.existencia_actual <= 0
                    OR inventario.existencia_actual <= {$stockMinimo}

                ORDER BY
                    CASE
                        WHEN inventario.existencia_actual <= 0 THEN 1
                        WHEN inventario.existencia_actual <= {$stockMinimo} THEN 2
                        ELSE 3
                    END,
                    inventario.descripcion ASC

                LIMIT 12";

        $stmt = $this->conn->query($sql);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getMovimientosRecientes(): array
    {
        $filtro = '';

        if ($this->rol !== 'ADMINISTRADOR' && $this->sucursal !== '') {
            $filtro = " WHERE a.nombre = " . $this->conn->quote($this->sucursal);
        }

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

                {$filtro}

                ORDER BY m.fecha DESC, m.id DESC
                LIMIT 8";

        $stmt = $this->conn->query($sql);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getProductosPorUbicacion(): array
    {
        $filtroExistencias = $this->filtroSucursalExistencias();

        $sql = "SELECT
                    COALESCE(NULLIF(TRIM(pe.ubicacion), ''), 'SIN UBICACIÓN') AS ubicacion,
                    SUM(COALESCE(pe.existencia, 0)) AS total

                FROM producto_existencias pe

                INNER JOIN productos p
                    ON p.id = pe.producto_id

                WHERE p.estado = 1
                  AND COALESCE(pe.existencia, 0) > 0
                  {$filtroExistencias}

                GROUP BY COALESCE(NULLIF(TRIM(pe.ubicacion), ''), 'SIN UBICACIÓN')

                ORDER BY total DESC
                LIMIT 10";

        $stmt = $this->conn->query($sql);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getEstadoProducto(array $item): array
    {
        $existencia = (int)($item['existencia_actual'] ?? 0);

        if ($existencia <= 0) {
            return ['texto' => 'AGOTADO', 'clase' => 'badge-danger'];
        }

        if ($existencia <= $this->stockMinimoDashboard) {
            return ['texto' => 'BAJO STOCK', 'clase' => 'badge-warning'];
        }

        return ['texto' => 'EN STOCK', 'clase' => 'badge-success'];
    }
}