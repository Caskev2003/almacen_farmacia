<?php

require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../helpers/audit.php';

class AuthController
{
    private Usuario $usuarioModel;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->usuarioModel = new Usuario();
    }

    public function login(string $login, string $password): array
    {
        $login = trim($login);
        $password = trim($password);

        if ($login === '' || $password === '') {
            auditLog([
                'modulo' => 'Autenticación',
                'accion' => 'INICIO_SESION_FALLIDO',
                'descripcion' => 'Intento de inicio de sesión con datos incompletos.',
                'metadata' => [
                    'login_ingresado' => $login
                ],
            ]);

            return [
                'success' => false,
                'message' => 'Debes capturar el usuario/correo y la contraseña.'
            ];
        }

        $user = $this->usuarioModel->findByUsuarioOrCorreo($login);

        if (!$user) {
            auditLog([
                'modulo' => 'Autenticación',
                'accion' => 'INICIO_SESION_FALLIDO',
                'descripcion' => 'Intento de inicio de sesión con un usuario inexistente o inactivo.',
                'metadata' => [
                    'login_ingresado' => $login
                ],
            ]);

            return [
                'success' => false,
                'message' => 'Usuario no encontrado o inactivo.'
            ];
        }

        if (!password_verify($password, $user['password'])) {
            auditLog([
                'modulo' => 'Autenticación',
                'accion' => 'INICIO_SESION_FALLIDO',
                'entidad' => 'usuario',
                'registro_id' => $user['id'],
                'descripcion' => 'Intento de inicio de sesión con contraseña incorrecta.',
                'usuario' => $user,
                'metadata' => [
                    'login_ingresado' => $login
                ],
            ]);

            return [
                'success' => false,
                'message' => 'Contraseña incorrecta.'
            ];
        }

        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'nombre' => $user['nombre'],
            'usuario' => $user['usuario'],
            'correo' => $user['correo'],
            'rol' => $user['rol'],
            'almacen_id' => isset($user['almacen_id']) ? (int)$user['almacen_id'] : null,

            // NUEVO: estos campos nos servirán para separar existencias por sucursal
            'almacen_nombre' => $user['almacen_nombre'] ?? null,
            'almacen_codigo' => $user['almacen_codigo'] ?? null
        ];

        auditLog([
            'modulo' => 'Autenticación',
            'accion' => 'INICIO_SESION',
            'entidad' => 'usuario',
            'registro_id' => $user['id'],
            'descripcion' => 'Inició sesión correctamente.',
        ]);

        return [
            'success' => true,
            'message' => 'Inicio de sesión correcto.'
        ];
    }

    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        session_destroy();
    }
}
