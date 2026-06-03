<?php

require_once __DIR__ . '/../models/Movimiento.php';

class EntradaController
{
    private Movimiento $movimientoModel;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->movimientoModel = new Movimiento();
    }

    private function obtenerAlmacenSesion(): int
    {
        $usuario = $_SESSION['user'] ?? [];
        return (int)($usuario['almacen_id'] ?? 0);
    }

    private function limpiarUbicacion(?string $ubicacion): string
    {
        $ubicacion = strtoupper(trim((string)$ubicacion));
        $ubicacion = str_replace('SIN UBICACIÓN', 'SIN UBICACION', $ubicacion);

        return $ubicacion !== '' ? $ubicacion : 'SIN UBICACION';
    }

    public function almacenes(): array
    {
        return $this->movimientoModel->getAlmacenes();
    }

    public function proveedores(): array
    {
        return $this->movimientoModel->getProveedores();
    }

    public function productos(): array
    {
        return $this->movimientoModel->getProductosCatalogo();
    }

    public function generarFolio(?int $almacenId = null): string
    {
        $almacenId = $almacenId !== null
            ? (int)$almacenId
            : $this->obtenerAlmacenSesion();

        return $this->movimientoModel->generarFolioEntrada($almacenId);
    }

    public function ultimoFolioEntrada(?int $almacenId = null): string
    {
        $almacenId = $almacenId !== null
            ? (int)$almacenId
            : $this->obtenerAlmacenSesion();

        return $this->movimientoModel->ultimoFolioEntrada($almacenId);
    }

    public function obtenerEntrada(int $id): ?array
    {
        return $this->movimientoModel->obtenerEntradaPorId($id);
    }

    public function tiposEntrada(): array
    {
        return [
            ['clave' => 'E0001', 'descripcion' => 'Inventario Inicial'],
            ['clave' => 'E0002', 'descripcion' => 'Entrada de Producto'],
            ['clave' => 'E0003', 'descripcion' => 'Ajuste de Entrada de Inventario'],
        ];
    }

    public function guardar(array $postData, int $usuarioId): array
    {
        $fecha = trim($postData['fecha'] ?? '');
        $folioAnterior = trim($postData['folio_anterior'] ?? '');
        $tipoEntrada = trim($postData['tipo_entrada'] ?? '');

        $proveedorId = trim($postData['proveedor_id'] ?? '');
        $proveedorNombre = trim($postData['proveedor_nombre'] ?? '');

        $referencia = trim($postData['referencia'] ?? '');
        $observaciones = trim($postData['observaciones'] ?? '');

        $usuario = $_SESSION['user'] ?? [];
        $rol = strtoupper(trim($usuario['rol'] ?? ''));
        $almacenSesionId = (int)($usuario['almacen_id'] ?? 0);

        $almacenId = $rol === 'ADMINISTRADOR'
            ? (int)($postData['almacen_id'] ?? 0)
            : $almacenSesionId;

        if ($almacenId <= 0) {
            return [
                'success' => false,
                'message' => 'No tienes un almacén asignado.'
            ];
        }

        $folio = trim($postData['folio'] ?? '');

        if ($folio === '') {
            $folio = $this->movimientoModel->generarFolioEntrada($almacenId);
        }

        $productoIds = $postData['producto_id'] ?? [];
        $cantidades = $postData['cantidad'] ?? [];
        $costos = $postData['costo_unitario'] ?? [];
        $lotes = $postData['numero_lote'] ?? [];
        $caducidades = $postData['fecha_caducidad'] ?? [];
        $ubicaciones = $postData['ubicacion'] ?? [];

        if ($fecha === '') {
            return [
                'success' => false,
                'message' => 'La fecha es obligatoria.'
            ];
        }

        if ($tipoEntrada === '') {
            return [
                'success' => false,
                'message' => 'Debes seleccionar el tipo de entrada.'
            ];
        }

        if (empty($productoIds)) {
            return [
                'success' => false,
                'message' => 'Debes agregar al menos un producto.'
            ];
        }

        $detalle = [];

        foreach ($productoIds as $i => $productoId) {
            $productoId = (int)$productoId;
            $cantidad = isset($cantidades[$i]) ? (int)$cantidades[$i] : 0;
            $costo = isset($costos[$i]) ? (float)$costos[$i] : 0;
            $lote = trim($lotes[$i] ?? '');
            $caducidad = trim($caducidades[$i] ?? '');
            $ubicacion = $this->limpiarUbicacion($ubicaciones[$i] ?? '');

            if ($productoId <= 0) {
                continue;
            }

            if ($cantidad <= 0) {
                return [
                    'success' => false,
                    'message' => 'La cantidad debe ser mayor a 0 en todos los productos.'
                ];
            }

            if ($costo < 0) {
                return [
                    'success' => false,
                    'message' => 'El costo unitario no puede ser negativo.'
                ];
            }

            if ($ubicacion === 'SIN UBICACION') {
                return [
                    'success' => false,
                    'message' => 'Debes escribir o seleccionar una ubicación válida para cada producto.'
                ];
            }

            $detalle[] = [
                'producto_id' => $productoId,
                'cantidad' => $cantidad,
                'costo_unitario' => $costo,
                'precio_unitario' => 0,
                'numero_lote' => $lote,
                'fecha_caducidad' => $caducidad,
                'ubicacion' => $ubicacion,
                'almacen_id' => $almacenId,
            ];
        }

        if (count($detalle) === 0) {
            return [
                'success' => false,
                'message' => 'No hay productos válidos para guardar.'
            ];
        }

        $referenciaFinal = $tipoEntrada;

        if ($folioAnterior !== '') {
            $referenciaFinal .= ' | Folio anterior: ' . $folioAnterior;
        }

        if ($proveedorNombre !== '') {
            $referenciaFinal .= ' | Proveedor: ' . $proveedorNombre;
        }

        if ($referencia !== '') {
            $referenciaFinal .= ' | Ref: ' . $referencia;
        }

        return $this->movimientoModel->crearMovimiento([
            'folio' => $folio,
            'tipo_movimiento' => 'ENTRADA',
            'fecha' => $fecha,
            'almacen_id' => $almacenId,
            'proveedor_id' => $proveedorId !== '' ? $proveedorId : null,
            'referencia' => $referenciaFinal,
            'observaciones' => $observaciones,
            'usuario_id' => $usuarioId,
        ], $detalle);
    }

    public function cancelarEntrada(int $movimientoId, int $usuarioId, string $motivo = ''): array
    {
        return $this->movimientoModel->cancelarEntrada($movimientoId, $usuarioId, $motivo);
    }

    /**
     * Obtiene todas las ubicaciones únicas del sistema
     * @param int|null $almacenId ID del almacén (opcional)
     * @return array Lista de ubicaciones únicas
     */
    public function ubicacionesTodas(?int $almacenId = null): array
    {
        return $this->movimientoModel->getTodasUbicacionesPorSucursal($almacenId);
    }
}