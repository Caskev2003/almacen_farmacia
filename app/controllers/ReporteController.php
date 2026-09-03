<?php

require_once __DIR__ . '/../config/database.php';

class ReporteController
{
    private PDO $conn;
    private int $limiteBajoStock = 120;
    private string $rol = '';
    private int $almacenId = 0;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $database = new Database();
        $this->conn = $database->connect();

        $usuario = $_SESSION['user'] ?? [];

        $this->rol = strtoupper(trim($usuario['rol'] ?? ''));
        $this->almacenId = (int)($usuario['almacen_id'] ?? 0);
    }

    private function obtenerNombreAlmacenPorId(int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        $stmt = $this->conn->prepare("
            SELECT nombre
            FROM almacenes
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $id
        ]);

        return strtoupper(trim($stmt->fetchColumn() ?: ''));
    }

    private function normalizarSucursales(string $almacen): array
    {
        $almacen = strtoupper(trim($almacen));

        if ($almacen === '') {
            return [];
        }

        if (str_contains($almacen, 'HIDALGO')) {
            return [
                'CIUDAD HIDALGO',
                'CD HIDALGO'
            ];
        }

        if (str_contains($almacen, 'TUXTLA')) {
            return [
                'TUXTLA',
                'TUXTLA GUTIERREZ',
                'TUXTLA GUTIÉRREZ'
            ];
        }

        return [$almacen];
    }

    private function sucursalesPermitidas(array $filtros): array
    {
        if ($this->rol === 'ADMINISTRADOR') {
            if (!empty($filtros['sucursal'])) {
                return $this->normalizarSucursales(
                    (string)$filtros['sucursal']
                );
            }

            return [];
        }

        $almacen = $this->obtenerNombreAlmacenPorId(
            $this->almacenId
        );

        return $this->normalizarSucursales($almacen);
    }

    private function agregarFiltroSucursal(
        string &$sql,
        array &$params,
        array $filtros,
        string $alias = 'pe'
    ): void {
        $sucursales = $this->sucursalesPermitidas($filtros);

        if (empty($sucursales)) {
            return;
        }

        $placeholders = [];

        foreach ($sucursales as $index => $sucursal) {
            $key = ":sucursal_{$alias}_{$index}";

            $placeholders[] = $key;
            $params[$key] = $sucursal;
        }

        $sql .= "
            AND UPPER(COALESCE({$alias}.sucursal, ''))
                COLLATE utf8mb4_general_ci
                IN (" . implode(',', $placeholders) . ")
        ";
    }

    public function obtenerDatosReporte(
        array $filtros,
        array $columnas,
        bool $isAdmin = false,
        string $sucursalUsuario = ''
    ): array {
        $params = [];

        $subquery = "
            SELECT
                pe.producto_id,

                SUM(
                    COALESCE(pe.existencia, 0)
                ) AS existencia,

                GROUP_CONCAT(
                    DISTINCT COALESCE(
                        NULLIF(TRIM(pe.ubicacion), ''),
                        'SIN UBICACION'
                    )
                    ORDER BY pe.ubicacion ASC
                    SEPARATOR ', '
                ) AS ubicacion,

                MIN(
                    NULLIF(TRIM(pe.sucursal), '')
                ) AS sucursal

            FROM producto_existencias pe

            WHERE 1 = 1
        ";

        $this->agregarFiltroSucursal(
            $subquery,
            $params,
            $filtros,
            'pe'
        );

        if (!empty($filtros['rack'])) {
            $subquery .= "
                AND UPPER(
                    COALESCE(pe.ubicacion, '')
                ) LIKE :rack
            ";

            $params[':rack'] =
                strtoupper(trim((string)$filtros['rack'])) . '%';
        }

        $subquery .= "
            GROUP BY pe.producto_id
        ";

        $sql = "
            SELECT
                p.id,
                p.codigo,
                p.codigo_barras,
                p.descripcion,

                c.nombre AS categoria,
                pr.nombre AS proveedor,

                p.laboratorio,
                p.unidad_medida,
                p.precio_compra,
                COALESCE(p.costo_ultimo, p.precio_compra, 0) AS costo_ultimo,
                COALESCE(p.costo_promedio, p.costo_ultimo, p.precio_compra, 0) AS costo_promedio,

                COALESCE(
                    stock.sucursal,
                    :almacen_default
                ) AS sucursal,

                COALESCE(
                    stock.ubicacion,
                    NULLIF(TRIM(p.ubicacion), ''),
                    'SIN UBICACION'
                ) AS ubicacion,

                COALESCE(
                    stock.existencia,
                    0
                ) AS existencia,

                (
                    COALESCE(stock.existencia, 0)
                    * COALESCE(p.costo_promedio, p.costo_ultimo, p.precio_compra, 0)
                ) AS valor_costo_promedio,

                CASE
                    WHEN COALESCE(stock.existencia, 0) <= 0
                    THEN 'AGOTADO'

                    WHEN COALESCE(stock.existencia, 0) > 0
                         AND COALESCE(stock.existencia, 0)
                             <= {$this->limiteBajoStock}
                    THEN 'BAJO STOCK'

                    ELSE 'NORMAL'
                END AS estado_stock

            FROM productos p

            LEFT JOIN categorias c
                ON p.categoria_id = c.id

            LEFT JOIN proveedores pr
                ON p.proveedor_id = pr.id

            LEFT JOIN ({$subquery}) AS stock
                ON stock.producto_id = p.id

            WHERE p.estado = 1
        ";

        $almacenDefault = 'SIN ALMACEN';

        if ($this->rol !== 'ADMINISTRADOR') {
            $almacenDefault =
                $this->obtenerNombreAlmacenPorId(
                    $this->almacenId
                );
        } elseif (!empty($filtros['sucursal'])) {
            $almacenDefault =
                strtoupper(trim((string)$filtros['sucursal']));
        }

        $params[':almacen_default'] = $almacenDefault;

        if (!empty($filtros['rack'])) {
            $sql .= "
                AND stock.producto_id IS NOT NULL
            ";
        }

        /*
         * Cada campo utiliza un parámetro diferente porque
         * PDO::ATTR_EMULATE_PREPARES está configurado en false.
         */
        if (!empty($filtros['buscar'])) {
            $sql .= "
                AND (
                    p.codigo LIKE :buscar_codigo
                    OR p.codigo_barras LIKE :buscar_barras
                    OR p.descripcion LIKE :buscar_descripcion
                    OR c.nombre LIKE :buscar_categoria
                    OR pr.nombre LIKE :buscar_proveedor
                    OR p.laboratorio LIKE :buscar_laboratorio
                    OR stock.ubicacion LIKE :buscar_ubicacion
                )
            ";

            $valorBuscar =
                '%' . trim((string)$filtros['buscar']) . '%';

            $params[':buscar_codigo'] = $valorBuscar;
            $params[':buscar_barras'] = $valorBuscar;
            $params[':buscar_descripcion'] = $valorBuscar;
            $params[':buscar_categoria'] = $valorBuscar;
            $params[':buscar_proveedor'] = $valorBuscar;
            $params[':buscar_laboratorio'] = $valorBuscar;
            $params[':buscar_ubicacion'] = $valorBuscar;
        }

        if (!empty($filtros['existencia'])) {
            $existencia = trim(
                (string)$filtros['existencia']
            );

            if ($existencia === 'agotado') {
                $sql .= "
                    AND COALESCE(stock.existencia, 0) <= 0
                ";
            } elseif ($existencia === 'bajo') {
                $sql .= "
                    AND COALESCE(stock.existencia, 0) > 0
                    AND COALESCE(stock.existencia, 0)
                        <= :limite_bajo
                ";

                $params[':limite_bajo'] =
                    $this->limiteBajoStock;
            } elseif ($existencia === 'normal') {
                $sql .= "
                    AND COALESCE(stock.existencia, 0)
                        > :limite_normal
                ";

                $params[':limite_normal'] =
                    $this->limiteBajoStock;
            }
        }

        $ordenPermitido = [
            'codigo' => 'p.codigo',
            'descripcion' => 'p.descripcion',
            'ubicacion' => 'ubicacion',
            'existencia' => 'existencia',
            'sucursal' => 'sucursal',
        ];

        $orden = trim(
            (string)($filtros['orden'] ?? 'descripcion')
        );

        $ordenSql =
            $ordenPermitido[$orden] ?? 'p.descripcion';

        $sql .= "
            ORDER BY {$ordenSql} ASC
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function obtenerAlmacenes(): array
    {
        $sql = "
            SELECT
                nombre AS sucursal
            FROM almacenes
            WHERE estado = 1
            ORDER BY nombre ASC
        ";

        return $this->conn
            ->query($sql)
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function columnasDisponibles(): array
    {
        $columnas = [
            'codigo' => 'Código',
            'codigo_barras' => 'Código de Barras',
            'descripcion' => 'Descripción',
            'categoria' => 'Categoría',
            'proveedor' => 'Proveedor',
            'laboratorio' => 'Laboratorio',
            'unidad_medida' => 'Unidad',
            'sucursal' => 'Almacén',
            'ubicacion' => 'Ubicación',
            'existencia' => 'Existencia',
        ];

        // Los campos de costos son exclusivos del administrador y se pueden
        // seleccionar de forma independiente para pantalla, Excel y PDF.
        if ($this->rol === 'ADMINISTRADOR') {
            $columnas['costo_ultimo'] = 'Costo último';
            $columnas['costo_promedio'] = 'Costo promedio';
            $columnas['valor_costo_promedio'] = 'Valor a costo promedio';
        }

        $columnas['estado_stock'] = 'Estado Stock';

        return $columnas;
    }
}