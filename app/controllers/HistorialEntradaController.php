<?php

require_once __DIR__ . '/../models/Movimiento.php';

class HistorialEntradaController
{
    private Movimiento $movimientoModel;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->movimientoModel = new Movimiento();
    }

    public function almacenes(): array
    {
        return $this->movimientoModel->getAlmacenes();
    }

    public function index(
        string $buscar = '',
        int $almacenId = 0,
        string $fechaInicio = '',
        string $fechaFinal = ''
    ): array {
        $usuario = $_SESSION['user'] ?? [];

        $rol = $usuario['rol'] ?? '';
        $almacenSesion = (int)($usuario['almacen_id'] ?? 0);

        if ($rol !== 'ADMINISTRADOR') {
            $almacenId = $almacenSesion;
        }

        return $this->movimientoModel->historialEntradas(
            trim($buscar),
            $almacenId,
            trim($fechaInicio),
            trim($fechaFinal)
        );
    }

    public function resumen(array $entradas): array
    {
        $totalEntradas = count($entradas);
        $totalProductos = 0;
        $totalUnidades = 0;
        $totalImporte = 0.0;

        foreach ($entradas as $entrada) {
            $totalProductos += (int)($entrada['total_productos'] ?? 0);
            $totalUnidades += (int)($entrada['total_unidades'] ?? 0);
            $totalImporte += (float)($entrada['total'] ?? 0);
        }

        return [
            'total_entradas' => $totalEntradas,
            'total_productos' => $totalProductos,
            'total_unidades' => $totalUnidades,
            'total_importe' => $totalImporte,
        ];
    }

    public function obtenerEntrada(int $id): ?array
    {
        return $this->movimientoModel->obtenerEntradaPorId($id);
    }
}