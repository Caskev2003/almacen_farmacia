<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Devolucion.php';
require_once __DIR__ . '/../helpers/audit.php';

class DevolucionController
{
    private PDO $db;
    private Devolucion $model;

    private const ESTATUS_VALIDOS = [
        'PENDIENTE',
        'EN_PROCESO',
        'DEVUELTO',
        'CANCELADO',
    ];

    private const UBICACIONES_VALIDAS = [
        'FARMACIA',
        'BODEGA',
    ];

    public function __construct(?PDO $db = null)
    {
        if ($db instanceof PDO) {
            $this->db = $db;
        } else {
            $database = new Database();
            $this->db = $database->connect();
        }

        $this->model = new Devolucion($this->db);
    }

    public function buscarProductos(
        string $termino,
        int $almacenId
    ): array {
        $termino = trim($termino);

        if (mb_strlen($termino) < 2) {
            throw new InvalidArgumentException(
                'Escriba por lo menos 2 caracteres para buscar.'
            );
        }

        if (mb_strlen($termino) > 100) {
            throw new InvalidArgumentException(
                'La búsqueda es demasiado larga.'
            );
        }

        $productos = $this->model->buscarProductos($termino);

        auditLog([
            'modulo' => 'Devoluciones',
            'accion' => 'BUSQUEDA_PRODUCTO',
            'entidad' => 'producto',
            'descripcion' => 'Buscó "' . $termino
                . '" en el catálogo para una devolución.',
            'metadata' => [
                'termino' => $termino,
                'resultados' => count($productos),
                'almacen_id' => $almacenId,
            ],
        ]);

        return $productos;
    }

    public function obtenerAlmacenesActivos(): array
    {
        return $this->model->obtenerAlmacenesActivos();
    }

    public function listar(
        ?int $almacenLimite,
        array $filtros = []
    ): array {
        $filtros = $this->normalizarFiltros($filtros);

        return $this->model->listar(
            $almacenLimite,
            $filtros
        );
    }

    public function obtener(
        int $id,
        ?int $almacenLimite
    ): ?array {
        if ($id <= 0) {
            return null;
        }

        return $this->model->obtenerPorId(
            $id,
            $almacenLimite
        );
    }

    public function crear(
        array $datos,
        int $usuarioId
    ): array {
        $validados = $this->validarDatos($datos);
        $validados['usuario_id'] = $usuarioId;

        $id = $this->model->crear($validados);
        $registro = $this->model->obtenerPorId($id);

        auditLog([
            'modulo' => 'Devoluciones',
            'accion' => 'CREAR',
            'entidad' => 'devolucion',
            'registro_id' => $id,
            'descripcion' => 'Registró una devolución de '
                . $validados['piezas'] . ' pieza(s) de '
                . $validados['codigo'] . '.',
            'nuevos' => $registro ?? $validados,
        ]);

        return $registro ?? $validados;
    }

    public function actualizar(
        int $id,
        array $datos,
        int $usuarioId,
        ?int $almacenLimite
    ): array {
        if ($id <= 0) {
            throw new InvalidArgumentException(
                'La devolución indicada no es válida.'
            );
        }

        $anterior = $this->model->obtenerPorId(
            $id,
            $almacenLimite
        );

        if (!$anterior) {
            throw new RuntimeException(
                'No se encontró la devolución o no pertenece a su almacén.'
            );
        }

        $validados = $this->validarDatos($datos);

        $this->model->actualizar(
            $id,
            $validados,
            $almacenLimite
        );

        $nuevo = $this->model->obtenerPorId(
            $id,
            $almacenLimite
        );

        if (!$nuevo) {
            throw new RuntimeException(
                'No fue posible volver a consultar la devolución.'
            );
        }

        auditLog([
            'modulo' => 'Devoluciones',
            'accion' => 'EDITAR',
            'entidad' => 'devolucion',
            'registro_id' => $id,
            'descripcion' => 'Actualizó la devolución del producto '
                . $nuevo['codigo'] . '.',
            'anteriores' => $anterior,
            'nuevos' => $nuevo,
            'metadata' => [
                'usuario_id_accion' => $usuarioId,
            ],
        ]);

        return $nuevo;
    }

