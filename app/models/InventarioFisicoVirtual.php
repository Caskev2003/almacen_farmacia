<?php

require_once __DIR__ . '/../config/database.php';

class InventarioFisicoVirtual
{
    private PDO $conn;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->connect();
    }

    public function generarFolio(): string
    {
        $prefijo = 'INV-TUX-';
        $fecha = date('Ymd');

        $sql = "SELECT COUNT(*) total
                FROM inventario_fisico_conteos
                WHERE DATE(created_at) = CURDATE()";

        $stmt = $this->conn->query($sql);

        $total = (int)$stmt->fetch()['total'] + 1;

        return $prefijo . $fecha . '-' . str_pad((string)$total, 4, '0', STR_PAD_LEFT);
    }

    public function buscarProductoPorCodigo(string $codigo): ?array
    {
        $sql = "SELECT
                    id,
                    codigo_barras,
                    descripcion,
                    ubicacion
                FROM productos
                WHERE codigo_barras = :codigo
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            ':codigo' => trim($codigo)
        ]);

        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        return $producto ?: null;
    }

    public function crearConteo(array $data): int|false
    {
        try {

            $sql = "INSERT INTO inventario_fisico_conteos
                    (
                        folio,
                        almacen_id,
                        usuario_id,
                        estado,
                        observaciones
                    )
                    VALUES
                    (
                        :folio,
                        :almacen_id,
                        :usuario_id,
                        'ABIERTO',
                        :observaciones
                    )";

            $stmt = $this->conn->prepare($sql);

            $ok = $stmt->execute([
                ':folio' => $data['folio'],
                ':almacen_id' => $data['almacen_id'],
                ':usuario_id' => $data['usuario_id'],
                ':observaciones' => $data['observaciones'] ?? null,
            ]);

            if (!$ok) {
                return false;
            }

            return (int)$this->conn->lastInsertId();

        } catch (Throwable $e) {

            return false;
        }
    }

    public function guardarDetalle(int $inventarioId, array $detalle): bool
    {
        try {

            $this->conn->beginTransaction();

            $sql = "INSERT INTO inventario_fisico_detalle
        (
            conteo_id,
            producto_id,
            codigo_barras,
            descripcion,
            mostrador,
            piqueo,
            almacen,
            bodega,
            total
        )
        VALUES
        (
            :inventario_id,
            :producto_id,
            :codigo_barras,
            :descripcion,
            :mostrador,
            :piqueo,
            :almacen,
            :bodega,
            :total
        )";

            $stmt = $this->conn->prepare($sql);

            foreach ($detalle as $item) {

                $mostrador = (int)($item['mostrador'] ?? 0);
                $piqueo = (int)($item['piqueo'] ?? 0);
                $almacen = (int)($item['almacen'] ?? 0);
                $bodega = (int)($item['bodega'] ?? 0);

                $total =
                    $mostrador +
                    $piqueo +
                    $almacen +
                    $bodega;

                $stmt->execute([
                    ':inventario_id' => $inventarioId,
                    ':producto_id' => $item['producto_id'] ?? null,
                    ':codigo_barras' => trim($item['codigo_barras'] ?? ''),
                    ':descripcion' => trim($item['descripcion'] ?? ''),
                    ':mostrador' => $mostrador,
                    ':piqueo' => $piqueo,
                    ':almacen' => $almacen,
                    ':bodega' => $bodega,
                    ':total' => $total,
                ]);
            }

            $this->conn->commit();

            return true;

        } catch (Throwable $e) {

            $this->conn->rollBack();

            return false;
        }
    }

    public function cerrarConteo(int $inventarioId): bool
    {
        $sql = "UPDATE inventario_fisico_conteos
                SET
                    estado = 'CERRADO',
                    cerrado_at = NOW()
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':id' => $inventarioId
        ]);
    }

    public function obtenerConteos(): array
    {
        $sql = "SELECT
                    i.*,
                    a.nombre AS almacen_nombre,
                    u.nombre AS usuario_nombre
                FROM inventario_fisico_conteos i
                INNER JOIN almacenes a
                    ON i.almacen_id = a.id
                INNER JOIN usuarios u
                    ON i.usuario_id = u.id
                ORDER BY i.id DESC";

        $stmt = $this->conn->query($sql);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerConteoPorId(int $id): ?array
    {
        $sql = "SELECT *
                FROM inventario_fisico_conteos
                WHERE id = :id
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            ':id' => $id
        ]);

        $conteo = $stmt->fetch(PDO::FETCH_ASSOC);

        return $conteo ?: null;
    }

    public function obtenerDetalle(int $inventarioId): array
    {
        $sql = "SELECT *
        FROM inventario_fisico_detalle
        WHERE conteo_id = :inventario_id
        ORDER BY id ASC";

        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            ':inventario_id' => $inventarioId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function eliminarConteo(int $id): bool
    {
        $sql = "DELETE FROM inventario_fisico_conteos
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':id' => $id
        ]);
    }
}