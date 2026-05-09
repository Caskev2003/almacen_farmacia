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
        $stmt->execute([':login' => $login]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function findById(int $id): ?array
    {
        $sql = "SELECT id, nombre, usuario, correo, rol, estado, created_at, updated_at
                FROM {$this->table}
                WHERE id = :id
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function create(array $data): bool
    {
        $sql = "INSERT INTO {$this->table}
                (nombre, usuario, correo, password, rol, estado)
                VALUES
                (:nombre, :usuario, :correo, :password, :rol, :estado)";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':nombre' => $data['nombre'],
            ':usuario' => $data['usuario'],
            ':correo' => $data['correo'],
            ':password' => password_hash($data['password'], PASSWORD_DEFAULT),
            ':rol' => $data['rol'] ?? 'CONSULTA',
            ':estado' => $data['estado'] ?? 1,
        ]);
    }
}