<?php

require_once __DIR__ . '/../models/InventarioFisicoVirtual.php';
require_once __DIR__ . '/../models/Movimiento.php';

class InventarioFisicoVirtualController
{
    private InventarioFisicoVirtual $inventarioModel;
    private Movimiento $movimientoModel;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->inventarioModel = new InventarioFisicoVirtual();
        $this->movimientoModel = new Movimiento();
    }

    public function verificarAcceso(): void
    {
        $user = $_SESSION['user'] ?? null;

        if (!$user) {
            header('Location: login.php');
            exit;
        }

        $rol = strtoupper(trim($user['rol'] ?? ''));
        $almacenId = (int)($user['almacen_id'] ?? 0);

        $puedeEntrar =
            $rol === 'ADMINISTRADOR'
            || $rol === 'ENCARGADO'
            || $rol === 'ALMACEN'
            || $rol === 'GERENTE'
            || in_array($almacenId, [1, 2, 3], true);

        if (!$puedeEntrar) {
            header('Location: dashboard.php');
            exit;
        }
    }

    public function generarFolio(): string
    {
        return $this->inventarioModel->generarFolio();
    }

    public function almacenes(): array
    {
        return $this->movimientoModel->getAlmacenes();
    }

    public function buscarProducto(string $codigo): ?array
    {
        $codigo = trim($codigo);

        if ($codigo === '') {
            return null;
        }

        return $this->inventarioModel->buscarProductoPorCodigo($codigo);
    }

    public function guardar(array $postData, int $usuarioId): array
    {
        try {
            $folio = trim($postData['folio'] ?? '');
            $almacenId = (int)($postData['almacen_id'] ?? 0);
            $observaciones = trim($postData['observaciones'] ?? '');

            $codigos = $postData['codigo_barras'] ?? [];
            $descripciones = $postData['descripcion'] ?? [];
            $productoIds = $postData['producto_id'] ?? [];

            $mostradores = $postData['mostrador'] ?? [];
            $piqueos = $postData['piqueo'] ?? [];
            $almacenes = $postData['almacen'] ?? [];
            $bodegas = $postData['bodega'] ?? [];

            if ($folio === '') {
                return [
                    'success' => false,
                    'message' => 'El folio es obligatorio.'
                ];
            }

            if ($almacenId <= 0) {
                return [
                    'success' => false,
                    'message' => 'Debe seleccionar un almacén.'
                ];
            }

            if (empty($codigos)) {
                return [
                    'success' => false,
                    'message' => 'No hay productos para guardar.'
                ];
            }

            $inventarioId = $this->inventarioModel->crearConteo([
                'folio' => $folio,
                'almacen_id' => $almacenId,
                'usuario_id' => $usuarioId,
                'observaciones' => $observaciones,
            ]);

            if (!$inventarioId) {
                return [
                    'success' => false,
                    'message' => 'No se pudo crear el inventario.'
                ];
            }

            $detalle = [];

            foreach ($codigos as $i => $codigo) {
                $codigo = trim($codigo);

                if ($codigo === '') {
                    continue;
                }

                $mostrador = (int)($mostradores[$i] ?? 0);
                $piqueo = (int)($piqueos[$i] ?? 0);
                $almacen = (int)($almacenes[$i] ?? 0);
                $bodega = (int)($bodegas[$i] ?? 0);

                $detalle[] = [
                    'producto_id' => !empty($productoIds[$i])
                        ? (int)$productoIds[$i]
                        : null,

                    'codigo_barras' => $codigo,
                    'descripcion' => trim($descripciones[$i] ?? ''),

                    'mostrador' => $mostrador,
                    'piqueo' => $piqueo,
                    'almacen' => $almacen,
                    'bodega' => $bodega,
                ];
            }

            if (count($detalle) === 0) {
                return [
                    'success' => false,
                    'message' => 'No hay productos válidos.'
                ];
            }

            $guardado = $this->inventarioModel->guardarDetalle(
                $inventarioId,
                $detalle
            );

            if (!$guardado) {
                return [
                    'success' => false,
                    'message' => 'No se pudo guardar el detalle.'
                ];
            }

            $cerrado = $this->inventarioModel->cerrarConteo($inventarioId);

            if (!$cerrado) {
                return [
                    'success' => false,
                    'message' => 'No se pudo cerrar el conteo.'
                ];
            }

            return [
                'success' => true,
                'message' => 'Inventario guardado correctamente.',
                'inventario_id' => $inventarioId
            ];

        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error interno: ' . $e->getMessage()
            ];
        }
    }

    public function conteos(): array
    {
        return $this->inventarioModel->obtenerConteos();
    }

    public function obtenerConteo(int $id): ?array
    {
        return $this->inventarioModel->obtenerConteoPorId($id);
    }

    public function obtenerDetalle(int $inventarioId): array
    {
        return $this->inventarioModel->obtenerDetalle($inventarioId);
    }

    public function eliminar(int $id): array
    {
        $user = $_SESSION['user'] ?? null;
        $rol = strtoupper(trim($user['rol'] ?? ''));

        if ($rol !== 'ADMINISTRADOR') {
            return [
                'success' => false,
                'message' => 'No tienes permiso para eliminar inventarios.'
            ];
        }

        $ok = $this->inventarioModel->eliminarConteo($id);

        return [
            'success' => $ok,
            'message' => $ok
                ? 'Inventario eliminado correctamente.'
                : 'No se pudo eliminar el inventario.'
        ];
    }
}