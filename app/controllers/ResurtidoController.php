<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Resurtido.php';

class ResurtidoController
{
    private PDO $db;
    private Resurtido $resurtidoModel;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? $this->obtenerConexion();

        $this->resurtidoModel = new Resurtido(
            $this->db
        );
    }

    // ==================================================
    // BUSCAR PRODUCTOS POR LOS ÚLTIMOS CUATRO DÍGITOS
    // ==================================================

    public function buscarPorUltimosDigitos(
        string $codigo
    ): array {
        $codigo = preg_replace(
            '/\D/',
            '',
            trim($codigo)
        );

        if (
            !is_string($codigo)
            || strlen($codigo) !== 4
        ) {
            throw new InvalidArgumentException(
                'Ingrese exactamente los últimos 4 dígitos del código.'
            );
        }

        return $this
            ->resurtidoModel
            ->buscarPorUltimosDigitos($codigo);
    }

    // ==================================================
    // CREAR SOLICITUD DE RESURTIDO
    // ==================================================

    public function crear(array $datos): array
    {
        $solicitanteId = (int) (
            $datos['solicitante_id']
            ?? $datos['usuario_id']
            ?? 0
        );

        $almacenId = (int) (
            $datos['almacen_id']
            ?? 0
        );

        $observaciones = trim(
            (string) (
                $datos['observaciones']
                ?? ''
            )
        );

        $productos = $datos['productos'] ?? [];

        if ($solicitanteId <= 0) {
            throw new InvalidArgumentException(
                'No se pudo identificar al usuario solicitante.'
            );
        }

        if ($almacenId <= 0) {
            throw new InvalidArgumentException(
                'No se pudo identificar el almacén o sucursal.'
            );
        }

        if (
            !is_array($productos)
            || empty($productos)
        ) {
            throw new InvalidArgumentException(
                'Debe agregar por lo menos un producto.'
            );
        }

        if (count($productos) > 300) {
            throw new InvalidArgumentException(
                'La solicitud no puede contener más de 300 productos.'
            );
        }

        if (mb_strlen($observaciones) > 1000) {
            throw new InvalidArgumentException(
                'Las observaciones no pueden superar los 1000 caracteres.'
            );
        }

        $productosValidados = [];

        foreach ($productos as $indice => $producto) {
            if (!is_array($producto)) {
                throw new InvalidArgumentException(
                    'El producto número '
                    . ($indice + 1)
                    . ' no es válido.'
                );
            }

            $productoId = (int) (
                $producto['producto_id']
                ?? $producto['id']
                ?? 0
            );

            $cantidad = $this->normalizarCantidad(
                $producto['cantidad']
                ?? $producto['cantidad_solicitada']
                ?? 0
            );

            $unidad = strtoupper(
                trim(
                    (string) (
                        $producto['unidad']
                        ?? 'PIEZA'
                    )
                )
            );

            if ($productoId <= 0) {
                throw new InvalidArgumentException(
                    'El producto número '
                    . ($indice + 1)
                    . ' no es válido.'
                );
            }

            if ($cantidad <= 0) {
                throw new InvalidArgumentException(
                    'La cantidad del producto número '
                    . ($indice + 1)
                    . ' debe ser mayor que cero.'
                );
            }

            if ($cantidad > 999999999) {
                throw new InvalidArgumentException(
                    'La cantidad del producto número '
                    . ($indice + 1)
                    . ' es demasiado grande.'
                );
            }

            if ($unidad === '') {
                $unidad = 'PIEZA';
            }

            if (mb_strlen($unidad) > 30) {
                $unidad = mb_substr(
                    $unidad,
                    0,
                    30
                );
            }

            $productosValidados[] = [
                'producto_id' => $productoId,
                'cantidad' => $cantidad,
                'unidad' => $unidad
            ];
        }

        return $this->resurtidoModel->crear([
            'solicitante_id' => $solicitanteId,
            'almacen_id' => $almacenId,
            'observaciones' => (
                $observaciones !== ''
                    ? $observaciones
                    : null
            ),
            'productos' => $productosValidados
        ]);
    }

    // ==================================================
    // OBTENER UNA SOLICITUD POR ID
    // ==================================================

    public function obtenerPorId(
        int $resurtidoId
    ): ?array {
        if ($resurtidoId <= 0) {
            throw new InvalidArgumentException(
                'El identificador del resurtido no es válido.'
            );
        }

        return $this
            ->resurtidoModel
            ->obtenerPorId($resurtidoId);
    }

    // ==================================================
    // OBTENER SOLICITUDES DE UN GERENTE
    // ==================================================

    public function obtenerPorGerente(
        int $solicitanteId,
        int $limite = 100
    ): array {
        if ($solicitanteId <= 0) {
            throw new InvalidArgumentException(
                'El usuario solicitante no es válido.'
            );
        }

        $limite = $this->normalizarLimite(
            $limite
        );

        return $this
            ->resurtidoModel
            ->obtenerPorGerente(
                $solicitanteId,
                $limite
            );
    }

    // ==================================================
    // OBTENER TODAS LAS SOLICITUDES
    // ==================================================

    public function obtenerTodos(
        ?int $almacenId = null,
        int $limite = 150
    ): array {
        if (
            $almacenId !== null
            && $almacenId <= 0
        ) {
            $almacenId = null;
        }

        $limite = $this->normalizarLimite(
            $limite
        );

        return $this
            ->resurtidoModel
            ->obtenerTodos(
                $almacenId,
                $limite
            );
    }

    // ==================================================
    // OBTENER SOLICITUDES PENDIENTES
    // ==================================================

    public function obtenerPendientes(
        ?int $almacenId = null
    ): array {
        if (
            $almacenId !== null
            && $almacenId <= 0
        ) {
            $almacenId = null;
        }

        return $this
            ->resurtidoModel
            ->obtenerPendientes($almacenId);
    }

    // ==================================================
    // CONTAR NOTIFICACIONES PENDIENTES
    // ==================================================

    public function contarPendientes(
        ?int $almacenId = null
    ): int {
        if (
            $almacenId !== null
            && $almacenId <= 0
        ) {
            $almacenId = null;
        }

        return $this
            ->resurtidoModel
            ->contarPendientes($almacenId);
    }

    // ==================================================
    // CAMBIAR ESTADO DE UNA SOLICITUD
    // ==================================================

    public function cambiarEstado(
        int $resurtidoId,
        string $estado,
        int $encargadoId
    ): bool {
        if ($resurtidoId <= 0) {
            throw new InvalidArgumentException(
                'El resurtido indicado no es válido.'
            );
        }

        if ($encargadoId <= 0) {
            throw new InvalidArgumentException(
                'El encargado indicado no es válido.'
            );
        }

        $estado = strtoupper(
            trim($estado)
        );

        $estadosPermitidos = [
            'PENDIENTE',
            'EN_PROCESO',
            'SURTIDO',
            'PARCIAL',
            'CANCELADO'
        ];

        if (
            !in_array(
                $estado,
                $estadosPermitidos,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'El estado indicado no es válido.'
            );
        }

        return $this
            ->resurtidoModel
            ->cambiarEstado(
                $resurtidoId,
                $estado,
                $encargadoId
            );
    }

    // ==================================================
    // INICIAR EL SURTIDO
    // ==================================================

    public function iniciarSurtido(
        int $resurtidoId,
        int $encargadoId
    ): array {
        if ($resurtidoId <= 0) {
            throw new InvalidArgumentException(
                'El resurtido no es válido.'
            );
        }

        if ($encargadoId <= 0) {
            throw new InvalidArgumentException(
                'El encargado no es válido.'
            );
        }

        $resurtido = $this
            ->resurtidoModel
            ->obtenerPorId($resurtidoId);

        if (!$resurtido) {
            throw new RuntimeException(
                'No se encontró la solicitud de resurtido.'
            );
        }

        if (
            ($resurtido['estado'] ?? '')
            === 'CANCELADO'
        ) {
            throw new RuntimeException(
                'La solicitud fue cancelada y no puede surtirse.'
            );
        }

        if (
            ($resurtido['estado'] ?? '')
            === 'SURTIDO'
        ) {
            throw new RuntimeException(
                'La solicitud ya fue surtida.'
            );
        }

        if (!empty($resurtido['salida_id'])) {
            throw new RuntimeException(
                'Esta solicitud ya se encuentra vinculada con una salida.'
            );
        }

        if (
            ($resurtido['estado'] ?? '')
            === 'PENDIENTE'
        ) {
            $this
                ->resurtidoModel
                ->iniciarSurtido(
                    $resurtidoId,
                    $encargadoId
                );
        }

        $resurtidoActualizado = $this
            ->resurtidoModel
            ->obtenerPorId($resurtidoId);

        if (!$resurtidoActualizado) {
            throw new RuntimeException(
                'No fue posible volver a consultar el resurtido.'
            );
        }

        return $resurtidoActualizado;
    }

    // ==================================================
    // FINALIZAR Y VINCULAR CON UNA SALIDA
    // ==================================================

    public function finalizarConSalida(
        int $resurtidoId,
        int $salidaId,
        int $encargadoId,
        array $cantidadesSurtidas
    ): array {
        if ($resurtidoId <= 0) {
            throw new InvalidArgumentException(
                'El resurtido no es válido.'
            );
        }

        if ($salidaId <= 0) {
            throw new InvalidArgumentException(
                'La salida no es válida.'
            );
        }

        if ($encargadoId <= 0) {
            throw new InvalidArgumentException(
                'El encargado no es válido.'
            );
        }

        if (
            !is_array($cantidadesSurtidas)
            || empty($cantidadesSurtidas)
        ) {
            throw new InvalidArgumentException(
                'Debe proporcionar los productos realmente surtidos.'
            );
        }

        $cantidadesValidadas = [];

        foreach (
            $cantidadesSurtidas as $indice => $producto
        ) {
            if (!is_array($producto)) {
                throw new InvalidArgumentException(
                    'La información del producto número '
                    . ($indice + 1)
                    . ' no es válida.'
                );
            }

            $productoId = (int) (
                $producto['producto_id']
                ?? $producto['id']
                ?? 0
            );

            $cantidadSurtida = $this->normalizarCantidad(
                $producto['cantidad_surtida']
                ?? $producto['cantidad']
                ?? 0
            );

            if ($productoId <= 0) {
                throw new InvalidArgumentException(
                    'El producto número '
                    . ($indice + 1)
                    . ' no es válido.'
                );
            }

            if ($cantidadSurtida < 0) {
                throw new InvalidArgumentException(
                    'La cantidad surtida no puede ser negativa.'
                );
            }

            if ($cantidadSurtida > 999999999) {
                throw new InvalidArgumentException(
                    'La cantidad surtida del producto número '
                    . ($indice + 1)
                    . ' es demasiado grande.'
                );
            }

            $cantidadesValidadas[] = [
                'producto_id' => $productoId,
                'cantidad_surtida' => $cantidadSurtida
            ];
        }

        return $this
            ->resurtidoModel
            ->finalizarConSalida(
                $resurtidoId,
                $salidaId,
                $encargadoId,
                $cantidadesValidadas
            );
    }

    // ==================================================
    // VERIFICAR SI EXISTE UN FOLIO
    // ==================================================

    public function existeFolio(
        string $folio
    ): bool {
        $folio = strtoupper(
            trim($folio)
        );

        if ($folio === '') {
            return false;
        }

        if (mb_strlen($folio) > 50) {
            return false;
        }

        return $this
            ->resurtidoModel
            ->existeFolio($folio);
    }

    // ==================================================
    // OBTENER CONEXIÓN PDO
    // ==================================================

    private function obtenerConexion(): PDO
    {
        /*
         * Tu database.php utiliza connect() como método
         * de instancia, por lo que primero se crea el
         * objeto Database.
         */
        $database = new Database();
        $conexion = $database->connect();

        if (!$conexion instanceof PDO) {
            throw new RuntimeException(
                'No fue posible establecer la conexión con la base de datos.'
            );
        }

        return $conexion;
    }

    // ==================================================
    // NORMALIZAR UNA CANTIDAD
    // ==================================================

    private function normalizarCantidad(
        mixed $cantidad
    ): float {
        if (is_string($cantidad)) {
            $cantidad = trim($cantidad);

            /*
             * Permitir cantidades escritas con coma
             * decimal, por ejemplo: 10,5.
             */
            $cantidad = str_replace(
                ',',
                '.',
                $cantidad
            );
        }

        if (!is_numeric($cantidad)) {
            throw new InvalidArgumentException(
                'Una de las cantidades no es válida.'
            );
        }

        $cantidadNormalizada = (float) $cantidad;

        if (!is_finite($cantidadNormalizada)) {
            throw new InvalidArgumentException(
                'Una de las cantidades no es válida.'
            );
        }

        return $cantidadNormalizada;
    }

    // ==================================================
    // NORMALIZAR LÍMITE DE RESULTADOS
    // ==================================================

    private function normalizarLimite(
        int $limite
    ): int {
        if ($limite < 1) {
            return 1;
        }

        if ($limite > 500) {
            return 500;
        }

        return $limite;
    }
}