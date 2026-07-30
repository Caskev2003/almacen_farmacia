<?php

declare(strict_types=1);

class VerificadorResurtido
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;

        $this->db->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        $this->db->setAttribute(
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::FETCH_ASSOC
        );
    }

    public function listarActivosPorAlmacen(
        int $almacenId
    ): array {
        if ($almacenId <= 0) {
            return [];
        }

        $sql = "
            SELECT
                id,
                almacen_id,
                nombre
            FROM verificadores_resurtido
            WHERE
                almacen_id = :almacen_id
                AND estado = 1
            ORDER BY nombre ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':almacen_id' => $almacenId
        ]);

        return $this->normalizarLista(
            $stmt->fetchAll()
        );
    }

    public function listarTodos(): array
    {
        $sql = "
            SELECT
                v.id,
                v.almacen_id,
                v.nombre,
                v.estado,
                v.creado_en,
                v.actualizado_en,
                a.nombre AS almacen_nombre
            FROM verificadores_resurtido AS v
            LEFT JOIN almacenes AS a
                ON a.id = v.almacen_id
            ORDER BY
                a.nombre ASC,
                v.nombre ASC
        ";

        $stmt = $this->db->query($sql);

        return $this->normalizarLista(
            $stmt->fetchAll()
        );
    }

    public function listarAlmacenes(): array
    {
        $sql = "
            SELECT
                id,
                nombre
            FROM almacenes
            ORDER BY nombre ASC
        ";

        $stmt = $this->db->query($sql);
        $almacenes = $stmt->fetchAll();

        foreach ($almacenes as &$almacen) {
            $almacen['id'] = (int) (
                $almacen['id'] ?? 0
            );
        }

        unset($almacen);

        return $almacenes;
    }

    public function verificarCredenciales(
        int $verificadorId,
        int $almacenId,
        string $password
    ): ?array {
        $password = trim($password);

        if (
            $verificadorId <= 0
            || $almacenId <= 0
            || $password === ''
        ) {
            return null;
        }

        $sql = "
            SELECT
                id,
                almacen_id,
                nombre,
                password_hash
            FROM verificadores_resurtido
            WHERE
                id = :id
                AND almacen_id = :almacen_id
                AND estado = 1
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $verificadorId,
            ':almacen_id' => $almacenId
        ]);

        $verificador = $stmt->fetch();

        if (!$verificador) {
            return null;
        }

        $hash = (string) (
            $verificador['password_hash'] ?? ''
        );

        if (
            $hash === ''
            || !password_verify($password, $hash)
        ) {
            return null;
        }

        if (
            password_needs_rehash(
                $hash,
                PASSWORD_DEFAULT
            )
        ) {
            $this->actualizarHash(
                (int) $verificador['id'],
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                )
            );
        }

        unset($verificador['password_hash']);

        $verificador['id'] = (int) $verificador['id'];
        $verificador['almacen_id'] = (int) (
            $verificador['almacen_id']
        );

        return $verificador;
    }

    public function crear(
        string $nombre,
        string $password,
        int $almacenId
    ): array {
        $nombre = $this->validarNombre($nombre);
        $password = $this->validarPassword($password);
        $this->asegurarPasswordUnica($password);

        if ($almacenId <= 0) {
            throw new InvalidArgumentException(
                'Seleccione el almacén del verificador.'
            );
        }

        $sql = "
            INSERT INTO verificadores_resurtido (
                almacen_id,
                nombre,
                password_hash,
                estado,
                creado_en,
                actualizado_en
            ) VALUES (
                :almacen_id,
                :nombre,
                :password_hash,
                1,
                NOW(),
                NOW()
            )
        ";

        $stmt = $this->db->prepare($sql);

        try {
            $stmt->execute([
                ':almacen_id' => $almacenId,
                ':nombre' => $nombre,
                ':password_hash' => password_hash(
                    $password,
                    PASSWORD_DEFAULT
                )
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new InvalidArgumentException(
                    'Ya existe un verificador con ese nombre en el almacén.'
                );
            }

            throw $e;
        }

        return [
            'id' => (int) $this->db->lastInsertId(),
            'almacen_id' => $almacenId,
            'nombre' => $nombre,
            'estado' => 1
        ];
    }

    public function cambiarPassword(
        int $verificadorId,
        string $password
    ): bool {
        if ($verificadorId <= 0) {
            throw new InvalidArgumentException(
                'El verificador indicado no es válido.'
            );
        }

        $password = $this->validarPassword($password);
        $this->asegurarPasswordUnica(
            $password,
            $verificadorId
        );

        $sql = "
            UPDATE verificadores_resurtido
            SET
                password_hash = :password_hash,
                actualizado_en = NOW()
            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':password_hash' => password_hash(
                $password,
                PASSWORD_DEFAULT
            ),
            ':id' => $verificadorId
        ]);

        return $stmt->rowCount() > 0;
    }

    public function cambiarEstado(
        int $verificadorId,
        bool $activo
    ): bool {
        if ($verificadorId <= 0) {
            throw new InvalidArgumentException(
                'El verificador indicado no es válido.'
            );
        }

        $sql = "
            UPDATE verificadores_resurtido
            SET
                estado = :estado,
                actualizado_en = NOW()
            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':estado' => $activo ? 1 : 0,
            ':id' => $verificadorId
        ]);

        return $stmt->rowCount() > 0;
    }

    public function obtenerPorId(
        int $verificadorId
    ): ?array {
        if ($verificadorId <= 0) {
            return null;
        }

        $sql = "
            SELECT
                id,
                almacen_id,
                nombre,
                estado
            FROM verificadores_resurtido
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $verificadorId
        ]);

        $verificador = $stmt->fetch();

        if (!$verificador) {
            return null;
        }

        return $this->normalizarLista([
            $verificador
        ])[0];
    }

    private function actualizarHash(
        int $verificadorId,
        string $hash
    ): void {
        $sql = "
            UPDATE verificadores_resurtido
            SET
                password_hash = :password_hash,
                actualizado_en = NOW()
            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':password_hash' => $hash,
            ':id' => $verificadorId
        ]);
    }

    private function asegurarPasswordUnica(
        string $password,
        ?int $excluirVerificadorId = null
    ): void {
        $sql = "
            SELECT
                id,
                password_hash
            FROM verificadores_resurtido
        ";

        $stmt = $this->db->query($sql);

        foreach ($stmt->fetchAll() as $registro) {
            if (
                $excluirVerificadorId !== null
                && (int) ($registro['id'] ?? 0)
                    === $excluirVerificadorId
            ) {
                continue;
            }

            $hash = (string) (
                $registro['password_hash'] ?? ''
            );

            if (
                $hash !== ''
                && password_verify($password, $hash)
            ) {
                throw new InvalidArgumentException(
                    'Esa contraseña ya la usa otro verificador. Asigne una diferente.'
                );
            }
        }
    }

    private function validarNombre(
        string $nombre
    ): string {
        $nombre = preg_replace(
            '/\s+/u',
            ' ',
            trim($nombre)
        );

        if (!is_string($nombre) || $nombre === '') {
            throw new InvalidArgumentException(
                'Escriba el nombre completo del verificador.'
            );
        }

        $longitud = mb_strlen($nombre);

        if ($longitud < 3 || $longitud > 150) {
            throw new InvalidArgumentException(
                'El nombre del verificador debe tener entre 3 y 150 caracteres.'
            );
        }

        return $nombre;
    }

    private function validarPassword(
        string $password
    ): string {
        $password = trim($password);
        $longitud = mb_strlen($password);

        if ($longitud < 4) {
            throw new InvalidArgumentException(
                'La contraseña del verificador debe tener al menos 4 caracteres.'
            );
        }

        if ($longitud > 255) {
            throw new InvalidArgumentException(
                'La contraseña del verificador es demasiado larga.'
            );
        }

        return $password;
    }

    private function normalizarLista(
        array $verificadores
    ): array {
        foreach ($verificadores as &$verificador) {
            $verificador['id'] = (int) (
                $verificador['id'] ?? 0
            );

            $verificador['almacen_id'] = (int) (
                $verificador['almacen_id'] ?? 0
            );

            if (isset($verificador['estado'])) {
                $verificador['estado'] = (int) (
                    $verificador['estado']
                );
            }
        }

        unset($verificador);

        return $verificadores;
    }
}
