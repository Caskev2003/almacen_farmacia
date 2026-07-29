<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class Devolucion
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        if ($db instanceof PDO) {
            $this->db = $db;
            return;
        }

        $database = new Database();
        $this->db = $database->connect();
    }

    public function buscarProductos(string $termino): array
    {
        $termino = trim($termino);

        if ($termino === '') {
            return [];
        }

        $like = '%' . $termino . '%';

        $sql = "
            SELECT
                id,
                codigo,
                codigo_barras,
                descripcion,
                unidad_medida
            FROM productos
            WHERE estado = 1
              AND (
                    codigo LIKE :codigo_like
                    OR codigo_barras LIKE :barras_like
                    OR descripcion LIKE :descripcion_like
              )
            ORDER BY
                CASE
                    WHEN codigo = :codigo_exacto THEN 0
                    WHEN codigo_barras = :barras_exacto THEN 1
                    WHEN codigo LIKE :codigo_inicio THEN 2
                    ELSE 3
                END,
                descripcion ASC
            LIMIT 30
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':codigo_like' => $like,
            ':barras_like' => $like,
            ':descripcion_like' => $like,
            ':codigo_exacto' => $termino,
            ':barras_exacto' => $termino,
            ':codigo_inicio' => $termino . '%',
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerProducto(int $productoId): ?array
    {
        $sql = "
            SELECT
                id,
                codigo,
                codigo_barras,
                descripcion,
                unidad_medida
            FROM productos
            WHERE id = :id
              AND estado = 1
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $productoId,
        ]);

        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($producto) ? $producto : null;
    }

    public function obtenerAlmacenesActivos(): array
    {
        $sql = "
            SELECT id, nombre
            FROM almacenes
            WHERE estado = 1
            ORDER BY nombre ASC
        ";

        return $this->db
            ->query($sql)
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function almacenActivoExiste(int $almacenId): bool
    {
        $sql = "
            SELECT 1
            FROM almacenes
            WHERE id = :id
              AND estado = 1
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $almacenId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public function crear(array $datos): int
    {
        $sql = "
            INSERT INTO devoluciones (
                producto_id,
                codigo,
                descripcion,
                piezas,
                anio,
                mes,
                motivo,
                estatus,
                fecha,
                ubicacion,
                observaciones,
                almacen_id,
                usuario_id
            ) VALUES (
                :producto_id,
                :codigo,
                :descripcion,
                :piezas,
                :anio,
                :mes,
                :motivo,
                :estatus,
                :fecha,
                :ubicacion,
                :observaciones,
                :almacen_id,
                :usuario_id
            )
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':producto_id' => $datos['producto_id'],
            ':codigo' => $datos['codigo'],
            ':descripcion' => $datos['descripcion'],
            ':piezas' => $datos['piezas'],
            ':anio' => $datos['anio'],
            ':mes' => $datos['mes'],
            ':motivo' => $datos['motivo'],
            ':estatus' => $datos['estatus'],
            ':fecha' => $datos['fecha'],
            ':ubicacion' => $datos['ubicacion'],
            ':observaciones' => $datos['observaciones'],
            ':almacen_id' => $datos['almacen_id'],
            ':usuario_id' => $datos['usuario_id'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function actualizar(
        int $id,
        array $datos,
        ?int $almacenLimite = null
    ): bool {
        $sql = "
            UPDATE devoluciones
            SET
                producto_id = :producto_id,
                codigo = :codigo,
                descripcion = :descripcion,
                piezas = :piezas,
                anio = :anio,
                mes = :mes,
                motivo = :motivo,
                estatus = :estatus,
                fecha = :fecha,
                ubicacion = :ubicacion,
                observaciones = :observaciones,
                almacen_id = :almacen_id
            WHERE id = :id
        ";

        $params = [
            ':producto_id' => $datos['producto_id'],
            ':codigo' => $datos['codigo'],
            ':descripcion' => $datos['descripcion'],
            ':piezas' => $datos['piezas'],
            ':anio' => $datos['anio'],
            ':mes' => $datos['mes'],
            ':motivo' => $datos['motivo'],
            ':estatus' => $datos['estatus'],
            ':fecha' => $datos['fecha'],
            ':ubicacion' => $datos['ubicacion'],
            ':observaciones' => $datos['observaciones'],
            ':almacen_id' => $datos['almacen_id'],
            ':id' => $id,
        ];

        if ($almacenLimite !== null) {
            $sql .= ' AND almacen_id = :almacen_limite';
            $params[':almacen_limite'] = $almacenLimite;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function actualizarTicket(
        int $id,
        bool $tieneTicket,
        ?int $almacenLimite = null
    ): bool {
        $sql = "
            UPDATE devoluciones
            SET tiene_ticket = :tiene_ticket
            WHERE id = :id
        ";

        $params = [
            ':tiene_ticket' => $tieneTicket ? 1 : 0,
            ':id' => $id,
        ];

        if ($almacenLimite !== null) {
            $sql .= ' AND almacen_id = :almacen_limite';
            $params[':almacen_limite'] = $almacenLimite;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function eliminar(
        int $id,
        ?int $almacenLimite = null
    ): bool {
        $sql = "
            DELETE FROM devoluciones
            WHERE id = :id
        ";

        $params = [
            ':id' => $id,
        ];

        if ($almacenLimite !== null) {
            $sql .= ' AND almacen_id = :almacen_limite';
            $params[':almacen_limite'] = $almacenLimite;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function obtenerPorId(
        int $id,
        ?int $almacenLimite = null
    ): ?array {
        $sql = "
            SELECT
                d.*,
                a.nombre AS almacen_nombre,
                u.nombre AS usuario_nombre
            FROM devoluciones d
            INNER JOIN almacenes a
                ON a.id = d.almacen_id
            LEFT JOIN usuarios u
                ON u.id = d.usuario_id
            WHERE d.id = :id
        ";

        $params = [
            ':id' => $id,
        ];

        if ($almacenLimite !== null) {
            $sql .= ' AND d.almacen_id = :almacen_limite';
            $params[':almacen_limite'] = $almacenLimite;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $registro = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($registro) ? $registro : null;
    }

    public function listar(
        ?int $almacenLimite = null,
        array $filtros = []
    ): array {
        $sql = "
            SELECT
                d.*,
                a.nombre AS almacen_nombre,
                u.nombre AS usuario_nombre
            FROM devoluciones d
            INNER JOIN almacenes a
                ON a.id = d.almacen_id
            LEFT JOIN usuarios u
                ON u.id = d.usuario_id
            WHERE 1 = 1
        ";

        $params = [];

        if ($almacenLimite !== null) {
            $sql .= ' AND d.almacen_id = :almacen_limite';
            $params[':almacen_limite'] = $almacenLimite;
        }

        $texto = trim((string) ($filtros['texto'] ?? ''));

        if ($texto !== '') {
            $like = '%' . $texto . '%';
            $sql .= "
                AND (
                    d.codigo LIKE :texto_codigo
                    OR d.descripcion LIKE :texto_descripcion
                    OR d.motivo LIKE :texto_motivo
                    OR d.observaciones LIKE :texto_observaciones
                )
            ";
            $params[':texto_codigo'] = $like;
            $params[':texto_descripcion'] = $like;
            $params[':texto_motivo'] = $like;
            $params[':texto_observaciones'] = $like;
        }

        $estatus = strtoupper(
            trim((string) ($filtros['estatus'] ?? ''))
        );

        if ($estatus !== '') {
            $sql .= ' AND d.estatus = :estatus';
            $params[':estatus'] = $estatus;
        }

        $ubicacion = strtoupper(
            trim((string) ($filtros['ubicacion'] ?? ''))
        );

        if ($ubicacion !== '') {
            $sql .= ' AND d.ubicacion = :ubicacion';
            $params[':ubicacion'] = $ubicacion;
        }

        $ticket = strtoupper(
            trim((string) ($filtros['ticket'] ?? ''))
        );

        if ($ticket === 'CON') {
            $sql .= ' AND d.tiene_ticket = 1';
        } elseif ($ticket === 'SIN') {
            $sql .= ' AND d.tiene_ticket = 0';
        }

        $sql .= "
            ORDER BY
                CASE d.estatus
                    WHEN 'PENDIENTE' THEN 0
                    WHEN 'EN_PROCESO' THEN 1
                    WHEN 'DEVUELTO' THEN 2
                    WHEN 'CANCELADO' THEN 3
                    ELSE 4
                END ASC,
                d.fecha DESC,
                d.id DESC
            LIMIT 500
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