    public function marcarTicket(
        int $id,
        bool $tieneTicket,
        int $usuarioId,
        ?int $almacenLimite
    ): array {
        if ($id <= 0) {
            throw new InvalidArgumentException(
                'La devolución indicada no es válida.'
            );
        }

        $anterior = $this->model->obtenerPorId(
            $id,
            $almacenLimite
        );

        if (!$anterior) {
            throw new RuntimeException(
                'No se encontró la devolución o no pertenece a su almacén.'
            );
        }

        $this->model->actualizarTicket(
            $id,
            $tieneTicket,
            $almacenLimite
        );

        $nuevo = $this->model->obtenerPorId(
            $id,
            $almacenLimite
        );

        if (!$nuevo) {
            throw new RuntimeException(
                'No fue posible actualizar el ticket de devolución.'
            );
        }

        auditLog([
            'modulo' => 'Devoluciones',
            'accion' => $tieneTicket
                ? 'MARCAR_TICKET'
                : 'DESMARCAR_TICKET',
            'entidad' => 'devolucion',
            'registro_id' => $id,
            'descripcion' => $tieneTicket
                ? 'Marcó como retirada del sistema la devolución de '
                    . $nuevo['codigo']
                    . '; dejó de apartar existencias.'
                : 'Reabrió la devolución de '
                    . $nuevo['codigo']
                    . '; volvió a apartar existencias.',
            'anteriores' => [
                'tiene_ticket' => (int) $anterior['tiene_ticket'],
            ],
            'nuevos' => [
                'tiene_ticket' => (int) $nuevo['tiene_ticket'],
            ],
            'metadata' => [
                'usuario_id_accion' => $usuarioId,
                'estatus_conservado' => $nuevo['estatus'],
            ],
        ]);

        return $nuevo;
    }

    public function eliminar(
        int $id,
        int $usuarioId,
        ?int $almacenLimite
    ): array {
        if ($id <= 0) {
            throw new InvalidArgumentException(
                'La devolución indicada no es válida.'
            );
        }

        $registro = $this->model->obtenerPorId(
            $id,
            $almacenLimite
        );

        if (!$registro) {
            throw new RuntimeException(
                'No se encontró la devolución o no pertenece a su almacén.'
            );
        }

        if (
            !$this->model->eliminar(
                $id,
                $almacenLimite
            )
        ) {
            throw new RuntimeException(
                'No fue posible eliminar la devolución.'
            );
        }

        auditLog([
            'modulo' => 'Devoluciones',
            'accion' => 'ELIMINAR',
            'entidad' => 'devolucion',
            'registro_id' => $id,
            'descripcion' => 'Eliminó de la tabla la devolución de '
                . $registro['piezas'] . ' pieza(s) del producto '
                . $registro['codigo'] . '.',
            'anteriores' => $registro,
            'metadata' => [
                'usuario_id_accion' => $usuarioId,
            ],
        ]);

        return $registro;
    }

