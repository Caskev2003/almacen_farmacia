<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class EntradaBorrador
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        if ($db instanceof PDO) {
            $this->db = $db;
        } else {
            $database = new Database();
            $this->db = $database->connect();
        }

        $this->db->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        $this->db->setAttribute(
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::FETCH_ASSOC
        );
    }

    public function guardar(
        int $usuarioId,
        int $almacenId,
        string $nombre,
        array $datos,
        ?int $borradorId = null
    ): array {
        if ($usuarioId <= 0) {
            throw new InvalidArgumentException(
                'No se pudo identificar al usuario del borrador.'
            );
        }

        if ($almacenId <= 0) {
            throw new InvalidArgumentException(
                'Seleccione un almacén antes de guardar el borrador.'
            );
        }

        $productos = $datos['productos'] ?? [];

        if (!is_array($productos)) {
            $productos = [];
            $datos['productos'] = [];
        }

        if (count($productos) > 1000) {
            throw new InvalidArgumentException(
                'El borrador no puede contener más de 1000 productos.'
            );
        }

        $nombre = preg_replace(
            '/\s+/u',
            ' ',
            trim($nombre)
        );

        if (!is_string($nombre) || $nombre === '') {
            $nombre = 'Borrador '
                . date('d/m/Y H:i');
        }

        if (mb_strlen($nombre) > 150) {
            $nombre = mb_substr(
                $nombre,
                0,
                150
            );
        }

        $folio = trim(
            (string) ($datos['folio'] ?? '')
        );

        if (mb_strlen($folio) > 50) {
            $folio = mb_substr($folio, 0, 50);
        }

        $datosJson = json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );

        if (strlen($datosJson) > 8 * 1024 * 1024) {
            throw new InvalidArgumentException(
                'El borrador es demasiado grande para guardarse.'
            );
        }

        if ($borradorId !== null && $borradorId > 0) {
            $sql = "
                UPDATE entrada_borradores
                SET
                    almacen_id = :almacen_id,
                    nombre = :nombre,
                    folio = :folio,
                    total_productos = :total_productos,
                    datos_json = :datos_json,
                    actualizado_en = NOW()
                WHERE
                    id = :id
                    AND usuario_id = :usuario_id
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':almacen_id' => $almacenId,
                ':nombre' => $nombre,
                ':folio' => $folio !== '' ? $folio : null,
                ':total_productos' => count($productos),
                ':datos_json' => $datosJson,
                ':id' => $borradorId,
                ':usuario_id' => $usuarioId
            ]);

            if ($stmt->rowCount() === 0) {
                $existente = $this->obtener(
                    $borradorId,
                    $usuarioId
                );

                if (!$existente) {
                    throw new InvalidArgumentException(
                        'No se encontró el borrador que desea actualizar.'
                    );
                }
            }

            return [
                'id' => $borradorId,
                'nombre' => $nombre,
                'total_productos' => count($productos),
                'actualizado' => true
            ];
        }

        $sql = "
            INSERT INTO entrada_borradores (
                usuario_id,
                almacen_id,
                nombre,
                folio,
                total_productos,
                datos_json,
                creado_en,
                actualizado_en
            ) VALUES (
                :usuario_id,
                :almacen_id,
                :nombre,
                :folio,
                :total_productos,
                :datos_json,
                NOW(),
                NOW()
            )
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':usuario_id' => $usuarioId,
            ':almacen_id' => $almacenId,
            ':nombre' => $nombre,
            ':folio' => $folio !== '' ? $folio : null,
            ':total_productos' => count($productos),
            ':datos_json' => $datosJson
        ]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'nombre' => $nombre,
            'total_productos' => count($productos),
            'actualizado' => false
        ];
    }

    public function listarPorUsuario(
        int $usuarioId
    ): array {
        if ($usuarioId <= 0) {
            return [];
        }

        $sql = "
            SELECT
                b.id,
                b.usuario_id,
                b.almacen_id,
                b.nombre,
                b.folio,
                b.total_productos,
                b.creado_en,
                b.actualizado_en,
                a.nombre AS almacen_nombre
            FROM entrada_borradores AS b
            LEFT JOIN almacenes AS a
                ON a.id = b.almacen_id
            WHERE b.usuario_id = :usuario_id
            ORDER BY b.actualizado_en DESC
            LIMIT 100
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':usuario_id' => $usuarioId
        ]);

        return $this->normalizarLista(
            $stmt->fetchAll()
        );
    }

    public function obtener(
        int $borradorId,
        int $usuarioId
    ): ?array {
        if ($borradorId <= 0 || $usuarioId <= 0) {
            return null;
        }

        $sql = "
            SELECT
                id,
                usuario_id,
                almacen_id,
                nombre,
                folio,
                total_productos,
                datos_json,
                creado_en,
                actualizado_en
            FROM entrada_borradores
            WHERE
                id = :id
                AND usuario_id = :usuario_id
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $borradorId,
            ':usuario_id' => $usuarioId
        ]);

        $borrador = $stmt->fetch();

        if (!$borrador) {
            return null;
        }

        $datos = json_decode(
            (string) ($borrador['datos_json'] ?? ''),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        unset($borrador['datos_json']);

        $borrador['id'] = (int) $borrador['id'];
        $borrador['usuario_id'] = (int) (
            $borrador['usuario_id']
        );
        $borrador['almacen_id'] = (int) (
            $borrador['almacen_id']
        );
        $borrador['total_productos'] = (int) (
            $borrador['total_productos']
        );
        $borrador['datos'] = is_array($datos)
            ? $datos
            : [];

        return $borrador;
    }

    public function eliminar(
        int $borradorId,
        int $usuarioId
    ): bool {
        if ($borradorId <= 0 || $usuarioId <= 0) {
            return false;
        }

        $sql = "
            DELETE FROM entrada_borradores
            WHERE
                id = :id
                AND usuario_id = :usuario_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $borradorId,
            ':usuario_id' => $usuarioId
        ]);

        return $stmt->rowCount() > 0;
    }

    private function normalizarLista(
        array $borradores
    ): array {
        foreach ($borradores as &$borrador) {
            $borrador['id'] = (int) (
                $borrador['id'] ?? 0
            );
            $borrador['usuario_id'] = (int) (
                $borrador['usuario_id'] ?? 0
            );
            $borrador['almacen_id'] = (int) (
                $borrador['almacen_id'] ?? 0
            );
            $borrador['total_productos'] = (int) (
                $borrador['total_productos'] ?? 0
            );
        }

        unset($borrador);

        return $borradores;
    }
}
