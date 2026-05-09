<?php

require_once __DIR__ . '/../models/Movimiento.php';

class SalidaController
{
    private Movimiento $movimientoModel;

    public function __construct()
    {
        $this->movimientoModel = new Movimiento();
    }

    public function almacenes(): array
    {
        return $this->movimientoModel->getAlmacenes();
    }

    public function productos(): array
    {
        return $this->movimientoModel->getProductosActivos();
    }

    public function generarFolio(): string
    {
        return $this->movimientoModel->generarFolioSalida();
    }

    public function ultimoFolioSalida(): string
    {
        return $this->movimientoModel->ultimoFolioSalida();
    }

    public function tiposSalida(): array
    {
        return [
            ['clave' => 'S0001', 'descripcion' => 'Salida de Producto'],
            ['clave' => 'S0002', 'descripcion' => 'Merma'],
            ['clave' => 'S0003', 'descripcion' => 'Ajuste de Salida de Inventario'],
            ['clave' => 'S0004', 'descripcion' => 'Salida a Tienda Ciudad Hidalgo'],
            ['clave' => 'S0005', 'descripcion' => 'Salida a Tienda Tapachula'],
            ['clave' => 'S0006', 'descripcion' => 'Salida a Toscana'],
            ['clave' => 'S0007', 'descripcion' => 'Salida a Tuxtla Gutierrez'],
        ];
    }

    public function guardar(array $postData, int $usuarioId): array
    {
        $folio = trim($postData['folio'] ?? '');
        $fecha = trim($postData['fecha'] ?? '');
        $tipoSalida = trim($postData['tipo_salida'] ?? '');
        $tipoOperacion = trim($postData['tipo_operacion'] ?? '');
        $folioOperacion = trim($postData['folio_operacion'] ?? '');
        $almacenId = trim($postData['almacen_id'] ?? '');
        $observaciones = trim($postData['observaciones'] ?? '');

        $productoIds = $postData['producto_id'] ?? [];
        $cantidades = $postData['cantidad'] ?? [];
        $costos = $postData['costo_unitario'] ?? [];
        $precios = $postData['precio_unitario'] ?? [];
        $ubicaciones = $postData['ubicacion'] ?? [];

        if ($folio === '') {
            return ['success' => false, 'message' => 'El folio es obligatorio.'];
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

        if (($tipoOperacion === 'TICKET' || $tipoOperacion === 'RESURTIDO') && $folioOperacion === '') {
            return [
                'success' => false,
                'message' => 'Debes ingresar el folio de ' . strtolower($tipoOperacion) . '.'
            ];
        }

        if ($almacenId === '') {
            return ['success' => false, 'message' => 'Debes seleccionar un almacén.'];
        }

        if (empty($productoIds)) {
            return ['success' => false, 'message' => 'Debes agregar al menos un producto.'];
        }

        $detalle = [];

        foreach ($productoIds as $i => $productoId) {
            $productoId = (int)$productoId;
            $cantidad = isset($cantidades[$i]) ? (int)$cantidades[$i] : 0;
            $costo = isset($costos[$i]) ? (float)$costos[$i] : 0;
            $precio = isset($precios[$i]) ? (float)$precios[$i] : 0;
            $ubicacion = trim($ubicaciones[$i] ?? '');

            if ($productoId <= 0) {
                continue;
            }

            if ($cantidad <= 0) {
                return ['success' => false, 'message' => 'La cantidad debe ser mayor a 0 en todos los productos.'];
            }

            if ($precio < 0 || $costo < 0) {
                return ['success' => false, 'message' => 'Costo o precio inválido.'];
            }

            $detalle[] = [
                'producto_id' => $productoId,
                'cantidad' => $cantidad,
                'costo_unitario' => $costo,
                'precio_unitario' => $precio,
                'ubicacion' => $ubicacion,
            ];
        }

        if (count($detalle) === 0) {
            return ['success' => false, 'message' => 'No hay productos válidos para guardar.'];
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
    return $this->movimientoModel->historialSalidas(
        trim($buscar),
        $almacenId,
        trim($fechaInicio),
        trim($fechaFinal)
    );
}
}