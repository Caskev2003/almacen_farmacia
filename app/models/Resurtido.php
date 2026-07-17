<?php

declare(strict_types=1);

class Resurtido
{
    private PDO $db;

    private const ESTADOS = [
        'PENDIENTE',
        'EN_PROCESO',
        'SURTIDO',
        'PARCIAL',
        'CANCELADO'
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;

        $this->db->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        $this->db->setAttribute(
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::FETCH_ASSOC
        );
    }

    // ==================================================
    // BUSCAR PRODUCTOS POR LOS ÚLTIMOS CUATRO DÍGITOS
    // ==================================================

    public function buscarPorUltimosDigitos(
        string $ultimosDigitos
    ): array {
        $ultimosDigitos = trim($ultimosDigitos);

        if (!preg_match('/^\d{4}$/', $ultimosDigitos)) {
            throw new InvalidArgumentException(
                'Debe ingresar exactamente los últimos 4 dígitos.'
            );
        }

        $sql = "
            SELECT
                p.id,
                p.codigo,
                p.codigo_barras,
                p.descripcion,

                COALESCE(
                    NULLIF(TRIM(p.unidad_medida), ''),
                    'PIEZA'
                ) AS unidad,

                p.existencia_bodega

            FROM productos AS p

            WHERE p.estado = 1

            AND (
                RIGHT(TRIM(p.codigo), 4) = :codigo
                OR RIGHT(
                    TRIM(
                        COALESCE(p.codigo_barras, '')
                    ),
                    4
                ) = :codigo_barras
            )

            ORDER BY
                p.descripcion ASC

            LIMIT 30
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':codigo' => $ultimosDigitos,
            ':codigo_barras' => $ultimosDigitos
        ]);

        $productos = $stmt->fetchAll();

        foreach ($productos as &$producto) {
            $producto['id'] = (int) $producto['id'];

            $producto['existencia_bodega'] = (int) (
                $producto['existencia_bodega'] ?? 0
            );
        }

        unset($producto);

        return $productos;
    }

    // ==================================================
    // CREAR SOLICITUD DE RESURTIDO
    // ==================================================

    public function crear(array $datos): array
    {
        $solicitanteId = (int) (
            $datos['usuario_id']
            ?? $datos['solicitante_id']
            ?? 0
        );

        $almacenId = (int) (
            $datos['almacen_id'] ?? 0
        );

        $observaciones = trim(
            (string) (
                $datos['observaciones'] ?? ''
            )
        );

        $productos = $datos['productos'] ?? [];

        if ($solicitanteId <= 0) {
            throw new InvalidArgumentException(
                'El usuario solicitante no es válido.'
            );
        }

        if ($almacenId <= 0) {
            throw new InvalidArgumentException(
                'El almacén o sucursal no es válido.'
            );
        }

        if (!is_array($productos) || empty($productos)) {
            throw new InvalidArgumentException(
                'Debe agregar por lo menos un producto.'
            );
        }

        $productos = $this->normalizarProductos(
            $productos
        );

        $this->db->beginTransaction();

        try {
            $folioTemporal =
                'TMP-'
                . $solicitanteId
                . '-'
                . bin2hex(random_bytes(8));

            $sqlResurtido = "
                INSERT INTO resurtidos (
                    folio,
                    fecha_solicitud,
                    solicitante_id,
                    almacen_id,
                    observaciones,
                    estado,
                    creado_en,
                    actualizado_en
                ) VALUES (
                    :folio,
                    NOW(),
                    :solicitante_id,
                    :almacen_id,
                    :observaciones,
                    'PENDIENTE',
                    NOW(),
                    NOW()
                )
            ";

            $stmtResurtido = $this->db->prepare(
                $sqlResurtido
            );

            $stmtResurtido->execute([
                ':folio' => $folioTemporal,
                ':solicitante_id' => $solicitanteId,
                ':almacen_id' => $almacenId,
                ':observaciones' => (
                    $observaciones !== ''
                        ? $observaciones
                        : null
                )
            ]);

            $resurtidoId = (int) $this->db->lastInsertId();

            if ($resurtidoId <= 0) {
                throw new RuntimeException(
                    'No fue posible obtener el identificador del resurtido.'
                );
            }

            $folio = $this->generarFolio(
                $resurtidoId,
                $almacenId
            );

            $sqlActualizarFolio = "
                UPDATE resurtidos
                SET
                    folio = :folio,
                    actualizado_en = NOW()
                WHERE id = :id
            ";

            $stmtActualizarFolio = $this->db->prepare(
                $sqlActualizarFolio
            );

            $stmtActualizarFolio->execute([
                ':folio' => $folio,
                ':id' => $resurtidoId
            ]);

            $sqlProducto = "
                SELECT
                    id,
                    COALESCE(
                        NULLIF(TRIM(unidad_medida), ''),
                        'PIEZA'
                    ) AS unidad
                FROM productos
                WHERE
                    id = :producto_id
                    AND estado = 1
                LIMIT 1
            ";

            $stmtProducto = $this->db->prepare(
                $sqlProducto
            );

            $sqlDetalle = "
                INSERT INTO resurtido_detalles (
                    resurtido_id,
                    producto_id,
                    cantidad_solicitada,
                    cantidad_surtida,
                    unidad,
                    creado_en
                ) VALUES (
                    :resurtido_id,
                    :producto_id,
                    :cantidad_solicitada,
                    0,
                    :unidad,
                    NOW()
                )
            ";

            $stmtDetalle = $this->db->prepare(
                $sqlDetalle
            );

            foreach ($productos as $producto) {
                $productoId = (int) (
                    $producto['producto_id']
                );

                $stmtProducto->execute([
                    ':producto_id' => $productoId
                ]);

                $productoBase = $stmtProducto->fetch();

                if (!$productoBase) {
                    throw new RuntimeException(
                        'Uno de los productos seleccionados no existe o está inactivo.'
                    );
                }

                /*
                 * La unidad se obtiene nuevamente desde la base
                 * de datos para evitar datos manipulados.
                 */
                $unidad = strtoupper(
                    trim(
                        (string) (
                            $productoBase['unidad']
                            ?? 'PIEZA'
                        )
                    )
                );

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

                $stmtDetalle->execute([
                    ':resurtido_id' => $resurtidoId,
                    ':producto_id' => $productoId,
                    ':cantidad_solicitada' =>
                        $producto['cantidad'],

                    ':unidad' => $unidad
                ]);
            }

            $this->db->commit();

            return [
                'id' => $resurtidoId,
                'folio' => $folio,
                'estado' => 'PENDIENTE'
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    // ==================================================
    // OBTENER RESURTIDO POR ID
    // ==================================================

    public function obtenerPorId(
        int $resurtidoId
    ): ?array {
        if ($resurtidoId <= 0) {
            return null;
        }

        $sql = "
            SELECT
                r.id,
                r.folio,
                r.fecha_solicitud,
                r.solicitante_id,
                r.almacen_id,
                r.observaciones,
                r.estado,
                r.encargado_id,
                r.salida_id,
                r.fecha_atencion,
                r.creado_en,
                r.actualizado_en,

                u.nombre AS solicitante_nombre,
                ue.nombre AS encargado_nombre,
                a.nombre AS almacen_nombre,

                COUNT(rd.id) AS total_productos,

                COALESCE(
                    SUM(rd.cantidad_solicitada),
                    0
                ) AS total_cantidad_solicitada,

                COALESCE(
                    SUM(rd.cantidad_surtida),
                    0
                ) AS total_cantidad_surtida

            FROM resurtidos AS r

            INNER JOIN usuarios AS u
                ON u.id = r.solicitante_id

            LEFT JOIN usuarios AS ue
                ON ue.id = r.encargado_id

            LEFT JOIN almacenes AS a
                ON a.id = r.almacen_id

            LEFT JOIN resurtido_detalles AS rd
                ON rd.resurtido_id = r.id

            WHERE r.id = :id

            GROUP BY
                r.id,
                r.folio,
                r.fecha_solicitud,
                r.solicitante_id,
                r.almacen_id,
                r.observaciones,
                r.estado,
                r.encargado_id,
                r.salida_id,
                r.fecha_atencion,
                r.creado_en,
                r.actualizado_en,
                u.nombre,
                ue.nombre,
                a.nombre

            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':id' => $resurtidoId
        ]);

        $resurtido = $stmt->fetch();

        if (!$resurtido) {
            return null;
        }

        $resurtido['id'] = (int) $resurtido['id'];

        $resurtido['solicitante_id'] = (int) (
            $resurtido['solicitante_id']
        );

        $resurtido['almacen_id'] = (int) (
            $resurtido['almacen_id']
        );

        $resurtido['encargado_id'] =
            $resurtido['encargado_id'] !== null
                ? (int) $resurtido['encargado_id']
                : null;

        $resurtido['salida_id'] =
            $resurtido['salida_id'] !== null
                ? (int) $resurtido['salida_id']
                : null;

        $resurtido['total_productos'] = (int) (
            $resurtido['total_productos']
        );

        $resurtido['total_cantidad_solicitada'] =
            (float) (
                $resurtido['total_cantidad_solicitada']
            );

        $resurtido['total_cantidad_surtida'] =
            (float) (
                $resurtido['total_cantidad_surtida']
            );

        $resurtido['productos'] =
            $this->obtenerDetalles($resurtidoId);

        return $resurtido;
    }

    // ==================================================
    // OBTENER PRODUCTOS DEL RESURTIDO
    // ==================================================

    public function obtenerDetalles(
        int $resurtidoId
    ): array {
        if ($resurtidoId <= 0) {
            return [];
        }

        $sql = "
            SELECT
                rd.id,
                rd.resurtido_id,
                rd.producto_id,
                rd.cantidad_solicitada,
                rd.cantidad_surtida,
                rd.unidad,

                p.codigo,
                p.codigo_barras,
                p.descripcion,
                p.existencia_bodega

            FROM resurtido_detalles AS rd

            INNER JOIN productos AS p
                ON p.id = rd.producto_id

            WHERE rd.resurtido_id = :resurtido_id

            ORDER BY
                rd.id ASC
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':resurtido_id' => $resurtidoId
        ]);

        $detalles = $stmt->fetchAll();

        foreach ($detalles as &$detalle) {
            $detalle['id'] = (int) $detalle['id'];

            $detalle['resurtido_id'] = (int) (
                $detalle['resurtido_id']
            );

            $detalle['producto_id'] = (int) (
                $detalle['producto_id']
            );

            $detalle['cantidad_solicitada'] = (float) (
                $detalle['cantidad_solicitada']
            );

            $detalle['cantidad_surtida'] = (float) (
                $detalle['cantidad_surtida']
            );

            $detalle['existencia_bodega'] = (int) (
                $detalle['existencia_bodega'] ?? 0
            );
        }

        unset($detalle);

        return $detalles;
    }

    // ==================================================
    // OBTENER SOLICITUDES CREADAS POR UN GERENTE
    // ==================================================

    public function obtenerPorGerente(
        int $solicitanteId,
        ?int $limite = 100
    ): array {
        if ($solicitanteId <= 0) {
            return [];
        }

        $limite = $this->normalizarLimite(
            $limite
        );

        $sql = "
            SELECT
                r.id,
                r.folio,
                r.fecha_solicitud AS fecha,
                r.observaciones,
                r.estado,
                r.almacen_id,
                r.encargado_id,
                r.salida_id,

                a.nombre AS almacen_nombre,
                ue.nombre AS encargado_nombre,

                COUNT(rd.id) AS total_productos,

                COALESCE(
                    SUM(rd.cantidad_solicitada),
                    0
                ) AS total_cantidad

            FROM resurtidos AS r

            LEFT JOIN almacenes AS a
                ON a.id = r.almacen_id

            LEFT JOIN usuarios AS ue
                ON ue.id = r.encargado_id

            LEFT JOIN resurtido_detalles AS rd
                ON rd.resurtido_id = r.id

            WHERE r.solicitante_id = :solicitante_id

            GROUP BY
                r.id,
                r.folio,
                r.fecha_solicitud,
                r.observaciones,
                r.estado,
                r.almacen_id,
                r.encargado_id,
                r.salida_id,
                a.nombre,
                ue.nombre

            ORDER BY
                r.fecha_solicitud DESC

            LIMIT {$limite}
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':solicitante_id' => $solicitanteId
        ]);

        return $this->convertirLista(
            $stmt->fetchAll()
        );
    }

    // ==================================================
    // OBTENER TODAS LAS SOLICITUDES
    // ==================================================

    public function obtenerTodos(
        ?int $almacenId = null,
        ?int $limite = 150
    ): array {
        $limite = $this->normalizarLimite(
            $limite
        );

        $condicion = '';
        $parametros = [];

        if ($almacenId !== null && $almacenId > 0) {
            $condicion =
                'WHERE r.almacen_id = :almacen_id';

            $parametros[':almacen_id'] = $almacenId;
        }

        $sql = "
            SELECT
                r.id,
                r.folio,
                r.fecha_solicitud AS fecha,
                r.solicitante_id,
                r.almacen_id,
                r.observaciones,
                r.estado,
                r.encargado_id,
                r.salida_id,

                u.nombre AS solicitante_nombre,
                ue.nombre AS encargado_nombre,
                a.nombre AS almacen_nombre,

                COUNT(rd.id) AS total_productos,

                COALESCE(
                    SUM(rd.cantidad_solicitada),
                    0
                ) AS total_cantidad

            FROM resurtidos AS r

            INNER JOIN usuarios AS u
                ON u.id = r.solicitante_id

            LEFT JOIN usuarios AS ue
                ON ue.id = r.encargado_id

            LEFT JOIN almacenes AS a
                ON a.id = r.almacen_id

            LEFT JOIN resurtido_detalles AS rd
                ON rd.resurtido_id = r.id

            {$condicion}

            GROUP BY
                r.id,
                r.folio,
                r.fecha_solicitud,
                r.solicitante_id,
                r.almacen_id,
                r.observaciones,
                r.estado,
                r.encargado_id,
                r.salida_id,
                u.nombre,
                ue.nombre,
                a.nombre

            ORDER BY
                CASE r.estado
                    WHEN 'PENDIENTE' THEN 1
                    WHEN 'EN_PROCESO' THEN 2
                    WHEN 'PARCIAL' THEN 3
                    WHEN 'SURTIDO' THEN 4
                    WHEN 'CANCELADO' THEN 5
                    ELSE 6
                END,
                r.fecha_solicitud DESC

            LIMIT {$limite}
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($parametros);

        return $this->convertirLista(
            $stmt->fetchAll()
        );
    }

    // ==================================================
    // OBTENER SOLICITUDES PENDIENTES
    // ==================================================

    public function obtenerPendientes(
        ?int $almacenId = null
    ): array {
        $condicionAlmacen = '';
        $parametros = [];

        if ($almacenId !== null && $almacenId > 0) {
            $condicionAlmacen =
                'AND r.almacen_id = :almacen_id';

            $parametros[':almacen_id'] = $almacenId;
        }

        $sql = "
            SELECT
                r.id,
                r.folio,
                r.fecha_solicitud AS fecha,
                r.solicitante_id,
                r.almacen_id,
                r.observaciones,
                r.estado,

                u.nombre AS solicitante_nombre,
                a.nombre AS almacen_nombre,

                COUNT(rd.id) AS total_productos,

                COALESCE(
                    SUM(rd.cantidad_solicitada),
                    0
                ) AS total_cantidad

            FROM resurtidos AS r

            INNER JOIN usuarios AS u
                ON u.id = r.solicitante_id

            LEFT JOIN almacenes AS a
                ON a.id = r.almacen_id

            LEFT JOIN resurtido_detalles AS rd
                ON rd.resurtido_id = r.id

            WHERE r.estado IN (
                'PENDIENTE',
                'EN_PROCESO',
                'PARCIAL'
            )

            {$condicionAlmacen}

            GROUP BY
                r.id,
                r.folio,
                r.fecha_solicitud,
                r.solicitante_id,
                r.almacen_id,
                r.observaciones,
                r.estado,
                u.nombre,
                a.nombre

            ORDER BY
                CASE r.estado
                    WHEN 'PENDIENTE' THEN 1
                    WHEN 'EN_PROCESO' THEN 2
                    WHEN 'PARCIAL' THEN 3
                    ELSE 4
                END,
                r.fecha_solicitud ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($parametros);

        return $this->convertirLista(
            $stmt->fetchAll()
        );
    }

    // ==================================================
    // CONTAR SOLICITUDES PENDIENTES
    // ==================================================

    public function contarPendientes(
        ?int $almacenId = null
    ): int {
        $condicionAlmacen = '';
        $parametros = [];

        if ($almacenId !== null && $almacenId > 0) {
            $condicionAlmacen =
                'AND almacen_id = :almacen_id';

            $parametros[':almacen_id'] = $almacenId;
        }

        $sql = "
            SELECT COUNT(*)
            FROM resurtidos
            WHERE estado = 'PENDIENTE'
            {$condicionAlmacen}
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($parametros);

        return (int) $stmt->fetchColumn();
    }

    // ==================================================
    // CAMBIAR ESTADO
    // ==================================================

    public function cambiarEstado(
        int $resurtidoId,
        string $estado,
        int $encargadoId
    ): bool {
        if ($resurtidoId <= 0) {
            throw new InvalidArgumentException(
                'El resurtido no es válido.'
            );
        }

        if ($encargadoId <= 0) {
            throw new InvalidArgumentException(
                'El usuario encargado no es válido.'
            );
        }

        $estado = strtoupper(
            trim($estado)
        );

        if (!in_array($estado, self::ESTADOS, true)) {
            throw new InvalidArgumentException(
                'El estado indicado no es válido.'
            );
        }

        $resurtidoActual = $this->obtenerRegistroBasico(
            $resurtidoId
        );

        if (!$resurtidoActual) {
            throw new RuntimeException(
                'No se encontró la solicitud de resurtido.'
            );
        }

        if (
            in_array(
                $resurtidoActual['estado'],
                ['SURTIDO', 'CANCELADO'],
                true
            )
        ) {
            throw new RuntimeException(
                'La solicitud ya está finalizada.'
            );
        }

        $asignarEncargado = in_array(
            $estado,
            [
                'EN_PROCESO',
                'PARCIAL',
                'SURTIDO'
            ],
            true
        );

        $registrarAtencion = in_array(
            $estado,
            [
                'EN_PROCESO',
                'PARCIAL',
                'SURTIDO',
                'CANCELADO'
            ],
            true
        );

        $sql = "
            UPDATE resurtidos
            SET
                estado = :estado,
                encargado_id = :encargado_id,
                fecha_atencion = :fecha_atencion,
                actualizado_en = NOW()
            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':estado' => $estado,

            ':encargado_id' => (
                $asignarEncargado
                    ? $encargadoId
                    : $resurtidoActual['encargado_id']
            ),

            ':fecha_atencion' => (
                $registrarAtencion
                    ? date('Y-m-d H:i:s')
                    : $resurtidoActual['fecha_atencion']
            ),

            ':id' => $resurtidoId
        ]);
    }

    // ==================================================
    // INICIAR SURTIDO
    // ==================================================

    public function iniciarSurtido(
        int $resurtidoId,
        int $encargadoId
    ): bool {
        return $this->cambiarEstado(
            $resurtidoId,
            'EN_PROCESO',
            $encargadoId
        );
    }

    // ==================================================
    // FINALIZAR Y VINCULAR CON SALIDA
    // ==================================================

    public function finalizarConSalida(
        int $resurtidoId,
        int $salidaId,
        int $encargadoId,
        array $cantidadesSurtidas
    ): array {
        if (
            $resurtidoId <= 0
            || $salidaId <= 0
            || $encargadoId <= 0
        ) {
            throw new InvalidArgumentException(
                'Los datos de la salida no son válidos.'
            );
        }

        $this->db->beginTransaction();

        try {
            $sqlBloqueo = "
                SELECT
                    id,
                    estado,
                    salida_id
                FROM resurtidos
                WHERE id = :id
                LIMIT 1
                FOR UPDATE
            ";

            $stmtBloqueo = $this->db->prepare(
                $sqlBloqueo
            );

            $stmtBloqueo->execute([
                ':id' => $resurtidoId
            ]);

            $resurtido = $stmtBloqueo->fetch();

            if (!$resurtido) {
                throw new RuntimeException(
                    'No se encontró el resurtido.'
                );
            }

            if ($resurtido['estado'] === 'CANCELADO') {
                throw new RuntimeException(
                    'El resurtido fue cancelado.'
                );
            }

            if ($resurtido['estado'] === 'SURTIDO') {
                throw new RuntimeException(
                    'El resurtido ya fue surtido.'
                );
            }

            if (!empty($resurtido['salida_id'])) {
                throw new RuntimeException(
                    'El resurtido ya está vinculado con otra salida.'
                );
            }

            $sqlActualizarDetalle = "
                UPDATE resurtido_detalles
                SET cantidad_surtida = :cantidad_surtida
                WHERE
                    resurtido_id = :resurtido_id
                    AND producto_id = :producto_id
            ";

            $stmtActualizarDetalle = $this->db->prepare(
                $sqlActualizarDetalle
            );

            foreach ($cantidadesSurtidas as $producto) {
                $productoId = (int) (
                    $producto['producto_id'] ?? 0
                );

                $cantidadSurtida = (float) (
                    $producto['cantidad_surtida']
                    ?? $producto['cantidad']
                    ?? 0
                );

                if (
                    $productoId <= 0
                    || $cantidadSurtida < 0
                ) {
                    throw new InvalidArgumentException(
                        'Una cantidad surtida no es válida.'
                    );
                }

                $stmtActualizarDetalle->execute([
                    ':cantidad_surtida' => $cantidadSurtida,
                    ':resurtido_id' => $resurtidoId,
                    ':producto_id' => $productoId
                ]);

                if ($stmtActualizarDetalle->rowCount() === 0) {
                    throw new RuntimeException(
                        'Uno de los productos no pertenece al resurtido.'
                    );
                }
            }

            $estadoFinal = $this->calcularEstadoFinal(
                $resurtidoId
            );

            $sqlFinalizar = "
                UPDATE resurtidos
                SET
                    estado = :estado,
                    encargado_id = :encargado_id,
                    salida_id = :salida_id,
                    fecha_atencion = NOW(),
                    actualizado_en = NOW()
                WHERE id = :id
            ";

            $stmtFinalizar = $this->db->prepare(
                $sqlFinalizar
            );

            $stmtFinalizar->execute([
                ':estado' => $estadoFinal,
                ':encargado_id' => $encargadoId,
                ':salida_id' => $salidaId,
                ':id' => $resurtidoId
            ]);

            $this->db->commit();

            return [
                'id' => $resurtidoId,
                'salida_id' => $salidaId,
                'estado' => $estadoFinal
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    // ==================================================
    // VERIFICAR SI UN FOLIO EXISTE
    // ==================================================

    public function existeFolio(
        string $folio
    ): bool {
        $folio = trim($folio);

        if ($folio === '') {
            return false;
        }

        $sql = "
            SELECT COUNT(*)
            FROM resurtidos
            WHERE folio = :folio
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':folio' => $folio
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    // ==================================================
    // GENERAR FOLIO
    // ==================================================

    private function generarFolio(
        int $resurtidoId,
        int $almacenId
    ): string {
        return sprintf(
            'RES-%d-%06d',
            $almacenId,
            $resurtidoId
        );
    }

    // ==================================================
    // NORMALIZAR PRODUCTOS
    // ==================================================

    private function normalizarProductos(
        array $productos
    ): array {
        $productosNormalizados = [];

        foreach ($productos as $producto) {
            if (!is_array($producto)) {
                throw new InvalidArgumentException(
                    'La información de un producto no es válida.'
                );
            }

            $productoId = (int) (
                $producto['producto_id']
                ?? $producto['id']
                ?? 0
            );

            $cantidad = (float) (
                $producto['cantidad']
                ?? $producto['cantidad_solicitada']
                ?? 0
            );

            if ($productoId <= 0) {
                throw new InvalidArgumentException(
                    'Uno de los productos no es válido.'
                );
            }

            if ($cantidad <= 0) {
                throw new InvalidArgumentException(
                    'Las cantidades deben ser mayores que cero.'
                );
            }

            if ($cantidad > 999999999) {
                throw new InvalidArgumentException(
                    'Una de las cantidades es demasiado grande.'
                );
            }

            if (isset($productosNormalizados[$productoId])) {
                $productosNormalizados[$productoId]['cantidad']
                    += $cantidad;

                continue;
            }

            $productosNormalizados[$productoId] = [
                'producto_id' => $productoId,
                'cantidad' => $cantidad
            ];
        }

        if (empty($productosNormalizados)) {
            throw new InvalidArgumentException(
                'Debe agregar por lo menos un producto.'
            );
        }

        return array_values(
            $productosNormalizados
        );
    }

    // ==================================================
    // OBTENER REGISTRO BÁSICO
    // ==================================================

    private function obtenerRegistroBasico(
        int $resurtidoId
    ): ?array {
        $sql = "
            SELECT
                id,
                folio,
                estado,
                solicitante_id,
                almacen_id,
                encargado_id,
                salida_id,
                fecha_atencion
            FROM resurtidos
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':id' => $resurtidoId
        ]);

        $registro = $stmt->fetch();

        return $registro ?: null;
    }

    // ==================================================
    // CALCULAR ESTADO FINAL
    // ==================================================

    private function calcularEstadoFinal(
        int $resurtidoId
    ): string {
        $sql = "
            SELECT
                COUNT(*) AS total_productos,

                SUM(
                    CASE
                        WHEN cantidad_surtida
                            >= cantidad_solicitada
                        THEN 1
                        ELSE 0
                    END
                ) AS productos_completos,

                SUM(
                    CASE
                        WHEN cantidad_surtida > 0
                        THEN 1
                        ELSE 0
                    END
                ) AS productos_con_surtido

            FROM resurtido_detalles

            WHERE resurtido_id = :resurtido_id
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':resurtido_id' => $resurtidoId
        ]);

        $resultado = $stmt->fetch();

        $total = (int) (
            $resultado['total_productos'] ?? 0
        );

        $completos = (int) (
            $resultado['productos_completos'] ?? 0
        );

        $conSurtido = (int) (
            $resultado['productos_con_surtido'] ?? 0
        );

        if ($total > 0 && $completos === $total) {
            return 'SURTIDO';
        }

        if ($conSurtido > 0) {
            return 'PARCIAL';
        }

        return 'EN_PROCESO';
    }

    // ==================================================
    // CONVERTIR TIPOS DE LISTADO
    // ==================================================

    private function convertirLista(
        array $resurtidos
    ): array {
        foreach ($resurtidos as &$resurtido) {
            $resurtido['id'] = (int) (
                $resurtido['id'] ?? 0
            );

            if (isset($resurtido['solicitante_id'])) {
                $resurtido['solicitante_id'] = (int) (
                    $resurtido['solicitante_id']
                );
            }

            if (isset($resurtido['almacen_id'])) {
                $resurtido['almacen_id'] = (int) (
                    $resurtido['almacen_id']
                );
            }

            if (isset($resurtido['encargado_id'])) {
                $resurtido['encargado_id'] =
                    $resurtido['encargado_id'] !== null
                        ? (int) $resurtido['encargado_id']
                        : null;
            }

            if (isset($resurtido['salida_id'])) {
                $resurtido['salida_id'] =
                    $resurtido['salida_id'] !== null
                        ? (int) $resurtido['salida_id']
                        : null;
            }

            if (isset($resurtido['total_productos'])) {
                $resurtido['total_productos'] = (int) (
                    $resurtido['total_productos']
                );
            }

            if (isset($resurtido['total_cantidad'])) {
                $resurtido['total_cantidad'] = (float) (
                    $resurtido['total_cantidad']
                );
            }
        }

        unset($resurtido);

        return $resurtidos;
    }

    // ==================================================
    // NORMALIZAR LÍMITE
    // ==================================================

    private function normalizarLimite(
        ?int $limite
    ): int {
        $limite = $limite ?? 100;

        if ($limite < 1) {
            return 1;
        }

        if ($limite > 500) {
            return 500;
        }

        return $limite;
    }
}