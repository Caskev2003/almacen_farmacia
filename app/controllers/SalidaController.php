<?php

require_once __DIR__ . '/../models/Movimiento.php';
require_once __DIR__ . '/../models/SalidaBorrador.php';
require_once __DIR__ . '/../helpers/audit.php';

class SalidaController
{
    private Movimiento $movimientoModel;
    private SalidaBorrador $borradorModel;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->movimientoModel = new Movimiento();
        $this->borradorModel = new SalidaBorrador();
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

        $productIdsAudit = auditExtractProductIds($detalle);
        $inventoryBefore = auditInventorySnapshot(
            $productIdsAudit
        );

        $resultado = $this->movimientoModel->crearSalida([
            'folio' => $folio,
            'fecha' => $fecha,
            'almacen_id' => $almacenId,
            'usuario_id' => $usuarioId,
            'referencia' => $tipoSalida,
            'tipo_operacion' => $tipoOperacion,
            'observaciones' => $observacionesFinales,
        ], $detalle);

        if (!empty($resultado['success'])) {
            $movimientoId = (int) (
                $resultado['movimiento_id'] ?? 0
            );
            $salidaCreada = $movimientoId > 0
                ? $this->movimientoModel->obtenerSalidaPorId(
                    $movimientoId
                )
                : null;
            $inventoryAfter = auditInventorySnapshot(
                $productIdsAudit
            );

            auditLog([
                'modulo' => 'Salidas',
                'accion' => 'CREAR_SALIDA',
                'entidad' => 'movimiento',
                'registro_id' => $movimientoId ?: null,
                'descripcion' => 'Registró la salida '
                    . ($resultado['folio'] ?? $folio)
                    . ' con ' . count($detalle)
                    . ' producto(s) y documento '
                    . $tipoOperacion . '.',
                'anteriores' => [
                    'existencias' => $inventoryBefore,
                ],
                'nuevos' => [
                    'movimiento' => $salidaCreada ?? [
                        'folio' => $resultado['folio'] ?? $folio,
                        'almacen_id' => $almacenId,
                        'tipo_operacion' => $tipoOperacion,
                        'folio_operacion' => $folioOperacion,
                        'detalle' => $detalle,
                    ],
                    'existencias' => $inventoryAfter,
                ],
            ]);
        }

