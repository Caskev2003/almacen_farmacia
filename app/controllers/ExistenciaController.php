<?php

require_once __DIR__ . '/../config/database.php';

class ExistenciaController
{
    private PDO $conn;
    private int $limiteBajoStock = 120;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $database = new Database();
        $this->conn = $database->connect();
    }

    private function esAdministrador(): bool
    {
        $rol = strtoupper(trim($_SESSION['user']['rol'] ?? ''));
        return in_array($rol, ['ADMINISTRADOR', 'ADMIN'], true);
    }

    private function almacenSesionId(): int
    {
        return (int)($_SESSION['user']['almacen_id'] ?? 0);
    }

    private function sucursalesPorAlmacenId(int $almacenId): array
    {
        if ($almacenId === 1) {
            return ['CIUDAD HIDALGO', 'CD HIDALGO'];
        }

        if ($almacenId === 2 || $almacenId === 3) {
            return ['TUXTLA', 'TUXTLA GUTIERREZ', 'TUXTLA GUTIÉRREZ'];
        }

        return [];
    }

    public function almacenes(): array
    {
        return $this->conn
            ->query("SELECT id, nombre FROM almacenes WHERE estado = 1 ORDER BY nombre ASC")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function categorias(): array
    {
        return $this->conn
            ->query("SELECT id, nombre FROM categorias WHERE estado = 1 ORDER BY nombre ASC")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function proveedores(): array
    {
        return $this->conn
            ->query("SELECT id, nombre FROM proveedores WHERE estado = 1 ORDER BY nombre ASC")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    private function aplicarFiltroSucursal(string &$sql, array &$params, int $almacenId, string $alias = 'pe'): void
    {
        $sucursales = $this->sucursalesPorAlmacenId($almacenId);

        if (empty($sucursales)) {
            return;
        }

        $marks = [];

        foreach ($sucursales as $i => $sucursal) {
            $key = ":sucursal_{$alias}_{$i}";
            $marks[] = $key;
            $params[$key] = $sucursal;
        }

        $sql .= " AND UPPER({$alias}.sucursal) IN (" . implode(',', $marks) . ") ";
    }

    public function index(array $filtros): array
    {
        $params = [];
        $esAdmin = $this->esAdministrador();

        $almacenId = (int)($filtros['almacen_id'] ?? 0);

        if (!$esAdmin) {
            $almacenId = $this->almacenSesionId();
        }

        $subquery = "SELECT
                        pe.producto_id,
                        SUM(COALESCE(pe.existencia, 0)) AS existencia,
                        GROUP_CONCAT(
                            DISTINCT COALESCE(NULLIF(TRIM(pe.ubicacion), ''), 'SIN UBICACION')
                            ORDER BY pe.ubicacion ASC
                            SEPARATOR ', '
                        ) AS ubicacion,
                        MIN(pe.sucursal) AS sucursal
                    FROM producto_existencias pe
                    WHERE 1 = 1";

        if ($almacenId > 0) {
            $this->aplicarFiltroSucursal($subquery, $params, $almacenId, 'pe');
        }

        if (!empty($filtros['rack'])) {
            $subquery .= " AND UPPER(COALESCE(pe.ubicacion, '')) LIKE :rack";
            $params[':rack'] = strtoupper(trim($filtros['rack'])) . '%';
        }

        $subquery .= " GROUP BY pe.producto_id";

        $sql = "SELECT
                    p.id,
                    p.codigo,
                    p.codigo_barras,
                    p.descripcion,
                    c.nombre AS categoria,
                    pr.nombre AS proveedor,
                    p.laboratorio,
                    p.unidad_medida,
                    p.precio_compra,
                    COALESCE(stock.sucursal, 'SIN ALMACEN') AS sucursal,
                    COALESCE(stock.ubicacion, NULLIF(TRIM(p.ubicacion), ''), 'SIN UBICACION') AS ubicacion,
                    COALESCE(stock.existencia, 0) AS existencia,
                    CASE
                        WHEN COALESCE(stock.existencia, 0) <= 0 THEN 'SIN EXISTENCIA'
                        WHEN COALESCE(stock.existencia, 0) <= {$this->limiteBajoStock} THEN 'STOCK BAJO'
                        ELSE 'STOCK NORMAL'
                    END AS estado_stock
                FROM productos p
                LEFT JOIN categorias c ON p.categoria_id = c.id
                LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
                LEFT JOIN ({$subquery}) stock ON stock.producto_id = p.id
                WHERE p.estado = 1";

        if (!empty($filtros['rack'])) {
            $sql .= " AND stock.producto_id IS NOT NULL";
        }

        if (!empty($filtros['buscar'])) {
            $sql .= " AND (
                p.codigo LIKE :buscar
                OR p.codigo_barras LIKE :buscar
                OR p.descripcion LIKE :buscar
                OR c.nombre LIKE :buscar
                OR pr.nombre LIKE :buscar
                OR p.laboratorio LIKE :buscar
                OR stock.ubicacion LIKE :buscar
            )";
            $params[':buscar'] = '%' . trim($filtros['buscar']) . '%';
        }

        if (!empty($filtros['categoria_id'])) {
            $sql .= " AND p.categoria_id = :categoria_id";
            $params[':categoria_id'] = (int)$filtros['categoria_id'];
        }

        if (!empty($filtros['proveedor_id'])) {
            $sql .= " AND p.proveedor_id = :proveedor_id";
            $params[':proveedor_id'] = (int)$filtros['proveedor_id'];
        }

        if (!empty($filtros['estado_stock'])) {
            if ($filtros['estado_stock'] === 'sin_existencia') {
                $sql .= " AND COALESCE(stock.existencia, 0) <= 0";
            } elseif ($filtros['estado_stock'] === 'bajo') {
                $sql .= " AND COALESCE(stock.existencia, 0) > 0 AND COALESCE(stock.existencia, 0) <= {$this->limiteBajoStock}";
            } elseif ($filtros['estado_stock'] === 'normal') {
                $sql .= " AND COALESCE(stock.existencia, 0) > {$this->limiteBajoStock}";
            }
        }

        $orden = $filtros['orden'] ?? 'descripcion';

        $ordenes = [
            'codigo' => 'p.codigo ASC',
            'descripcion' => 'p.descripcion ASC',
            'existencia_mayor' => 'existencia DESC',
            'existencia_menor' => 'existencia ASC',
            'ubicacion' => 'ubicacion ASC',
        ];

        $sql .= " ORDER BY " . ($ordenes[$orden] ?? 'p.descripcion ASC');

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function resumen(array $productos): array
    {
        $totalProductos = count($productos);
        $totalUnidades = 0;
        $sinExistencia = 0;
        $stockBajo = 0;
        $stockNormal = 0;
        $valorInventario = 0;

        foreach ($productos as $p) {
            $existencia = (int)($p['existencia'] ?? 0);
            $precio = (float)($p['precio_compra'] ?? 0);

            $totalUnidades += $existencia;
            $valorInventario += $existencia * $precio;

            if ($existencia <= 0) {
                $sinExistencia++;
            } elseif ($existencia <= $this->limiteBajoStock) {
                $stockBajo++;
            } else {
                $stockNormal++;
            }
        }

        return compact(
            'totalProductos',
            'totalUnidades',
            'sinExistencia',
            'stockBajo',
            'stockNormal',
            'valorInventario'
        );
    }
}