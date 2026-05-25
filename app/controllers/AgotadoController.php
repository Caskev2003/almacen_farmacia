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

        $sql .= " AND UPPER(pe.sucursal) IN (" . implode(',', $marks) . ") ";
    }

    public function almacenes(): array
    {
        $sql = "SELECT id, nombre 
                FROM almacenes 
                WHERE estado = 1 
                ORDER BY nombre ASC";

        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listar(array $filtros): array
    {
        $params = [];

        $esAdmin = $this->esAdmin();
        $almacenId = (int)($filtros['almacen_id'] ?? 0);

        if (!$esAdmin) {
            $almacenId = $this->almacenIdSesion();
        }

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
                    COALESCE(stock.ubicaciones, NULLIF(TRIM(p.ubicacion), ''), 'SIN UBICACION') AS ubicacion,
                    COALESCE(stock.existencia_total, 0) AS existencia
                FROM productos p
                LEFT JOIN categorias c ON p.categoria_id = c.id
                LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
                LEFT JOIN ({$subquery}) AS stock ON stock.producto_id = p.id
                WHERE p.estado = 1
                AND (
                    COALESCE(stock.existencia_total, 0) <= 0
                    OR COALESCE(stock.ubicaciones, NULLIF(TRIM(p.ubicacion), ''), '') = ''
                    OR COALESCE(stock.ubicaciones, NULLIF(TRIM(p.ubicacion), ''), '') = 'SIN UBICACION'
                )";

        if (!empty($filtros['buscar'])) {
            $sql .= " AND (
                        p.codigo LIKE :buscar
                        OR p.codigo_barras LIKE :buscar
                        OR p.descripcion LIKE :buscar
                        OR c.nombre LIKE :buscar
                        OR pr.nombre LIKE :buscar
                        OR p.laboratorio LIKE :buscar
                    )";

            $params[':buscar'] = '%' . trim($filtros['buscar']) . '%';
        }

        $sql .= " ORDER BY p.descripcion ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}