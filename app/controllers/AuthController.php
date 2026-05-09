<?php

require_once __DIR__ . '/../models/Usuario.php';

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
            return [
                'success' => false,
                'message' => 'Debes capturar el usuario/correo y la contraseña.'
            ];
        }

        $user = $this->usuarioModel->findByUsuarioOrCorreo($login);

        if (!$user) {
            return [
                'success' => false,
                'message' => 'Usuario no encontrado o inactivo.'
            ];
        }

        if (!password_verify($password, $user['password'])) {
            return [
                'success' => false,
                'message' => 'Contraseña incorrecta.'
            ];
        }

        $_SESSION['user'] = [
            'id' => $user['id'],
            'nombre' => $user['nombre'],
            'usuario' => $user['usuario'],
            'correo' => $user['correo'],
            'rol' => $user['rol']
        ];

        return [
            'success' => true,
            'message' => 'Inicio de sesión correcto.'
        ];
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }
}