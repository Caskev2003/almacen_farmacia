<?php

require_once __DIR__ . '/../models/Producto.php';

class ExistenciaController
{
    private Producto $productoModel;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->productoModel = new Producto();
    }

    private function esAdministrador(): bool
    {
        $usuario = $_SESSION['user'] ?? [];
        $rol = strtoupper(trim($usuario['rol'] ?? ''));

        return in_array($rol, ['ADMINISTRADOR', 'ADMIN'], true);
    }

    private function sucursalPorAlmacenId(int $almacenId): string
    {
        if ($almacenId === 1) {
            return 'CIUDAD HIDALGO';
        }

        if ($almacenId === 2 || $almacenId === 3) {
            return 'TUXTLA';
        }

        return '';
    }

    public function almacenes(): array
    {
        return $this->productoModel->getAlmacenes();
    }

    public function index(string $buscar = '', int $almacenId = 0, string $estadoStock = ''): array
    {
        $usuario = $_SESSION['user'] ?? [];

        $esAdmin = $this->esAdministrador();
        $almacenSesion = (int)($usuario['almacen_id'] ?? 0);

        if (!$esAdmin) {
            $almacenId = $almacenSesion;
        }

        if ($esAdmin && $almacenId === 0) {
            return $this->productoModel->getExistencias(
                trim($buscar),
                0,
                trim($estadoStock),
                '',
                true
            );
        }

        $sucursal = $this->sucursalPorAlmacenId($almacenId);

        return $this->productoModel->getExistencias(
            trim($buscar),
            $almacenId,
            trim($estadoStock),
            $sucursal,
            false
        );
    }

    public function resumen(array $productos): array
    {
        $totalProductos = count($productos);
        $sinExistencia = 0;
        $stockBajo = 0;
        $stockNormal = 0;
        $valorInventario = 0.0;
        $totalUnidades = 0;

        foreach ($productos as $producto) {
            $existenciaHidalgo = (int)($producto['existencia_hidalgo'] ?? 0);
            $existenciaTuxtla = (int)($producto['existencia_tuxtla'] ?? 0);

            if (isset($producto['existencia_hidalgo']) || isset($producto['existencia_tuxtla'])) {
                $existencia = $existenciaHidalgo + $existenciaTuxtla;
            } else {
                $existencia = (int)($producto['existencia_consultada'] ?? $producto['existencia'] ?? 0);
            }

            $stockMinimo = (int)($producto['stock_minimo'] ?? 0);
            $precioCompra = (float)($producto['precio_compra'] ?? 0);

            $totalUnidades += $existencia;
            $valorInventario += $existencia * $precioCompra;

            if ($existencia <= 0) {
                $sinExistencia++;
            } elseif ($stockMinimo > 0 && $existencia <= $stockMinimo) {
                $stockBajo++;
            } else {
                $stockNormal++;
            }
        }

        return [
            'total_productos' => $totalProductos,
            'total_unidades' => $totalUnidades,
            'sin_existencia' => $sinExistencia,
            'stock_bajo' => $stockBajo,
            'stock_normal' => $stockNormal,
            'valor_inventario' => $valorInventario,
        ];
    }
}