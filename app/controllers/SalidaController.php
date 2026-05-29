<?php

require_once __DIR__ . '/../models/Movimiento.php';

class SalidaController
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

    public function productos(): array
    {
        return $this->movimientoModel->getProductosParaSalida();
    }

    public function generarFolio(?int $almacenId = null): string
    {
        $almacenId = $almacenId !== null
            ? (int)$almacenId
            : $this->obtenerAlmacenSesion();

        return $this->movimientoModel->generarFolioSalida($almacenId);
    }

    public function ultimoFolioSalida(?int $almacenId = null): string
    {
        $almacenId = $almacenId !== null
            ? (int)$almacenId
            : $this->obtenerAlmacenSesion();

        return $this->movimientoModel->ultimoFolioSalida($almacenId);
    }

    public function tiposSalida(): array
    {
        return [
            ['clave' => 'S0001', 'descripcion' => 'Salida de Producto'],
            ['clave' => 'S0002', 'descripcion' => 'Merma'],
            ['clave' => 'S0003', 'descripcion' => 'Ajuste de Salida de Inventario'],
            ['clave' => 'S0004', 'descripcion' => 'Salida a Tienda Ciudad Hidalgo'],
            ['clave' => 'S0005', 'descripcion' => 'Salida a Tienda Tapachula'],
            ['clave' => 'S0006', 'descripcion' => 'Salida a Tienda Toscana'],
            ['clave' => 'S0007', 'descripcion' => 'Salida a Tienda Tuxtla Gutierrez'],
        ];
    }

    public function guardar(array $postData, int $usuarioId): array
    {
        $fecha = trim($postData['fecha'] ?? '');
        $tipoSalida = trim($postData['tipo_salida'] ?? '');
        $tipoOperacion = trim($postData['tipo_operacion'] ?? '');
        $folioOperacion = trim($postData['folio_operacion'] ?? '');
        $observaciones = trim($postData['observaciones'] ?? '');

        $usuario = $_SESSION['user'] ?? [];
        $rol = strtoupper(trim($usuario['rol'] ?? ''));
        $almacenSesion = (int)($usuario['almacen_id'] ?? 0);

        $almacenId = $rol === 'ADMINISTRADOR'
            ? (int)($postData['almacen_id'] ?? 0)
            : $almacenSesion;

        if ($almacenId <= 0) {
            return [
                'success' => false,
                'message' => 'No tienes un almacén asignado.'
            ];
        }

        $folio = trim($postData['folio'] ?? '');

        if ($folio === '') {
            $folio = $this->movimientoModel->generarFolioSalida($almacenId);
        }

        $productoIds = $postData['producto_id'] ?? [];
        $cantidades = $postData['cantidad'] ?? [];
        $costos = $postData['costo_unitario'] ?? [];
        $precios = $postData['precio_unitario'] ?? [];
        $ubicaciones = $postData['ubicacion'] ?? [];

        if ($fecha === '') {
            return [
                'success' => false,
                'message' => 'La fecha es obligatoria.'
            ];
        }

        if ($tipoSalida === '') {
            return [
                'success' => false,
                'message' => 'Debes seleccionar el tipo de salida.'
            ];
        }

        if ($tipoOperacion === '') {
            return [
                'success' => false,
                'message' => 'Debes seleccionar el tipo de documento.'
            ];
        }

        if (
            in_array($tipoOperacion, ['TICKET', 'RESURTIDO'], true)
            && $folioOperacion === ''
        ) {
            return [
                'success' => false,
                'message' => 'Debes ingresar el folio de ' . strtolower($tipoOperacion) . '.'
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
            $precio = isset($precios[$i]) ? (float)$precios[$i] : 0;
            $ubicacion = $this->limpiarUbicacion($ubicaciones[$i] ?? '');

            if ($productoId <= 0) {
                continue;
            }

            if ($ubicacion === 'SIN UBICACION') {
                return [
                    'success' => false,
                    'message' => 'Debes escribir o seleccionar una ubicación válida para el producto.'
                ];
            }

            if ($cantidad <= 0) {
                return [
                    'success' => false,
                    'message' => 'La cantidad debe ser mayor a 0 en todos los productos.'
                ];
            }

            if ($precio < 0 || $costo < 0) {
                return [
                    'success' => false,
                    'message' => 'Costo o precio inválido.'
                ];
            }

            $detalle[] = [
                'producto_id' => $productoId,
                'cantidad' => $cantidad,
                'costo_unitario' => $costo,
                'precio_unitario' => $precio,
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

        $observacionesFinales = $observaciones;

        if ($folioOperacion !== '') {
            $textoFolio = 'Folio ' . strtolower($tipoOperacion) . ': ' . $folioOperacion;

            $observacionesFinales = $observacionesFinales !== ''
                ? $textoFolio . ' | ' . $observacionesFinales
                : $textoFolio;
        }

        return $this->movimientoModel->crearSalida([
            'folio' => $folio,
            'fecha' => $fecha,
            'almacen_id' => $almacenId,
            'usuario_id' => $usuarioId,
            'referencia' => $tipoSalida,
            'tipo_operacion' => $tipoOperacion,
            'observaciones' => $observacionesFinales,
        ], $detalle);
    }

    public function obtenerSalida(int $movimientoId): ?array
    {
        return $this->movimientoModel->obtenerSalidaPorId($movimientoId);
    }

    public function historialSalidas(
        string $buscar = '',
        int $almacenId = 0,
        string $fechaInicio = '',
        string $fechaFinal = ''
    ): array {
        $usuario = $_SESSION['user'] ?? [];

        $rol = strtoupper(trim($usuario['rol'] ?? ''));
        $almacenSesion = (int)($usuario['almacen_id'] ?? 0);

        if ($rol !== 'ADMINISTRADOR') {
            $almacenId = $almacenSesion;
        }

        return $this->movimientoModel->historialSalidas(
            trim($buscar),
            $almacenId,
            trim($fechaInicio),
            trim($fechaFinal)
        );
    }

    public function cancelarSalida(int $movimientoId, int $usuarioId, string $motivo = ''): array
{
    return $this->movimientoModel->cancelarSalida($movimientoId, $usuarioId, $motivo);
}
public function actualizar(int $movimientoId, array $postData, int $usuarioId): array
{
    $fecha = trim($postData['fecha'] ?? '');
    $tipoSalida = trim($postData['tipo_salida'] ?? '');
    $tipoOperacion = trim($postData['tipo_operacion'] ?? '');
    $folioOperacion = trim($postData['folio_operacion'] ?? '');
    $observaciones = trim($postData['observaciones'] ?? '');

    if ($movimientoId <= 0) {
        return ['success' => false, 'message' => 'Salida inválida para editar.'];
    }

    if ($fecha === '') {
        return ['success' => false, 'message' => 'La fecha es obligatoria.'];
    }

    if ($tipoSalida === '') {
        return ['success' => false, 'message' => 'Debes seleccionar el tipo de salida.'];
    }

    if ($tipoOperacion === '') {
        return ['success' => false, 'message' => 'Debes seleccionar el tipo de documento.'];
    }

    $productoIds = $postData['producto_id'] ?? [];
    $cantidades = $postData['cantidad'] ?? [];
    $costos = $postData['costo_unitario'] ?? [];
    $precios = $postData['precio_unitario'] ?? [];
    $ubicaciones = $postData['ubicacion'] ?? [];

    if (empty($productoIds)) {
        return ['success' => false, 'message' => 'Debes agregar al menos un producto.'];
    }

    $detalle = [];

    foreach ($productoIds as $i => $productoId) {
        $productoId = (int)$productoId;
        $cantidad = isset($cantidades[$i]) ? (int)$cantidades[$i] : 0;
        $costo = isset($costos[$i]) ? (float)$costos[$i] : 0;
        $precio = isset($precios[$i]) ? (float)$precios[$i] : 0;
        $ubicacion = $this->limpiarUbicacion($ubicaciones[$i] ?? '');

        if ($productoId <= 0) {
            continue;
        }

        if ($cantidad <= 0) {
            return ['success' => false, 'message' => 'La cantidad debe ser mayor a 0.'];
        }

        if ($ubicacion === 'SIN UBICACION') {
            return ['success' => false, 'message' => 'Debes seleccionar una ubicación válida.'];
        }

        $detalle[] = [
            'producto_id' => $productoId,
            'cantidad' => $cantidad,
            'costo_unitario' => $costo,
            'precio_unitario' => $precio,
            'ubicacion' => $ubicacion,
        ];
    }

    $observacionesFinales = $observaciones;

    if ($folioOperacion !== '') {
        $textoFolio = 'Folio ' . strtolower($tipoOperacion) . ': ' . $folioOperacion;

        $observacionesFinales = $observacionesFinales !== ''
            ? $textoFolio . ' | ' . $observacionesFinales
            : $textoFolio;
    }

    return $this->movimientoModel->editarSalida($movimientoId, [
        'fecha' => $fecha,
        'referencia' => $tipoSalida,
        'tipo_operacion' => $tipoOperacion,
        'observaciones' => $observacionesFinales,
    ], $detalle, $usuarioId);
}
}