        return $resultado;
    }

    public function obtenerSalida(int $movimientoId): ?array
    {
        return $this->movimientoModel->obtenerSalidaPorId($movimientoId);
    }

    public function obtenerDetallesSalidas(
        array $movimientoIds
    ): array {
        return $this
            ->movimientoModel
            ->obtenerDetallesSalidas($movimientoIds);
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
    $salidaAnterior =
        $this->movimientoModel->obtenerSalidaPorId(
            $movimientoId
        );
    $productIdsAudit = auditExtractProductIds(
        $salidaAnterior['detalle'] ?? []
    );
    $inventoryBefore = auditInventorySnapshot(
        $productIdsAudit
    );
    $resultado = $this->movimientoModel->cancelarSalida(
        $movimientoId,
        $usuarioId,
        $motivo
    );

    if (!empty($resultado['success'])) {
        $salidaNueva =
            $this->movimientoModel->obtenerSalidaPorId(
                $movimientoId
            );
        $inventoryAfter = auditInventorySnapshot(
            $productIdsAudit
        );

        auditLog([
            'modulo' => 'Salidas',
            'accion' => 'CANCELAR_SALIDA',
            'entidad' => 'movimiento',
            'registro_id' => $movimientoId,
            'descripcion' => 'Canceló la salida '
                . ($salidaAnterior['folio']
                    ?? ('#' . $movimientoId))
                . '. Motivo: '
                . ($motivo !== '' ? $motivo : 'Sin motivo capturado')
                . '.',
            'anteriores' => [
                'movimiento' => $salidaAnterior,
                'existencias' => $inventoryBefore,
            ],
            'nuevos' => [
                'movimiento' => $salidaNueva,
                'existencias' => $inventoryAfter,
            ],
            'metadata' => [
                'motivo_cancelacion' => $motivo,
            ],
        ]);
    }

    return $resultado;
}
    public function actualizar(int $movimientoId, array $postData, int $usuarioId): array
{
    $salidaAnterior =
        $this->movimientoModel->obtenerSalidaPorId(
            $movimientoId
        );

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

    $productIdsAudit = auditExtractProductIds(
        $salidaAnterior['detalle'] ?? [],
        $detalle
    );
    $inventoryBefore = auditInventorySnapshot(
        $productIdsAudit
    );

    $resultado = $this->movimientoModel->editarSalida($movimientoId, [
        'fecha' => $fecha,
        'referencia' => $tipoSalida,
        'tipo_operacion' => $tipoOperacion,
        'observaciones' => $observacionesFinales,
    ], $detalle, $usuarioId);

    if (!empty($resultado['success'])) {
        $salidaNueva =
            $this->movimientoModel->obtenerSalidaPorId(
                $movimientoId
            );
        $inventoryAfter = auditInventorySnapshot(
            $productIdsAudit
        );

        auditLog([
            'modulo' => 'Salidas',
            'accion' => 'ACTUALIZAR_SALIDA',
            'entidad' => 'movimiento',
            'registro_id' => $movimientoId,
            'descripcion' => 'Actualizó la salida '
                . ($salidaNueva['folio']
                    ?? $salidaAnterior['folio']
                    ?? ('#' . $movimientoId))
                . ', incluyendo sus productos, cantidades o ubicaciones.',
            'anteriores' => [
                'movimiento' => $salidaAnterior,
                'existencias' => $inventoryBefore,
            ],
            'nuevos' => [
                'movimiento' => $salidaNueva,
                'existencias' => $inventoryAfter,
            ],
        ]);
    }

    return $resultado;
}

    public function guardarBorrador(
        array $postData,
        int $usuarioId
    ): array {
        $usuario = $_SESSION['user'] ?? [];
        $rol = strtoupper(
            trim((string) ($usuario['rol'] ?? ''))
        );
        $almacenSesionId = (int) (
            $usuario['almacen_id'] ?? 0
        );
        $almacenId = $rol === 'ADMINISTRADOR'
            ? (int) ($postData['almacen_id'] ?? 0)
            : $almacenSesionId;
        $borradorId = (int) (
            $postData['borrador_id'] ?? 0
        );
        $resurtidoId = (int) (
            $postData['resurtido_id'] ?? 0
        );
        $tipoSolicitud = strtoupper(trim((string) (
            $postData['tipo_solicitud'] ?? 'SALIDA'
        )));
        $datos = $postData['datos'] ?? [];

        if (!is_array($datos)) {
            throw new InvalidArgumentException(
                'Los datos del borrador no son válidos.'
            );
        }

        if ($rol === 'JEFE_ALMACEN' && $resurtidoId <= 0) {
            throw new RuntimeException(
                'Esta cuenta solamente puede guardar borradores de Resurtidos o Tickets.'
            );
        }

        $resultado = $this->borradorModel->guardar(
            $usuarioId,
            $almacenId,
            (string) ($postData['nombre'] ?? ''),
            $datos,
            $resurtidoId > 0 ? $resurtidoId : null,
            $tipoSolicitud,
            $borradorId > 0 ? $borradorId : null
        );

        auditLog([
            'modulo' => 'Salidas',
            'accion' => !empty($resultado['actualizado'])
                ? 'ACTUALIZAR_BORRADOR_SALIDA'
                : 'CREAR_BORRADOR_SALIDA',
            'entidad' => 'salida_borrador',
            'registro_id' => $resultado['id'] ?? null,
            'descripcion' => (
                !empty($resultado['actualizado'])
                    ? 'Actualizó'
                    : 'Guardó'
            ) . ' el borrador de salida "'
                . ($resultado['nombre'] ?? 'Sin nombre')
                . '".',
            'nuevos' => [
                'nombre' => $resultado['nombre'] ?? '',
                'almacen_id' => $almacenId,
                'resurtido_id' => $resultado['resurtido_id'] ?? null,
                'tipo_solicitud' => $resultado['tipo_solicitud'] ?? 'SALIDA',
                'total_productos' => (
                    $resultado['total_productos'] ?? 0
                ),
            ],
        ]);

        return $resultado;
    }

    public function listarBorradores(int $usuarioId): array
    {
        return $this->borradorModel->listarPorUsuario($usuarioId);
    }

    public function obtenerBorrador(
        int $borradorId,
        int $usuarioId
    ): ?array {
        $borrador = $this->borradorModel->obtener(
            $borradorId,
            $usuarioId
        );

        if ($borrador) {
            auditLog([
                'modulo' => 'Salidas',
                'accion' => 'CONTINUAR_BORRADOR_SALIDA',
                'entidad' => 'salida_borrador',
                'registro_id' => $borradorId,
                'descripcion' => 'Abrió el borrador de salida "'
                    . ($borrador['nombre'] ?? 'Sin nombre')
                    . '" para continuar su captura.',
            ]);
        }

        return $borrador;
    }

    public function eliminarBorrador(
        int $borradorId,
        int $usuarioId,
        string $motivo = 'ELIMINADO'
    ): bool {
        $borrador = $this->borradorModel->obtener(
            $borradorId,
            $usuarioId
        );

        if (!$borrador) {
            return false;
        }

        $eliminado = $this->borradorModel->eliminar(
            $borradorId,
            $usuarioId
        );

        if ($eliminado) {
            $finalizado = strtoupper($motivo) === 'FINALIZADO';

            auditLog([
                'modulo' => 'Salidas',
                'accion' => $finalizado
                    ? 'FINALIZAR_BORRADOR_SALIDA'
                    : 'ELIMINAR_BORRADOR_SALIDA',
                'entidad' => 'salida_borrador',
                'registro_id' => $borradorId,
                'descripcion' => $finalizado
                    ? 'Convirtió el borrador "'
                        . ($borrador['nombre'] ?? 'Sin nombre')
                        . '" en una salida definitiva.'
                    : 'Eliminó el borrador de salida "'
                        . ($borrador['nombre'] ?? 'Sin nombre')
                        . '".',
            ]);
        }

        return $eliminado;
    }
}
