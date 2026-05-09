<?php

require_once __DIR__ . '/../models/Producto.php';

class ExistenciaController
{
    private Producto $productoModel;

    public function __construct()
    {
        $this->productoModel = new Producto();
    }

    public function almacenes(): array
    {
        return $this->productoModel->getAlmacenes();
    }

    public function index(string $buscar = '', int $almacenId = 0, string $estadoStock = ''): array
    {
        return $this->productoModel->getExistencias(
            trim($buscar),
            $almacenId,
            trim($estadoStock)
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
            $existencia = (int)($producto['existencia_consultada'] ?? 0);
            $stockMinimo = (int)($producto['stock_minimo'] ?? 0);
            $precioCompra = (float)($producto['precio_compra'] ?? 0);

            $totalUnidades += $existencia;
            $valorInventario += $existencia * $precioCompra;

            if ($existencia <= 0) {
                $sinExistencia++;
            } elseif ($existencia <= $stockMinimo) {
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