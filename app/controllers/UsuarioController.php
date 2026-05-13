<?php

require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../models/Movimiento.php';

class UsuarioController
{
    private Usuario $usuarioModel;
    private Movimiento $movimientoModel;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->usuarioModel = new Usuario();
        $this->movimientoModel = new Movimiento();
    }

    private function esAdmin(): bool
    {
        return ($_SESSION['user']['rol'] ?? '') === 'ADMINISTRADOR';
    }

    public function verificarAdmin(): void
    {
        if (!$this->esAdmin()) {
            header('Location: dashboard.php');
            exit;
        }
    }

    public function usuarios(): array
    {
        return $this->usuarioModel->getAll();
    }

    public function almacenes(): array
    {
        return $this->movimientoModel->getAlmacenes();
    }

    public function guardar(array $data): array
    {
        if (!$this->esAdmin()) {
            return [
                'success' => false,
                'message' => 'No tienes permiso para crear usuarios.'
            ];
        }

        $nombre = trim($data['nombre'] ?? '');
        $usuario = trim($data['usuario'] ?? '');
        $correo = trim($data['correo'] ?? '');
        $password = trim($data['password'] ?? '');
        $rol = trim($data['rol'] ?? 'CONSULTA');
        $almacenId = $data['almacen_id'] ?? null;

        if ($nombre === '' || $usuario === '' || $correo === '' || $password === '') {
            return [
                'success' => false,
                'message' => 'Nombre, usuario, correo y contraseña son obligatorios.'
            ];
        }

        if ($rol !== 'ADMINISTRADOR' && empty($almacenId)) {
            return [
                'success' => false,
                'message' => 'Debes asignar un almacén al usuario.'
            ];
        }

        if ($rol === 'ADMINISTRADOR') {
            $almacenId = null;
        }

        $ok = $this->usuarioModel->create([
            'nombre' => $nombre,
            'usuario' => $usuario,
            'correo' => $correo,
            'password' => $password,
            'rol' => $rol,
            'almacen_id' => $almacenId,
            'estado' => 1,
        ]);

        return [
            'success' => $ok,
            'message' => $ok ? 'Usuario creado correctamente.' : 'No se pudo crear el usuario.'
        ];
    }

    public function cambiarPassword(int $id, string $password): array
    {
        if (!$this->esAdmin()) {
            return [
                'success' => false,
                'message' => 'No tienes permiso para cambiar contraseñas.'
            ];
        }

        $password = trim($password);

        if ($id <= 0 || $password === '') {
            return [
                'success' => false,
                'message' => 'Usuario o contraseña inválida.'
            ];
        }

        $ok = $this->usuarioModel->updatePassword($id, $password);

        return [
            'success' => $ok,
            'message' => $ok ? 'Contraseña actualizada correctamente.' : 'No se pudo actualizar la contraseña.'
        ];
    }

    public function cambiarEstado(int $id, int $estado): array
    {
        if (!$this->esAdmin()) {
            return [
                'success' => false,
                'message' => 'No tienes permiso para modificar usuarios.'
            ];
        }

        $ok = $this->usuarioModel->updateEstado($id, $estado);

        return [
            'success' => $ok,
            'message' => $ok ? 'Estado actualizado correctamente.' : 'No se pudo actualizar el estado.'
        ];
    }
    public function actualizar(array $data): array
{
    if (!$this->esAdmin()) {
        return [
            'success' => false,
            'message' => 'No tienes permiso para editar usuarios.'
        ];
    }

    $id = (int)($data['usuario_id'] ?? 0);
    $nombre = trim($data['nombre'] ?? '');
    $usuario = trim($data['usuario'] ?? '');
    $correo = trim($data['correo'] ?? '');
    $rol = trim($data['rol'] ?? 'CONSULTA');
    $almacenId = $data['almacen_id'] ?? null;

    if ($id <= 0 || $nombre === '' || $usuario === '' || $correo === '') {
        return [
            'success' => false,
            'message' => 'Datos incompletos para editar el usuario.'
        ];
    }

    if ($rol !== 'ADMINISTRADOR' && empty($almacenId)) {
        return [
            'success' => false,
            'message' => 'Debes asignar un almacén al usuario.'
        ];
    }

    if ($rol === 'ADMINISTRADOR') {
        $almacenId = null;
    }

    $ok = $this->usuarioModel->update($id, [
        'nombre' => $nombre,
        'usuario' => $usuario,
        'correo' => $correo,
        'rol' => $rol,
        'almacen_id' => $almacenId,
    ]);

    return [
        'success' => $ok,
        'message' => $ok ? 'Usuario actualizado correctamente.' : 'No se pudo actualizar el usuario.'
    ];
}
}