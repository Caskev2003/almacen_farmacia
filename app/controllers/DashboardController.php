<?php

require_once __DIR__ . '/../config/database.php';

class DashboardController
{
    private PDO $conn;
    private int $almacenId = 0;
    private string $rol = '';

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $database = new Database();
        $this->conn = $database->connect();

        $usuario = $_SESSION['user'] ?? [];

        $this->rol = $usuario['rol'] ?? '';
        $this->almacenId = (int)($usuario['almacen_id'] ?? 0);
    }

    private function filtroAlmacen(string $campo = 'l.almacen_id'): string
    {
        if ($this->rol === 'ADMINISTRADOR') {
            return '';
        }

        return " AND {$campo} = {$this->almacenId} ";
    }

    public function getIndicadores(): array
    {
        $filtroLotes = $this->filtroAlmacen('l.almacen_id');
        $filtroMovimientos = $this->filtroAlmacen('m.almacen_id');

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
                                     AND COALESCE(stock.total_existencia, 0) <= p.stock_minimo
                                    THEN 1 ELSE 0
                                END
                            ) AS bajo_stock,

                            SUM(
                                CASE
                                    WHEN COALESCE(stock.total_existencia, 0) > p.stock_minimo
                                    THEN 1 ELSE 0
                                END
                            ) AS en_stock,

                            SUM(
                                COALESCE(stock.total_existencia, 0) * p.precio_compra
                            ) AS valor_inventario

                         FROM productos p

                         LEFT JOIN (

                            SELECT
                                l.producto_id,
                                SUM(l.existencia) AS total_existencia
                            FROM lotes l
                            WHERE l.estado = 1
                            {$filtroLotes}
                            GROUP BY l.producto_id

                         ) AS stock
                            ON stock.producto_id = p.id

                         WHERE p.estado = 1";

        $stmtProductos = $this->conn->query($sqlProductos);
        $productos = $stmtProductos->fetch() ?: [];

        $sqlCaducidad = "SELECT
                            SUM(
                                CASE
                                    WHEN fecha_caducidad < CURDATE()
                                     AND existencia > 0
                                     AND estado = 1
                                    THEN 1 ELSE 0
                                END
                            ) AS caducados,

                            SUM(
                                CASE
                                    WHEN fecha_caducidad >= CURDATE()
                                     AND fecha_caducidad <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                                     AND existencia > 0
                                     AND estado = 1
                                    THEN 1 ELSE 0
                                END
                            ) AS por_caducar

                         FROM lotes l
                         WHERE 1=1
                         {$filtroLotes}";

        $stmtCaducidad = $this->conn->query($sqlCaducidad);
        $caducidad = $stmtCaducidad->fetch() ?: [];

        $sqlEntradasHoy = "SELECT COUNT(*) AS total
                           FROM movimientos m
                           WHERE tipo_movimiento = 'ENTRADA'
                             AND DATE(fecha) = CURDATE()
                             {$filtroMovimientos}";

        $stmtEntradasHoy = $this->conn->query($sqlEntradasHoy);
        $entradasHoy = $stmtEntradasHoy->fetch()['total'] ?? 0;

        $sqlSalidasHoy = "SELECT COUNT(*) AS total
                          FROM movimientos m
                          WHERE tipo_movimiento = 'SALIDA'
                            AND DATE(fecha) = CURDATE()
                            {$filtroMovimientos}";

        $stmtSalidasHoy = $this->conn->query($sqlSalidasHoy);
        $salidasHoy = $stmtSalidasHoy->fetch()['total'] ?? 0;

        return [
            'total_productos' => (int)($productos['total_productos'] ?? 0),
            'agotados' => (int)($productos['agotados'] ?? 0),
            'bajo_stock' => (int)($productos['bajo_stock'] ?? 0),
            'en_stock' => (int)($productos['en_stock'] ?? 0),
            'valor_inventario' => (float)($productos['valor_inventario'] ?? 0),
            'caducados' => (int)($caducidad['caducados'] ?? 0),
            'por_caducar' => (int)($caducidad['por_caducar'] ?? 0),
            'entradas_hoy' => (int)$entradasHoy,
            'salidas_hoy' => (int)$salidasHoy,
        ];
    }

    public function getProductosCriticos(): array
    {
        $filtroLotes = $this->filtroAlmacen('l.almacen_id');

        $sql = "SELECT *
                FROM (

                    SELECT
                        p.codigo,
                        p.descripcion,
                        p.stock_minimo,
                        p.ubicacion,

                        COALESCE(SUM(
                            CASE
                                WHEN l.estado = 1
                                THEN l.existencia
                                ELSE 0
                            END
                        ), 0) AS existencia_actual,

                        MIN(
                            CASE
                                WHEN l.estado = 1
                                 AND l.existencia > 0
                                THEN l.fecha_caducidad
                                ELSE NULL
                            END
                        ) AS proxima_caducidad

                    FROM productos p

                    LEFT JOIN lotes l
                        ON p.id = l.producto_id
                        {$filtroLotes}

                    WHERE p.estado = 1

                    GROUP BY
                        p.id,
                        p.codigo,
                        p.descripcion,
                        p.stock_minimo,
                        p.ubicacion

                ) AS inventario

                WHERE
                    inventario.existencia_actual <= 0
                    OR inventario.existencia_actual <= inventario.stock_minimo
                    OR (
                        inventario.proxima_caducidad IS NOT NULL
                        AND inventario.proxima_caducidad <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                    )

                ORDER BY
                    CASE
                        WHEN inventario.existencia_actual <= 0 THEN 1
                        WHEN inventario.proxima_caducidad IS NOT NULL
                             AND inventario.proxima_caducidad < CURDATE()
                        THEN 2
                        WHEN inventario.existencia_actual <= inventario.stock_minimo
                        THEN 3
                        ELSE 4
                    END,
                    inventario.descripcion ASC

                LIMIT 12";

        $stmt = $this->conn->query($sql);

        return $stmt->fetchAll() ?: [];
    }

    public function getMovimientosRecientes(): array
    {
        $filtro = '';

        if ($this->rol !== 'ADMINISTRADOR') {
            $filtro = " WHERE m.almacen_id = {$this->almacenId}";
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

        return $stmt->fetchAll() ?: [];
    }

    public function getEstadoProducto(array $item): array
    {
        $existencia = (int)($item['existencia_actual'] ?? 0);
        $stockMinimo = (int)($item['stock_minimo'] ?? 0);
        $caducidad = $item['proxima_caducidad'] ?? null;

        if ($existencia <= 0) {
            return ['texto' => 'AGOTADO', 'clase' => 'badge-danger'];
        }

        if ($caducidad && strtotime($caducidad) < strtotime(date('Y-m-d'))) {
            return ['texto' => 'CADUCADO', 'clase' => 'badge-dark'];
        }

        if ($caducidad && strtotime($caducidad) <= strtotime('+30 days')) {
            return ['texto' => 'POR CADUCAR', 'clase' => 'badge-warning'];
        }

        if ($existencia <= $stockMinimo) {
            return ['texto' => 'BAJO STOCK', 'clase' => 'badge-warning'];
        }

        return ['texto' => 'EN STOCK', 'clase' => 'badge-success'];
    }
}