    private function validarDatos(array $datos): array
    {
        $productoId = (int) ($datos['producto_id'] ?? 0);
        $piezas = filter_var(
            $datos['piezas'] ?? null,
            FILTER_VALIDATE_INT
        );
        $anio = filter_var(
            $datos['anio'] ?? null,
            FILTER_VALIDATE_INT
        );
        $mes = filter_var(
            $datos['mes'] ?? null,
            FILTER_VALIDATE_INT
        );
        $almacenId = (int) ($datos['almacen_id'] ?? 0);

        $motivo = trim((string) ($datos['motivo'] ?? ''));
        $estatus = strtoupper(
            trim((string) ($datos['estatus'] ?? 'PENDIENTE'))
        );
        $fecha = trim((string) ($datos['fecha'] ?? ''));
        $ubicacion = strtoupper(
            trim((string) ($datos['ubicacion'] ?? ''))
        );
        $observaciones = trim(
            (string) ($datos['observaciones'] ?? '')
        );

        if ($productoId <= 0) {
            throw new InvalidArgumentException(
                'Seleccione un producto de los resultados de búsqueda.'
            );
        }

        $producto = $this->model->obtenerProducto($productoId);

        if (!$producto) {
            throw new InvalidArgumentException(
                'El producto seleccionado ya no está disponible.'
            );
        }

        if ($piezas === false || $piezas <= 0) {
            throw new InvalidArgumentException(
                'Las piezas deben ser un número entero mayor que cero.'
            );
        }

        if ($piezas > 999999999) {
            throw new InvalidArgumentException(
                'La cantidad de piezas es demasiado grande.'
            );
        }

        if ($anio === false || $anio < 2000 || $anio > 2200) {
            throw new InvalidArgumentException(
                'El año debe estar entre 2000 y 2200.'
            );
        }

        if ($mes === false || $mes < 1 || $mes > 12) {
            throw new InvalidArgumentException(
                'Seleccione un mes válido.'
            );
        }

        if ($motivo === '') {
            throw new InvalidArgumentException(
                'El motivo es obligatorio.'
            );
        }

        if (mb_strlen($motivo) > 255) {
            throw new InvalidArgumentException(
                'El motivo no puede superar los 255 caracteres.'
            );
        }

        if (!in_array($estatus, self::ESTATUS_VALIDOS, true)) {
            throw new InvalidArgumentException(
                'El status seleccionado no es válido.'
            );
        }

        if (!$this->fechaValida($fecha)) {
            throw new InvalidArgumentException(
                'Seleccione una fecha válida.'
            );
        }

        if (!in_array($ubicacion, self::UBICACIONES_VALIDAS, true)) {
            throw new InvalidArgumentException(
                'La ubicación debe ser Farmacia o Bodega.'
            );
        }

        if (mb_strlen($observaciones) > 3000) {
            throw new InvalidArgumentException(
                'Las observaciones no pueden superar los 3000 caracteres.'
            );
        }

        if (
            $almacenId <= 0
            || !$this->model->almacenActivoExiste($almacenId)
        ) {
            throw new InvalidArgumentException(
                'Seleccione un almacén o sucursal válido.'
            );
        }

        return [
            'producto_id' => $productoId,
            'codigo' => trim((string) $producto['codigo']),
            'descripcion' => trim((string) $producto['descripcion']),
            'piezas' => (int) $piezas,
            'anio' => (int) $anio,
            'mes' => (int) $mes,
            'motivo' => $motivo,
            'estatus' => $estatus,
            'fecha' => $fecha,
            'ubicacion' => $ubicacion,
            'observaciones' => $observaciones,
            'almacen_id' => $almacenId,
        ];
    }

    private function normalizarFiltros(array $filtros): array
    {
        $texto = trim((string) ($filtros['texto'] ?? ''));
        $estatus = strtoupper(
            trim((string) ($filtros['estatus'] ?? ''))
        );
        $ubicacion = strtoupper(
            trim((string) ($filtros['ubicacion'] ?? ''))
        );
        $ticket = strtoupper(
            trim((string) ($filtros['ticket'] ?? ''))
        );

        if (mb_strlen($texto) > 100) {
            $texto = mb_substr($texto, 0, 100);
        }

        if (
            $estatus !== ''
            && !in_array($estatus, self::ESTATUS_VALIDOS, true)
        ) {
            $estatus = '';
        }

        if (
            $ubicacion !== ''
            && !in_array($ubicacion, self::UBICACIONES_VALIDAS, true)
        ) {
            $ubicacion = '';
        }

        if (!in_array($ticket, ['', 'CON', 'SIN'], true)) {
            $ticket = '';
        }

        return [
            'texto' => $texto,
            'estatus' => $estatus,
            'ubicacion' => $ubicacion,
            'ticket' => $ticket,
        ];
    }

    private function fechaValida(string $fecha): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return false;
        }

        $date = DateTime::createFromFormat(
            '!Y-m-d',
            $fecha
        );

        $errores = DateTime::getLastErrors();

        return $date instanceof DateTime
            && (
                $errores === false
                || (
                    $errores['warning_count'] === 0
                    && $errores['error_count'] === 0
                )
            )
            && $date->format('Y-m-d') === $fecha;
    }
}
