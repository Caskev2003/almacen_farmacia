<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Sesiones persistentes por dispositivo.
 *
 * El navegador conserva únicamente un selector y un secreto aleatorio. En la
 * base de datos se almacena el hash del secreto, nunca el secreto original.
 */
class SesionPersistente
{
    private PDO $conn;

    public function __construct(?PDO $conn = null)
    {
        if ($conn instanceof PDO) {
            $this->conn = $conn;
            return;
        }

        $database = new Database();
        $this->conn = $database->connect();
    }

    public function crear(
        int $usuarioId,
        string $selector,
        string $tokenHash,
        string $credencialHash,
        string $expiraEn,
        ?string $direccionIp,
        ?string $userAgent
    ): bool {
        $sql = "
            INSERT INTO sesiones_persistentes (
                usuario_id,
                selector,
                token_hash,
                credencial_hash,
                expira_en,
                direccion_ip,
                user_agent,
                ultimo_uso_en
            ) VALUES (
                :usuario_id,
                :selector,
                :token_hash,
                :credencial_hash,
                :expira_en,
                :direccion_ip,
                :user_agent,
                CURRENT_TIMESTAMP
            )
        ";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':usuario_id' => $usuarioId,
            ':selector' => $selector,
            ':token_hash' => $tokenHash,
            ':credencial_hash' => $credencialHash,
            ':expira_en' => $expiraEn,
            ':direccion_ip' => $direccionIp,
            ':user_agent' => $userAgent,
        ]);
    }

    public function buscarActiva(string $selector): ?array
    {
        $sql = "
            SELECT
                sp.id AS sesion_persistente_id,
                sp.selector,
                sp.token_hash,
                sp.credencial_hash,
                sp.expira_en,
                u.id,
                u.nombre,
                u.usuario,
                u.correo,
                u.password,
                u.rol,
                u.estado,
                u.almacen_id,
                a.nombre AS almacen_nombre,
                UPPER(
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(
                                        REPLACE(
                                            a.nombre,
                                            ' ',
                                            '_'
                                        ),
                                        'Á',
                                        'A'
                                    ),
                                    'É',
                                    'E'
                                ),
                                'Í',
                                'I'
                            ),
                            'Ó',
                            'O'
                        ),
                        'Ú',
                        'U'
                    )
                ) AS almacen_codigo
            FROM sesiones_persistentes AS sp
            INNER JOIN usuarios AS u
                ON u.id = sp.usuario_id
            LEFT JOIN almacenes AS a
                ON a.id = u.almacen_id
            WHERE sp.selector = :selector
              AND sp.expira_en > CURRENT_TIMESTAMP
              AND u.estado = 1
            LIMIT 1
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':selector' => $selector,
        ]);

        $sesion = $stmt->fetch(PDO::FETCH_ASSOC);

        return $sesion ?: null;
    }

    public function buscarUsuarioActivo(int $usuarioId): ?array
    {
        if ($usuarioId <= 0) {
            return null;
        }

        $sql = "
            SELECT
                u.id,
                u.nombre,
                u.usuario,
                u.correo,
                u.password,
                u.rol,
                u.estado,
                u.almacen_id,
                a.nombre AS almacen_nombre
            FROM usuarios AS u
            LEFT JOIN almacenes AS a
                ON a.id = u.almacen_id
            WHERE u.id = :usuario_id
              AND u.estado = 1
            LIMIT 1
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':usuario_id' => $usuarioId,
        ]);

        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        return $usuario ?: null;
    }

    public function renovar(
        string $selector,
        string $tokenHash,
        string $credencialHash,
        string $expiraEn,
        ?string $direccionIp,
        ?string $userAgent
    ): bool {
        $sql = "
            UPDATE sesiones_persistentes
            SET
                token_hash = :token_hash,
                credencial_hash = :credencial_hash,
                expira_en = :expira_en,
                direccion_ip = :direccion_ip,
                user_agent = :user_agent,
                ultimo_uso_en = CURRENT_TIMESTAMP
            WHERE selector = :selector
        ";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':token_hash' => $tokenHash,
            ':credencial_hash' => $credencialHash,
            ':expira_en' => $expiraEn,
            ':direccion_ip' => $direccionIp,
            ':user_agent' => $userAgent,
            ':selector' => $selector,
        ]);
    }

    public function eliminarPorSelector(string $selector): bool
    {
        $stmt = $this->conn->prepare(
            'DELETE FROM sesiones_persistentes WHERE selector = :selector'
        );

        return $stmt->execute([
            ':selector' => $selector,
        ]);
    }

    public function eliminarExpiradas(): int
    {
        $stmt = $this->conn->prepare(
            'DELETE FROM sesiones_persistentes WHERE expira_en <= CURRENT_TIMESTAMP'
        );
        $stmt->execute();

        return $stmt->rowCount();
    }
}
