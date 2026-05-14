<?php

require_once __DIR__ . '/../config/database.php';

class Producto
{
    private PDO $conn;
    private string $table = "productos";

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->connect();
    }

    public function getCategorias(): array
    {
        $sql = "SELECT id, nombre FROM categorias WHERE estado = 1 ORDER BY nombre ASC";
        $stmt = $this->conn->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProveedores(): array
    {
        $sql = "SELECT id, nombre FROM proveedores WHERE estado = 1 ORDER BY nombre ASC";
        $stmt = $this->conn->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAlmacenes(): array
    {
        $sql = "SELECT id, nombre FROM almacenes WHERE estado = 1 ORDER BY nombre ASC";
        $stmt = $this->conn->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAll(string $search = ''): array
    {
        $sql = "SELECT 
                    p.id,
                    p.codigo,
                    p.codigo_barras,
                    p.descripcion,
                    p.laboratorio,
                    p.unidad_medida,
                    p.precio_compra,
                    p.precio_venta,
                    p.stock_minimo,
                    p.stock_maximo,
                    p.ubicacion,
                    p.existencia_actual,
                    p.existencia_bodega,
                    p.existencia_farmacia,
                    p.estado,
                    c.nombre AS categoria,
                    pr.nombre AS proveedor
                FROM productos p
                LEFT JOIN categorias c ON p.categoria_id = c.id
                LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
                WHERE p.estado = 1";

        $params = [];

        if ($search !== '') {
            $sql .= " AND (
                        p.codigo LIKE :search
                        OR p.codigo_barras LIKE :search
                        OR p.descripcion LIKE :search
                        OR p.laboratorio LIKE :search
                        OR p.ubicacion LIKE :search
                    )";
            $params[':search'] = "%{$search}%";
        }

        $sql .= " ORDER BY p.id DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getExistencias(
        string $search = '',
        int $almacenId = 0,
        string $estadoStock = ''
    ): array {

        $params = [];

        $sql = "SELECT 
                    p.id,
                    p.codigo,
                    p.codigo_barras,
                    p.descripcion,
                    p.laboratorio,
                    p.unidad_medida,
                    p.precio_compra,
                    p.precio_venta,
                    p.stock_minimo,
                    p.stock_maximo,
                    p.ubicacion,
                    p.existencia_actual,
                    p.existencia_bodega,
                    p.existencia_farmacia,
                    c.nombre AS categoria,
                    pr.nombre AS proveedor,

                    COALESCE(SUM(
                        CASE 
                            WHEN l.estado = 1 THEN l.existencia
                            ELSE 0
                        END
                    ), 0) AS existencia_consultada

                FROM productos p

                LEFT JOIN categorias c
                    ON p.categoria_id = c.id

                LEFT JOIN proveedores pr
                    ON p.proveedor_id = pr.id

                LEFT JOIN lotes l
                    ON l.producto_id = p.id";

        if ($almacenId > 0) {
            $sql .= " AND l.almacen_id = :almacen_id";
            $params[':almacen_id'] = $almacenId;
        }

        $sql .= " WHERE p.estado = 1";

        if ($search !== '') {
            $sql .= " AND (
                        p.codigo LIKE :search
                        OR p.codigo_barras LIKE :search
                        OR p.descripcion LIKE :search
                        OR p.laboratorio LIKE :search
                        OR p.ubicacion LIKE :search
                        OR c.nombre LIKE :search
                        OR pr.nombre LIKE :search
                    )";

            $params[':search'] = '%' . $search . '%';
        }

        $sql .= " GROUP BY
                    p.id,
                    p.codigo,
                    p.codigo_barras,
                    p.descripcion,
                    p.laboratorio,
                    p.unidad_medida,
                    p.precio_compra,
                    p.precio_venta,
                    p.stock_minimo,
                    p.stock_maximo,
                    p.ubicacion,
                    p.existencia_actual,
                    p.existencia_bodega,
                    p.existencia_farmacia,
                    c.nombre,
                    pr.nombre";

        if ($estadoStock === 'sin_existencia') {
            $sql .= " HAVING existencia_consultada <= 0";
        } elseif ($estadoStock === 'bajo') {
            $sql .= " HAVING existencia_consultada > 0
                      AND existencia_consultada <= stock_minimo";
        } elseif ($estadoStock === 'normal') {
            $sql .= " HAVING existencia_consultada > stock_minimo";
        }

        $sql .= " ORDER BY p.descripcion ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $sql = "SELECT * FROM productos WHERE id = :id LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':id' => $id]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        return $producto ?: null;
    }

    public function existsByCodigo(string $codigo, ?int $excludeId = null): bool
    {
        $sql = "SELECT id FROM productos WHERE codigo = :codigo";
        $params = [':codigo' => $codigo];

        if ($excludeId !== null) {
            $sql .= " AND id != :id";
            $params[':id'] = $excludeId;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create(array $data): bool
    {
        $existenciaBodega = (int)($data['existencia_bodega'] ?? 0);
        $existenciaFarmacia = (int)($data['existencia_farmacia'] ?? 0);
        $existenciaActual = $existenciaBodega + $existenciaFarmacia;

        $sql = "INSERT INTO productos (
                    codigo,
                    codigo_barras,
                    descripcion,
                    categoria_id,
                    proveedor_id,
                    laboratorio,
                    unidad_medida,
                    precio_compra,
                    precio_venta,
                    stock_minimo,
                    stock_maximo,
                    ubicacion,
                    existencia_actual,
                    existencia_bodega,
                    existencia_farmacia,
                    estado
                ) VALUES (
                    :codigo,
                    :codigo_barras,
                    :descripcion,
                    :categoria_id,
                    :proveedor_id,
                    :laboratorio,
                    :unidad_medida,
                    :precio_compra,
                    :precio_venta,
                    :stock_minimo,
                    :stock_maximo,
                    :ubicacion,
                    :existencia_actual,
                    :existencia_bodega,
                    :existencia_farmacia,
                    :estado
                )";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':codigo' => $data['codigo'],
            ':codigo_barras' => $data['codigo_barras'] ?: null,
            ':descripcion' => $data['descripcion'],
            ':categoria_id' => $data['categoria_id'] ?: null,
            ':proveedor_id' => $data['proveedor_id'] ?: null,
            ':laboratorio' => $data['laboratorio'] ?: null,
            ':unidad_medida' => $data['unidad_medida'] ?: null,
            ':precio_compra' => $data['precio_compra'],
            ':precio_venta' => $data['precio_venta'],
            ':stock_minimo' => $data['stock_minimo'],
            ':stock_maximo' => $data['stock_maximo'],
            ':ubicacion' => $data['ubicacion'] ?: null,
            ':existencia_actual' => $existenciaActual,
            ':existencia_bodega' => $existenciaBodega,
            ':existencia_farmacia' => $existenciaFarmacia,
            ':estado' => 1,
        ]);
    }

    public function update(int $id, array $data): bool
    {
        $existenciaBodega = (int)($data['existencia_bodega'] ?? 0);
        $existenciaFarmacia = (int)($data['existencia_farmacia'] ?? 0);
        $existenciaActual = $existenciaBodega + $existenciaFarmacia;

        $sql = "UPDATE productos SET
                    codigo = :codigo,
                    codigo_barras = :codigo_barras,
                    descripcion = :descripcion,
                    categoria_id = :categoria_id,
                    proveedor_id = :proveedor_id,
                    laboratorio = :laboratorio,
                    unidad_medida = :unidad_medida,
                    precio_compra = :precio_compra,
                    precio_venta = :precio_venta,
                    stock_minimo = :stock_minimo,
                    stock_maximo = :stock_maximo,
                    ubicacion = :ubicacion,
                    existencia_actual = :existencia_actual,
                    existencia_bodega = :existencia_bodega,
                    existencia_farmacia = :existencia_farmacia
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':codigo' => $data['codigo'],
            ':codigo_barras' => $data['codigo_barras'] ?: null,
            ':descripcion' => $data['descripcion'],
            ':categoria_id' => $data['categoria_id'] ?: null,
            ':proveedor_id' => $data['proveedor_id'] ?: null,
            ':laboratorio' => $data['laboratorio'] ?: null,
            ':unidad_medida' => $data['unidad_medida'] ?: null,
            ':precio_compra' => $data['precio_compra'],
            ':precio_venta' => $data['precio_venta'],
            ':stock_minimo' => $data['stock_minimo'],
            ':stock_maximo' => $data['stock_maximo'],
            ':ubicacion' => $data['ubicacion'] ?: null,
            ':existencia_actual' => $existenciaActual,
            ':existencia_bodega' => $existenciaBodega,
            ':existencia_farmacia' => $existenciaFarmacia,
            ':id' => $id,
        ]);
    }

    public function deleteLogical(int $id): bool
    {
        $sql = "UPDATE productos SET estado = 0 WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }
}