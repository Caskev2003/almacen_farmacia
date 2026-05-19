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
        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProveedores(): array
    {
        $sql = "SELECT id, nombre FROM proveedores WHERE estado = 1 ORDER BY nombre ASC";
        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAlmacenes(): array
    {
        $sql = "SELECT id, nombre FROM almacenes WHERE estado = 1 ORDER BY nombre ASC";
        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAll(string $search = '', string $sucursal = '', bool $isAdmin = false): array
{
    $params = [];

    if ($isAdmin) {
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
                    p.estado,
                    c.nombre AS categoria,
                    pr.nombre AS proveedor,
                    COALESCE(SUM(CASE WHEN pe.sucursal = 'CIUDAD HIDALGO' THEN pe.existencia ELSE 0 END), 0) AS existencia_hidalgo,
                    COALESCE(SUM(CASE WHEN pe.sucursal = 'TUXTLA' THEN pe.existencia ELSE 0 END), 0) AS existencia_tuxtla
                FROM productos p
                LEFT JOIN categorias c ON p.categoria_id = c.id
                LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
                LEFT JOIN producto_existencias pe ON pe.producto_id = p.id
                WHERE p.estado = 1";
    } else {
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
                    p.estado,
                    c.nombre AS categoria,
                    pr.nombre AS proveedor,
                    COALESCE(SUM(pe.existencia), 0) AS existencia
                FROM productos p
                LEFT JOIN categorias c ON p.categoria_id = c.id
                LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
                LEFT JOIN producto_existencias pe 
                    ON pe.producto_id = p.id 
                    AND pe.sucursal = :sucursal
                WHERE p.estado = 1";

        $params[':sucursal'] = $sucursal;
    }

    if ($search !== '') {
        $sql .= " AND (
                    p.codigo LIKE :search
                    OR p.codigo_barras LIKE :search
                    OR p.descripcion LIKE :search
                    OR p.laboratorio LIKE :search
                    OR p.ubicacion LIKE :search
                    OR pe.ubicacion LIKE :search
                )";
        $params[':search'] = "%{$search}%";
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
                p.estado,
                c.nombre,
                pr.nombre
              ORDER BY p.descripcion ASC";

    $stmt = $this->conn->prepare($sql);
    $stmt->execute($params);

    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($productos as &$producto) {
        $productoId = (int)$producto['id'];

        if ($isAdmin) {
            $sqlUbi = "SELECT 
                            sucursal,
                            COALESCE(ubicacion, 'SIN UBICACION') AS ubicacion,
                            existencia AS existencia_actual
                       FROM producto_existencias
                       WHERE producto_id = :producto_id
                       ORDER BY sucursal ASC, ubicacion ASC";
            $stmtUbi = $this->conn->prepare($sqlUbi);
            $stmtUbi->execute([
                ':producto_id' => $productoId
            ]);
        } else {
            $sqlUbi = "SELECT 
                            sucursal,
                            COALESCE(ubicacion, 'SIN UBICACION') AS ubicacion,
                            existencia AS existencia_actual
                       FROM producto_existencias
                       WHERE producto_id = :producto_id
                       AND sucursal = :sucursal
                       ORDER BY existencia ASC, ubicacion ASC";
            $stmtUbi = $this->conn->prepare($sqlUbi);
            $stmtUbi->execute([
                ':producto_id' => $productoId,
                ':sucursal' => $sucursal
            ]);
        }

        $producto['ubicaciones'] = $stmtUbi->fetchAll(PDO::FETCH_ASSOC);
    }

    unset($producto);

    return $productos;
}
    public function countAll(string $search = ''): int
    {
        $sql = "SELECT COUNT(*) FROM productos p WHERE p.estado = 1";
        $params = [];

        if ($search !== '') {
            $sql .= " AND (
                        p.codigo LIKE :search
                        OR p.codigo_barras LIKE :search
                        OR p.descripcion LIKE :search
                    )";
            $params[':search'] = "%{$search}%";
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    public function getExistencias(
    string $search = '',
    int $almacenId = 0,
    string $estadoStock = '',
    string $sucursal = '',
    bool $isAdmin = false
): array {

    $params = [];

    if ($isAdmin) {

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
                    p.estado,
                    c.nombre AS categoria,
                    pr.nombre AS proveedor,

                    COALESCE(MAX(
                        CASE 
                            WHEN pe.sucursal = 'CIUDAD HIDALGO'
                            THEN pe.existencia
                        END
                    ), 0) AS existencia_hidalgo,

                    COALESCE(MAX(
                        CASE 
                            WHEN pe.sucursal = 'TUXTLA'
                            THEN pe.existencia
                        END
                    ), 0) AS existencia_tuxtla

                FROM productos p

                LEFT JOIN categorias c
                    ON p.categoria_id = c.id

                LEFT JOIN proveedores pr
                    ON p.proveedor_id = pr.id

                LEFT JOIN producto_existencias pe
                    ON pe.producto_id = p.id

                WHERE p.estado = 1";

    } else {

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
                    p.estado,
                    c.nombre AS categoria,
                    pr.nombre AS proveedor,

                    COALESCE(pe.existencia, 0) AS existencia_consultada

                FROM productos p

                LEFT JOIN categorias c
                    ON p.categoria_id = c.id

                LEFT JOIN proveedores pr
                    ON p.proveedor_id = pr.id

                LEFT JOIN producto_existencias pe
                    ON pe.producto_id = p.id
                    AND pe.sucursal = :sucursal

                WHERE p.estado = 1";

        $params[':sucursal'] = $sucursal;
    }

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

    if ($isAdmin) {

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
                    p.estado,
                    c.nombre,
                    pr.nombre";
    }

    $sql .= " ORDER BY p.descripcion ASC";

    $stmt = $this->conn->prepare($sql);
    $stmt->execute($params);

    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // =========================
    // FILTRO DE STOCK
    // =========================

    if ($estadoStock === '') {
        return $productos;
    }

    $filtrados = [];

    foreach ($productos as $producto) {

        if ($isAdmin) {

            $existencia =
                (int)($producto['existencia_hidalgo'] ?? 0)
                +
                (int)($producto['existencia_tuxtla'] ?? 0);

        } else {

            $existencia = (int)($producto['existencia_consultada'] ?? 0);
        }

        $stockMinimo = (int)($producto['stock_minimo'] ?? 0);

        $agregar = false;

        switch ($estadoStock) {

            case 'sin_existencia':

                if ($existencia <= 0) {
                    $agregar = true;
                }

            break;

            case 'bajo':

                if (
                    $existencia > 0
                    &&
                    $stockMinimo > 0
                    &&
                    $existencia <= $stockMinimo
                ) {
                    $agregar = true;
                }

            break;

            case 'normal':

                if (
                    $existencia > $stockMinimo
                ) {
                    $agregar = true;
                }

            break;

            default:
                $agregar = true;
            break;
        }

        if ($agregar) {
            $filtrados[] = $producto;
        }
    }

    return $filtrados;
}

    public function findById(int $id): ?array
    {
        $sql = "SELECT * FROM productos WHERE id = :id LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':id' => $id]);

        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        return $producto ?: null;
    }

    public function findByCodigo(string $codigo): ?array
    {
        $sql = "SELECT * 
                FROM productos 
                WHERE codigo = :codigo 
                   OR codigo_barras = :codigo
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':codigo' => $codigo]);

        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        return $producto ?: null;
    }

    public function existsByCodigo(string $codigo, ?int $excludeId = null): bool
    {
        $sql = "SELECT id
                FROM productos
                WHERE codigo = :codigo";

        $params = [
            ':codigo' => $codigo
        ];

        if ($excludeId !== null) {
            $sql .= " AND id != :id";
            $params[':id'] = $excludeId;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create(array $data): bool
    {
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
                    existencia_bodega,
                    sucursal,
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
                    0,
                    NULL,
                    1
                )";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':codigo' => $data['codigo'],
            ':codigo_barras' => $data['codigo_barras'] ?: $data['codigo'],
            ':descripcion' => $data['descripcion'],
            ':categoria_id' => $data['categoria_id'] ?: null,
            ':proveedor_id' => $data['proveedor_id'] ?: null,
            ':laboratorio' => $data['laboratorio'] ?: null,
            ':unidad_medida' => $data['unidad_medida'] ?: null,
            ':precio_compra' => $data['precio_compra'] ?? 0,
            ':precio_venta' => $data['precio_venta'] ?? 0,
            ':stock_minimo' => $data['stock_minimo'] ?? 0,
            ':stock_maximo' => $data['stock_maximo'] ?? 0,
            ':ubicacion' => $data['ubicacion'] ?: null
        ]);
    }

    public function update(int $id, array $data): bool
    {
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
                    ubicacion = :ubicacion
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':codigo' => $data['codigo'],
            ':codigo_barras' => $data['codigo_barras'] ?: $data['codigo'],
            ':descripcion' => $data['descripcion'],
            ':categoria_id' => $data['categoria_id'] ?: null,
            ':proveedor_id' => $data['proveedor_id'] ?: null,
            ':laboratorio' => $data['laboratorio'] ?: null,
            ':unidad_medida' => $data['unidad_medida'] ?: null,
            ':precio_compra' => $data['precio_compra'] ?? 0,
            ':precio_venta' => $data['precio_venta'] ?? 0,
            ':stock_minimo' => $data['stock_minimo'] ?? 0,
            ':stock_maximo' => $data['stock_maximo'] ?? 0,
            ':ubicacion' => $data['ubicacion'] ?: null,
            ':id' => $id
        ]);
    }

    public function crearOActualizarCatalogo(array $data): bool
    {
        $codigo = trim($data['codigo']);

        $producto = $this->findByCodigo($codigo);

        if ($producto) {
            return $this->update((int)$producto['id'], [
                'codigo' => $codigo,
                'codigo_barras' => $data['codigo_barras'] ?: $codigo,
                'descripcion' => $data['descripcion'],
                'categoria_id' => $data['categoria_id'] ?? null,
                'proveedor_id' => $data['proveedor_id'] ?? null,
                'laboratorio' => $data['laboratorio'] ?? null,
                'unidad_medida' => $data['unidad_medida'] ?? null,
                'precio_compra' => $data['precio_compra'] ?? 0,
                'precio_venta' => $data['precio_venta'] ?? 0,
                'stock_minimo' => $data['stock_minimo'] ?? 0,
                'stock_maximo' => $data['stock_maximo'] ?? 0,
                'ubicacion' => $data['ubicacion'] ?? null
            ]);
        }

        return $this->create([
            'codigo' => $codigo,
            'codigo_barras' => $data['codigo_barras'] ?: $codigo,
            'descripcion' => $data['descripcion'],
            'categoria_id' => $data['categoria_id'] ?? null,
            'proveedor_id' => $data['proveedor_id'] ?? null,
            'laboratorio' => $data['laboratorio'] ?? null,
            'unidad_medida' => $data['unidad_medida'] ?? null,
            'precio_compra' => $data['precio_compra'] ?? 0,
            'precio_venta' => $data['precio_venta'] ?? 0,
            'stock_minimo' => $data['stock_minimo'] ?? 0,
            'stock_maximo' => $data['stock_maximo'] ?? 0,
            'ubicacion' => $data['ubicacion'] ?? null
        ]);
    }

    public function actualizarExistenciaPorCodigo(
    string $codigo,
    string $sucursal,
    int $existencia,
    string $ubicacion = 'SIN UBICACION'
): bool {

    $producto = $this->findByCodigo($codigo);

    if (!$producto) {
        return false;
    }

    $ubicacion = strtoupper(trim($ubicacion));

    if ($ubicacion === '') {
        $ubicacion = 'SIN UBICACION';
    }

    $sql = "INSERT INTO producto_existencias (
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

    $stmt = $this->conn->prepare($sql);

    return $stmt->execute([
        ':producto_id' => (int)$producto['id'],
        ':sucursal' => $sucursal,
        ':ubicacion' => $ubicacion,
        ':existencia' => $existencia
    ]);
}

    public function deleteLogical(int $id): bool
    {
        $sql = "UPDATE productos SET estado = 0 WHERE id = :id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':id' => $id
        ]);
    }
}