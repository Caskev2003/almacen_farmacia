<?php

require_once __DIR__ . '/../config/database.php';

class Usuario
{
    private PDO $conn;
    private string $table = "usuarios";

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->connect();
    }

    public function findByUsuarioOrCorreo(string $login): ?array
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE (usuario = :login OR correo = :login)
                AND estado = 1
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            ':login' => $login
        ]);

        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function findById(int $id): ?array
    {
        $sql = "SELECT 
                    id,
                    nombre,
                    usuario,
                    correo,
                    rol,
                    estado,
                    almacen_id,
                    created_at,
                    updated_at
                FROM {$this->table}
                WHERE id = :id
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            ':id' => $id
        ]);

        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function getAll(): array
    {
        $sql = "SELECT
                    u.id,
                    u.nombre,
                    u.usuario,
                    u.correo,
                    u.rol,
                    u.estado,
                    u.almacen_id,
                    u.created_at,

                    a.nombre AS almacen_nombre

                FROM usuarios u

                LEFT JOIN almacenes a
                    ON u.almacen_id = a.id

                ORDER BY
                    u.nombre ASC";

        $stmt = $this->conn->query($sql);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): bool
    {
        $sql = "INSERT INTO {$this->table}
                (
                    nombre,
                    usuario,
                    correo,
                    password,
                    rol,
                    estado,
                    almacen_id
                )
                VALUES
                (
                    :nombre,
                    :usuario,
                    :correo,
                    :password,
                    :rol,
                    :estado,
                    :almacen_id
                )";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':nombre' => $data['nombre'],
            ':usuario' => $data['usuario'],
            ':correo' => $data['correo'],
            ':password' => password_hash($data['password'], PASSWORD_DEFAULT),
            ':rol' => $data['rol'] ?? 'CONSULTA',
            ':estado' => $data['estado'] ?? 1,
            ':almacen_id' => $data['almacen_id'] ?? null,
        ]);
    }

    public function updatePassword(int $id, string $password): bool
    {
        $sql = "UPDATE usuarios
                SET password = :password
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':password' => password_hash($password, PASSWORD_DEFAULT),
            ':id' => $id
        ]);
    }

    public function updateEstado(int $id, int $estado): bool
    {
        $sql = "UPDATE usuarios
                SET estado = :estado
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':estado' => $estado,
            ':id' => $id
        ]);
    }
    public function update(int $id, array $data): bool
{
    $sql = "UPDATE usuarios SET
                nombre = :nombre,
                usuario = :usuario,
                correo = :correo,
                rol = :rol,
                almacen_id = :almacen_id
            WHERE id = :id";

    $stmt = $this->conn->prepare($sql);

    return $stmt->execute([
        ':nombre' => $data['nombre'],
        ':usuario' => $data['usuario'],
        ':correo' => $data['correo'],
        ':rol' => $data['rol'],
        ':almacen_id' => $data['almacen_id'] ?? null,
        ':id' => $id,
    ]);
}
}