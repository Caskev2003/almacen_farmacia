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

    public function almacenes(): array
    {
        return $this->movimientoModel->getAlmacenes();
    }

    public function productos(): array
    {
        $productos = $this->movimientoModel->getProductosActivos();

        foreach ($productos as &$producto) {
            $producto['ubicaciones'] = [];

            $ubicacion = trim((string)($producto['ubicacion'] ?? ''));
            $existencia = (int)($producto['existencia_actual'] ?? 0);

            if ($ubicacion !== '') {
                $producto['ubicaciones'][] = [
                    'ubicacion' => $ubicacion,
                    'existencia_actual' => $existencia,
                ];
            }

            usort($producto['ubicaciones'], function ($a, $b) {
                return ((int)$a['existencia_actual']) <=> ((int)$b['existencia_actual']);
            });
        }

        unset($producto);

        return $productos;
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

        if ($rol === 'ADMINISTRADOR') {
            $almacenId = (int)($postData['almacen_id'] ?? 0);
        } else {
            $almacenId = $almacenSesion;
        }

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

        if (($tipoOperacion === 'TICKET' || $tipoOperacion === 'RESURTIDO') && $folioOperacion === '') {
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
            $ubicacion = trim($ubicaciones[$i] ?? '');

            if ($ubicacion === '') {
                $ubicacion = 'SIN UBICACION';
            }

            if ($productoId <= 0) {
                continue;
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

            if ($observacionesFinales !== '') {
                $observacionesFinales = $textoFolio . ' | ' . $observacionesFinales;
            } else {
                $observacionesFinales = $textoFolio;
            }
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
}