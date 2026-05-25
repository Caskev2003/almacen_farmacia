<?php

require_once __DIR__ . '/../config/database.php';

class Producto
{
    private PDO $conn;
    private string $table = "productos";
    private int $limiteBajoStock = 120;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->connect();
    }

    public function getCategorias(): array
    {
        $sql = "SELECT id, nombre 
                FROM categorias 
                WHERE estado = 1 
                ORDER BY nombre ASC";

        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProveedores(): array
    {
        $sql = "SELECT id, nombre 
                FROM proveedores 
                WHERE estado = 1 
                ORDER BY nombre ASC";

        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAlmacenes(): array
    {
        $sql = "SELECT id, nombre 
                FROM almacenes 
                WHERE estado = 1 
                ORDER BY nombre ASC";

        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function obtenerOCrearProveedor(string $nombre): ?int
    {
        $nombre = strtoupper(trim($nombre));

        if ($nombre === '') {
            return null;
        }

        $sql = "SELECT id
                FROM proveedores
                WHERE UPPER(nombre) = :nombre
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':nombre' => $nombre
        ]);

        $id = $stmt->fetchColumn();

        if ($id) {
            return (int)$id;
        }

        $sqlInsert = "INSERT INTO proveedores (nombre, estado)
                      VALUES (:nombre, 1)";

        $stmtInsert = $this->conn->prepare($sqlInsert);
        $stmtInsert->execute([
            ':nombre' => $nombre
        ]);

        return (int)$this->conn->lastInsertId();
    }

    private function calcularEstadoStock(int $existencia): string
    {
        if ($existencia <= 0) {
            return 'agotado';
        }

        if ($existencia <= $this->limiteBajoStock) {
            return 'bajo';
        }

        return 'normal';
    }

    public function getAll(
        string $search = '',
        string $sucursal = '',
        bool $isAdmin = false,
        string $categoriaId = '',
        string $proveedor = '',
        string $ubicacion = '',
        string $estadoStock = ''
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

                        COALESCE(SUM(
                            CASE 
                                WHEN pe.sucursal = 'CIUDAD HIDALGO'
                                THEN pe.existencia 
                                ELSE 0 
                            END
                        ), 0) AS existencia_hidalgo,

                        COALESCE(SUM(
                            CASE 
                                WHEN pe.sucursal = 'TUXTLA'
                                THEN pe.existencia 
                                ELSE 0 
                            END
                        ), 0) AS existencia_tuxtla,

                        COALESCE(SUM(pe.existencia), 0) AS existencia_total

                    FROM productos p

                    LEFT JOIN categorias c 
                        ON p.categoria_id = c.id

                    LEFT JOIN proveedores pr 
                        ON p.proveedor_id = pr.id

                    LEFT JOIN producto_existencias pe 
                        ON pe.producto_id = p.id
                        AND pe.existencia > 0

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

                        COALESCE(SUM(pe.existencia), 0) AS existencia,
                        COALESCE(SUM(pe.existencia), 0) AS existencia_total

                    FROM productos p

                    LEFT JOIN categorias c 
                        ON p.categoria_id = c.id

                    LEFT JOIN proveedores pr 
                        ON p.proveedor_id = pr.id

                    LEFT JOIN producto_existencias pe 
                        ON pe.producto_id = p.id
                        AND pe.sucursal = :sucursal
                        AND pe.existencia > 0

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
                        OR pr.nombre LIKE :search
                        OR pe.ubicacion LIKE :search
                    )";

            $params[':search'] = "%{$search}%";
        }

        if ($categoriaId !== '') {
            $sql .= " AND p.categoria_id = :categoria_id";
            $params[':categoria_id'] = (int)$categoriaId;
        }

        if ($proveedor !== '') {
            $sql .= " AND pr.nombre LIKE :proveedor";
            $params[':proveedor'] = "%{$proveedor}%";
        }

        if ($ubicacion !== '') {
            $sql .= " AND (
                        p.ubicacion LIKE :ubicacion
                        OR pe.ubicacion LIKE :ubicacion
                    )";
            $params[':ubicacion'] = "%{$ubicacion}%";
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
                           AND existencia > 0
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
                           AND existencia > 0
                           ORDER BY existencia ASC, ubicacion ASC";

                $stmtUbi = $this->conn->prepare($sqlUbi);
                $stmtUbi->execute([
                    ':producto_id' => $productoId,
                    ':sucursal' => $sucursal
                ]);
            }

            $producto['ubicaciones'] = $stmtUbi->fetchAll(PDO::FETCH_ASSOC);

            if (empty($producto['ubicaciones'])) {
                $producto['ubicacion'] = '';
            }

            $existencia = $isAdmin
                ? (int)($producto['existencia_total'] ?? 0)
                : (int)($producto['existencia'] ?? 0);

            $producto['estado_stock'] = $this->calcularEstadoStock($existencia);
            $producto['estado_stock_texto'] = match ($producto['estado_stock']) {
                'agotado' => 'AGOTADO',
                'bajo' => 'BAJO STOCK',
                default => 'NORMAL',
            };
        }

        unset($producto);

        if ($estadoStock !== '') {
            $productos = array_values(array_filter($productos, function ($producto) use ($estadoStock) {
                return ($producto['estado_stock'] ?? '') === $estadoStock;
            }));
        }

        return $productos;
    }

    public function countAll(string $search = ''): int
    {
        $sql = "SELECT COUNT(*) 
                FROM productos p 
                WHERE p.estado = 1";

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

    public function findById(int $id): ?array
    {
        $sql = "SELECT 
                    p.*,
                    pr.nombre AS proveedor_nombre
                FROM productos p
                LEFT JOIN proveedores pr
                    ON p.proveedor_id = pr.id
                WHERE p.id = :id
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':id' => $id
        ]);

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
        $stmt->execute([
            ':codigo' => $codigo
        ]);

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
        $proveedorId = $this->obtenerOCrearProveedor($data['proveedor_nombre'] ?? '');

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
                    0,
                    0,
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
            ':proveedor_id' => $proveedorId,
            ':laboratorio' => $data['laboratorio'] ?: null,
            ':unidad_medida' => $data['unidad_medida'] ?: null,
            ':precio_compra' => $data['precio_compra'] ?? 0,
            ':precio_venta' => $data['precio_venta'] ?? 0,
            ':ubicacion' => $data['ubicacion'] ?: null
        ]);
    }

    public function update(int $id, array $data): bool
    {
        $proveedorId = $this->obtenerOCrearProveedor($data['proveedor_nombre'] ?? '');

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
                    stock_minimo = 0,
                    stock_maximo = 0,
                    ubicacion = :ubicacion
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':codigo' => $data['codigo'],
            ':codigo_barras' => $data['codigo_barras'] ?: $data['codigo'],
            ':descripcion' => $data['descripcion'],
            ':categoria_id' => $data['categoria_id'] ?: null,
            ':proveedor_id' => $proveedorId,
            ':laboratorio' => $data['laboratorio'] ?: null,
            ':unidad_medida' => $data['unidad_medida'] ?: null,
            ':precio_compra' => $data['precio_compra'] ?? 0,
            ':precio_venta' => $data['precio_venta'] ?? 0,
            ':ubicacion' => $data['ubicacion'] ?: null,
            ':id' => $id
        ]);
    }

    public function crearOActualizarCatalogo(array $data): bool
    {
        $codigo = trim($data['codigo']);

        $producto = $this->findByCodigo($codigo);

        $payload = [
            'codigo' => $codigo,
            'codigo_barras' => $data['codigo_barras'] ?: $codigo,
            'descripcion' => $data['descripcion'],
            'categoria_id' => $data['categoria_id'] ?? null,
            'proveedor_nombre' => $data['proveedor_nombre'] ?? '',
            'laboratorio' => $data['laboratorio'] ?? null,
            'unidad_medida' => $data['unidad_medida'] ?? null,
            'precio_compra' => $data['precio_compra'] ?? 0,
            'precio_venta' => $data['precio_venta'] ?? 0,
            'stock_minimo' => 0,
            'stock_maximo' => 0,
            'ubicacion' => $data['ubicacion'] ?? null
        ];

        if ($producto) {
            return $this->update((int)$producto['id'], $payload);
        }

        return $this->create($payload);
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

    public function actualizarUbicacionExistencia(
        int $productoId,
        string $sucursal,
        string $ubicacionAnterior,
        string $ubicacionNueva,
        int $existencia
    ): bool {
        $ubicacionAnterior = strtoupper(trim($ubicacionAnterior));
        $ubicacionNueva = strtoupper(trim($ubicacionNueva));

        if ($ubicacionAnterior === '') {
            $ubicacionAnterior = 'SIN UBICACION';
        }

        if ($ubicacionNueva === '') {
            $ubicacionNueva = 'SIN UBICACION';
        }

        if ($existencia < 0) {
            $existencia = 0;
        }

        try {
            $this->conn->beginTransaction();

            $sqlExiste = "SELECT id, existencia
                          FROM producto_existencias
                          WHERE producto_id = :producto_id
                          AND sucursal = :sucursal
                          AND ubicacion = :ubicacion";

            $stmtExiste = $this->conn->prepare($sqlExiste);
            $stmtExiste->execute([
                ':producto_id' => $productoId,
                ':sucursal' => $sucursal,
                ':ubicacion' => $ubicacionNueva
            ]);

            $registroNuevo = $stmtExiste->fetch(PDO::FETCH_ASSOC);

            if ($registroNuevo && $ubicacionAnterior !== $ubicacionNueva) {
                $sqlActualizarNueva = "UPDATE producto_existencias
                                       SET existencia = :existencia,
                                           updated_at = CURRENT_TIMESTAMP
                                       WHERE id = :id";

                $stmtActualizarNueva = $this->conn->prepare($sqlActualizarNueva);
                $stmtActualizarNueva->execute([
                    ':existencia' => $existencia,
                    ':id' => $registroNuevo['id']
                ]);

                $sqlEliminarAnterior = "DELETE FROM producto_existencias
                                        WHERE producto_id = :producto_id
                                        AND sucursal = :sucursal
                                        AND ubicacion = :ubicacion";

                $stmtEliminarAnterior = $this->conn->prepare($sqlEliminarAnterior);
                $stmtEliminarAnterior->execute([
                    ':producto_id' => $productoId,
                    ':sucursal' => $sucursal,
                    ':ubicacion' => $ubicacionAnterior
                ]);
            } else {
                $sql = "UPDATE producto_existencias
                        SET ubicacion = :ubicacion_nueva,
                            existencia = :existencia,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE producto_id = :producto_id
                        AND sucursal = :sucursal
                        AND ubicacion = :ubicacion_anterior";

                $stmt = $this->conn->prepare($sql);
                $stmt->execute([
                    ':ubicacion_nueva' => $ubicacionNueva,
                    ':existencia' => $existencia,
                    ':producto_id' => $productoId,
                    ':sucursal' => $sucursal,
                    ':ubicacion_anterior' => $ubicacionAnterior
                ]);
            }

            $sqlProducto = "UPDATE productos
                            SET ubicacion = :ubicacion
                            WHERE id = :producto_id";

            $stmtProducto = $this->conn->prepare($sqlProducto);
            $stmtProducto->execute([
                ':ubicacion' => $ubicacionNueva,
                ':producto_id' => $productoId
            ]);

            $this->conn->commit();

            return true;
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            return false;
        }
    }

    public function deleteLogical(int $id): bool
    {
        $sql = "UPDATE productos 
                SET estado = 0 
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':id' => $id
        ]);
    }

    public function eliminarUbicacionExistencia(
        int $productoId,
        string $sucursal,
        string $ubicacion
    ): bool {
        $ubicacion = strtoupper(trim($ubicacion));

        if ($ubicacion === '') {
            $ubicacion = 'SIN UBICACION';
        }

        try {
            $this->conn->beginTransaction();

            $sqlDelete = "DELETE FROM producto_existencias
                          WHERE producto_id = :producto_id
                          AND sucursal = :sucursal
                          AND ubicacion = :ubicacion";

            $stmtDelete = $this->conn->prepare($sqlDelete);
            $stmtDelete->execute([
                ':producto_id' => $productoId,
                ':sucursal' => $sucursal,
                ':ubicacion' => $ubicacion
            ]);

            $sqlProducto = "UPDATE productos
                            SET ubicacion = NULL
                            WHERE id = :producto_id
                            AND ubicacion = :ubicacion";

            $stmtProducto = $this->conn->prepare($sqlProducto);
            $stmtProducto->execute([
                ':producto_id' => $productoId,
                ':ubicacion' => $ubicacion
            ]);

            $this->conn->commit();

            return true;
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            return false;
        }
    }
    public function getExistencias(
    string $buscar = '',
    int $almacenId = 0,
    string $estadoStock = '',
    string $sucursal = '',
    bool $isAdmin = false
): array {
    return $this->getAll(
        $buscar,
        $sucursal,
        $isAdmin,
        '',
        '',
        '',
        $estadoStock
    );
}
}