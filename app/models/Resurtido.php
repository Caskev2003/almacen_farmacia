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

    private const TIPOS_SOLICITUD = [
        'RESURTIDO',
        'TICKET'
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
        string $ultimosDigitos,
        int $almacenId
    ): array {
        $ultimosDigitos = trim($ultimosDigitos);

        if (!preg_match('/^\d{4}$/', $ultimosDigitos)) {
            throw new InvalidArgumentException(
                'Debe ingresar exactamente los últimos 4 dígitos.'
            );
        }

        if ($almacenId <= 0) {
            throw new InvalidArgumentException(
                'El almacén que surtirá la solicitud no es válido.'
            );
        }

        $sucursales = $this->obtenerSucursalesAlmacen(
            $almacenId
        );

        if (empty($sucursales)) {
            throw new RuntimeException(
                'No fue posible identificar las existencias del almacén.'
            );
        }

        $params = [
            ':codigo' => $ultimosDigitos,
            ':codigo_barras' => $ultimosDigitos,
            ':almacen_reservado_busqueda' =>
                $almacenId,
            ':almacen_devolucion_busqueda' =>
                $almacenId
        ];

        $placeholdersSucursales = [];

        foreach ($sucursales as $indice => $sucursal) {
            $placeholder = ':sucursal_busqueda_'
                . $indice;

            $placeholdersSucursales[] = $placeholder;
            $params[$placeholder] = $sucursal;
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

                GREATEST(
                    COALESCE(
                        stock.existencia_bodega,
                        0
                    )
                    - COALESCE(
                        reservas.cantidad_reservada,
                        0
                    )
                    - COALESCE(
                        devoluciones_activas.cantidad_devolucion,
                        0
                    ),
                    0
                ) AS existencia_disponible,

                COALESCE(
                    stock.existencia_bodega,
                    0
                ) AS existencia_bodega,

                COALESCE(
                    reservas.cantidad_reservada,
                    0
                ) AS cantidad_reservada,

                COALESCE(
                    devoluciones_activas.cantidad_devolucion,
                    0
                ) AS cantidad_devolucion

            FROM productos AS p

            LEFT JOIN (
                SELECT
                    pe.producto_id,
                    SUM(pe.existencia) AS existencia_bodega
                FROM producto_existencias AS pe
                WHERE
                    UPPER(
                        TRIM(
                            COALESCE(pe.sucursal, '')
                        )
                    ) COLLATE utf8mb4_general_ci
                    IN (
                        " . implode(
                            ', ',
                            $placeholdersSucursales
                        ) . "
                    )
                    AND COALESCE(pe.existencia, 0) > 0
                    AND pe.ubicacion IS NOT NULL
                    AND TRIM(pe.ubicacion) <> ''
                    AND UPPER(
                        TRIM(pe.ubicacion)
                    ) COLLATE utf8mb4_general_ci
                    NOT IN (
                        'SIN UBICACION',
                        'SIN UBICACIÓN'
                    )
                GROUP BY pe.producto_id
            ) AS stock
                ON stock.producto_id = p.id

            LEFT JOIN (
                SELECT
                    rd.producto_id,
                    SUM(
                        GREATEST(
                            COALESCE(
                                rd.cantidad_solicitada,
                                0
                            )
                            - COALESCE(
                                rd.cantidad_surtida,
                                0
                            ),
                            0
                        )
                    ) AS cantidad_reservada
                FROM resurtido_detalles AS rd
                INNER JOIN resurtidos AS r
                    ON r.id = rd.resurtido_id
                WHERE
                    r.almacen_id =
                        :almacen_reservado_busqueda
                    AND r.estado IN (
                        'PENDIENTE',
                        'EN_PROCESO',
                        'PARCIAL'
                    )
                GROUP BY rd.producto_id
            ) AS reservas
                ON reservas.producto_id = p.id

            LEFT JOIN (
                SELECT
                    d.producto_id,
                    SUM(d.piezas) AS cantidad_devolucion
                FROM devoluciones AS d
                WHERE
                    d.almacen_id =
                        :almacen_devolucion_busqueda
                    AND d.estatus IN (
                        'PENDIENTE',
                        'EN_PROCESO'
                    )
                GROUP BY d.producto_id
            ) AS devoluciones_activas
                ON devoluciones_activas.producto_id = p.id

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

        $stmt->execute($params);

        $productos = $stmt->fetchAll();

        foreach ($productos as &$producto) {
            $producto['id'] = (int) $producto['id'];

            $producto['existencia_disponible'] = (int) (
                $producto['existencia_disponible'] ?? 0
            );

            $producto['existencia_bodega'] = (int) (
                $producto['existencia_bodega'] ?? 0
            );

            $producto['cantidad_reservada'] = (int) (
                $producto['cantidad_reservada'] ?? 0
            );

            $producto['cantidad_devolucion'] = (int) (
                $producto['cantidad_devolucion'] ?? 0
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

        $verificadorId = isset($datos['verificador_id'])
            && $datos['verificador_id'] !== null
                ? (int) $datos['verificador_id']
                : null;

        $verificadorNombre = trim(
            (string) (
                $datos['verificador_nombre'] ?? ''
            )
        );

        $observaciones = trim(
            (string) (
                $datos['observaciones'] ?? ''
            )
        );

        $tipoSolicitud = $this->normalizarTipoSolicitud(
            (string) ($datos['tipo_solicitud'] ?? 'RESURTIDO')
        );

        $folioDocumento = strtoupper(
            trim((string) ($datos['folio_documento'] ?? ''))
        );

        if ($tipoSolicitud === 'TICKET' && $folioDocumento === '') {
            throw new InvalidArgumentException(
                'El folio del ticket es obligatorio.'
            );
        }

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
            /*
             * Serializa las solicitudes del mismo almacén.
             * Así dos tabletas no pueden reservar al mismo
             * tiempo las mismas unidades disponibles.
             */
            $this->bloquearAlmacenParaResurtido(
                $almacenId
            );

            /*
             * La existencia se consulta de nuevo al guardar.
             * El valor enviado por JavaScript nunca se considera
             * una fuente confiable.
             */
            $existenciasDisponibles =
                $this->obtenerExistenciasDisponibles(
                    $productos,
                    $almacenId
                );

            foreach ($productos as $producto) {
                $productoId = (int) (
                    $producto['producto_id']
                );

                $informacion =
                    $existenciasDisponibles[$productoId]
                    ?? null;

                if (!$informacion) {
                    throw new InvalidArgumentException(
                        'Uno de los productos seleccionados no existe o está inactivo.'
                    );
                }

                $cantidadSolicitada = (float) (
                    $producto['cantidad']
                );

                $existenciaDisponible = (float) (
                    $informacion['existencia_disponible']
                    ?? 0
                );

                if (
                    $cantidadSolicitada
                    > $existenciaDisponible
                ) {
                    $codigo = trim(
                        (string) (
                            $informacion['codigo']
                            ?? ''
                        )
                    );

                    $descripcion = trim(
                        (string) (
                            $informacion['descripcion']
                            ?? 'Producto'
                        )
                    );

                    throw new InvalidArgumentException(
                        'La cantidad solicitada de '
                        . ($codigo !== ''
                            ? $codigo . ' - '
                            : '')
                        . $descripcion
                        . ' supera la existencia disponible en bodega. '
                        . 'Solicitado: '
                        . $this->formatearCantidad(
                            $cantidadSolicitada
                        )
                        . '. Disponible: '
                        . $this->formatearCantidad(
                            $existenciaDisponible
                        )
                        . '.'
                    );
                }
            }

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
                    verificador_id,
                    verificador_nombre,
                    almacen_id,
                    tipo_solicitud,
                    folio_documento,
                    observaciones,
                    estado,
                    creado_en,
                    actualizado_en
                ) VALUES (
                    :folio,
                    NOW(),
                    :solicitante_id,
                    :verificador_id,
                    :verificador_nombre,
                    :almacen_id,
                    :tipo_solicitud,
                    :folio_documento,
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
                ':verificador_id' => (
                    $verificadorId !== null
                    && $verificadorId > 0
                        ? $verificadorId
                        : null
                ),
                ':verificador_nombre' => (
                    $verificadorNombre !== ''
                        ? $verificadorNombre
                        : null
                ),
                ':almacen_id' => $almacenId,
                ':tipo_solicitud' => $tipoSolicitud,
                ':folio_documento' => (
                    $folioDocumento !== ''
                        ? $folioDocumento
                        : null
                ),
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
                $almacenId,
                $tipoSolicitud
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
                'tipo_solicitud' => $tipoSolicitud,
                'folio_documento' => (
                    $folioDocumento !== ''
                        ? $folioDocumento
                        : null
                ),
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
                r.verificador_id,
                r.verificador_nombre,
                r.almacen_id,
                r.tipo_solicitud,
                r.folio_documento,
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
                r.verificador_id,
                r.verificador_nombre,
                r.almacen_id,
                r.tipo_solicitud,
                r.folio_documento,
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

        $resurtido['verificador_id'] =
            $resurtido['verificador_id'] !== null
                ? (int) $resurtido['verificador_id']
                : null;

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
        ?int $limite = 100,
        string $tipoSolicitud = 'RESURTIDO'
    ): array {
        if ($solicitanteId <= 0) {
            return [];
        }

        $limite = $this->normalizarLimite(
            $limite
        );

        $tipoSolicitud = $this->normalizarTipoSolicitud(
            $tipoSolicitud
        );

        $sql = "
            SELECT
                r.id,
                r.folio,
                r.tipo_solicitud,
                r.folio_documento,
                r.fecha_solicitud AS fecha,
                r.verificador_id,
                r.verificador_nombre,
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
                ) AS total_cantidad,

                COALESCE(
                    SUM(rd.cantidad_surtida),
                    0
                ) AS total_cantidad_surtida

            FROM resurtidos AS r

            LEFT JOIN almacenes AS a
                ON a.id = r.almacen_id

            LEFT JOIN usuarios AS ue
                ON ue.id = r.encargado_id

            LEFT JOIN resurtido_detalles AS rd
                ON rd.resurtido_id = r.id

            WHERE r.solicitante_id = :solicitante_id
            AND r.tipo_solicitud = :tipo_solicitud

            GROUP BY
                r.id,
                r.folio,
                r.tipo_solicitud,
                r.folio_documento,
                r.fecha_solicitud,
                r.verificador_id,
                r.verificador_nombre,
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
            ':solicitante_id' => $solicitanteId,
            ':tipo_solicitud' => $tipoSolicitud
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
        ?int $limite = 150,
        string $tipoSolicitud = 'RESURTIDO'
    ): array {
        $limite = $this->normalizarLimite(
            $limite
        );

        $condiciones = [
            'r.tipo_solicitud = :tipo_solicitud'
        ];
        $parametros = [];

        $tipoSolicitud = $this->normalizarTipoSolicitud(
            $tipoSolicitud
        );

        $parametros[':tipo_solicitud'] = $tipoSolicitud;

        if ($almacenId !== null && $almacenId > 0) {
            $condiciones[] =
                'r.almacen_id = :almacen_id';

            $parametros[':almacen_id'] = $almacenId;
        }

        $condicion = 'WHERE ' . implode(
            ' AND ',
            $condiciones
        );

        $sql = "
            SELECT
                r.id,
                r.folio,
                r.tipo_solicitud,
                r.folio_documento,
                r.fecha_solicitud AS fecha,
                r.solicitante_id,
                r.verificador_id,
                r.verificador_nombre,
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
                ) AS total_cantidad,

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

            {$condicion}

            GROUP BY
                r.id,
                r.folio,
                r.tipo_solicitud,
                r.folio_documento,
                r.fecha_solicitud,
                r.solicitante_id,
                r.verificador_id,
                r.verificador_nombre,
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
        ?int $almacenId = null,
        string $tipoSolicitud = 'RESURTIDO'
    ): array {
        $condicionAlmacen = '';
        $tipoSolicitud = $this->normalizarTipoSolicitud(
            $tipoSolicitud
        );

        $parametros = [
            ':tipo_solicitud' => $tipoSolicitud
        ];

        if ($almacenId !== null && $almacenId > 0) {
            $condicionAlmacen =
                'AND r.almacen_id = :almacen_id';

            $parametros[':almacen_id'] = $almacenId;
        }

        $sql = "
            SELECT
                r.id,
                r.folio,
                r.tipo_solicitud,
                r.folio_documento,
                r.fecha_solicitud AS fecha,
                r.solicitante_id,
                r.verificador_id,
                r.verificador_nombre,
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
            AND r.tipo_solicitud = :tipo_solicitud

            {$condicionAlmacen}

            GROUP BY
                r.id,
                r.folio,
                r.tipo_solicitud,
                r.folio_documento,
                r.fecha_solicitud,
                r.solicitante_id,
                r.verificador_id,
                r.verificador_nombre,
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
        ?int $almacenId = null,
        string $tipoSolicitud = 'RESURTIDO'
    ): int {
        $condicionAlmacen = '';
        $tipoSolicitud = $this->normalizarTipoSolicitud(
            $tipoSolicitud
        );

        $parametros = [
            ':tipo_solicitud' => $tipoSolicitud
        ];

        if ($almacenId !== null && $almacenId > 0) {
            $condicionAlmacen =
                'AND almacen_id = :almacen_id';

            $parametros[':almacen_id'] = $almacenId;
        }

        $sql = "
            SELECT COUNT(*)
            FROM resurtidos
            WHERE estado = 'PENDIENTE'
            AND tipo_solicitud = :tipo_solicitud
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

            if (
                !empty($resurtido['salida_id'])
                && $resurtido['estado'] !== 'PARCIAL'
            ) {
                throw new RuntimeException(
                    'El resurtido ya está vinculado con otra salida.'
                );
            }

            $sqlDetallesBloqueo = "
                SELECT
                    producto_id,
                    cantidad_solicitada,
                    cantidad_surtida
                FROM resurtido_detalles
                WHERE resurtido_id = :resurtido_id
                FOR UPDATE
            ";

            $stmtDetallesBloqueo = $this->db->prepare(
                $sqlDetallesBloqueo
            );

            $stmtDetallesBloqueo->execute([
                ':resurtido_id' => $resurtidoId
            ]);

            $detallesBloqueados = [];

            foreach (
                $stmtDetallesBloqueo->fetchAll() as $detalleBloqueado
            ) {
                $productoIdDetalle = (int) (
                    $detalleBloqueado['producto_id'] ?? 0
                );

                $detallesBloqueados[$productoIdDetalle] = [
                    'solicitada' => (float) (
                        $detalleBloqueado['cantidad_solicitada'] ?? 0
                    ),
                    'surtida' => (float) (
                        $detalleBloqueado['cantidad_surtida'] ?? 0
                    )
                ];
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

                if (!isset($detallesBloqueados[$productoId])) {
                    throw new RuntimeException(
                        'Uno de los productos no pertenece al resurtido.'
                    );
                }

                $cantidadSolicitada = (float) (
                    $detallesBloqueados[$productoId]['solicitada']
                );

                $cantidadAcumulada = (float) (
                    $detallesBloqueados[$productoId]['surtida']
                );

                $cantidadPendiente = max(
                    0,
                    $cantidadSolicitada - $cantidadAcumulada
                );

                if ($cantidadSurtida > $cantidadPendiente) {
                    throw new RuntimeException(
                        'La cantidad surtida supera la cantidad pendiente '
                        . 'del producto.'
                    );
                }

                $nuevaCantidadAcumulada = min(
                    $cantidadSolicitada,
                    $cantidadAcumulada + $cantidadSurtida
                );

                $stmtActualizarDetalle->execute([
                    ':cantidad_surtida' =>
                        $nuevaCantidadAcumulada,
                    ':resurtido_id' => $resurtidoId,
                    ':producto_id' => $productoId
                ]);

                $detallesBloqueados[$productoId]['surtida'] =
                    $nuevaCantidadAcumulada;
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

    public function existeFolioDocumentoTicket(
        string $folioDocumento,
        int $almacenId
    ): bool {
        $folioDocumento = strtoupper(
            trim($folioDocumento)
        );

        if ($folioDocumento === '' || $almacenId <= 0) {
            return false;
        }

        $sql = "
            SELECT COUNT(*)
            FROM resurtidos
            WHERE tipo_solicitud = 'TICKET'
            AND UPPER(TRIM(folio_documento)) = :folio_documento
            AND almacen_id = :almacen_id
            AND estado <> 'CANCELADO'
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':folio_documento' => $folioDocumento,
            ':almacen_id' => $almacenId
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    // ==================================================
    // GENERAR FOLIO
    // ==================================================

    private function generarFolio(
        int $almacenId,
        string $tipoSolicitud = 'RESURTIDO'
    ): string {
        if ($almacenId <= 0) {
            throw new InvalidArgumentException(
                'El almacén del folio no es válido.'
            );
        }

        $tipoSolicitud = $this->normalizarTipoSolicitud(
            $tipoSolicitud
        );

        $prefijo = $tipoSolicitud === 'TICKET'
            ? 'TKT'
            : 'RES';

        /*
         * Esta fila se bloquea hasta que termina la transacción.
         * El contador de RESURTIDO y el contador de TICKET son
         * independientes, aun cuando ambos módulos utilizan la
         * tabla resurtidos.
         */
        $sqlControl = "
            SELECT ultimo_numero
            FROM control_folios_solicitudes
            WHERE
                almacen_id = :almacen_id
                AND tipo_solicitud = :tipo_solicitud
            LIMIT 1
            FOR UPDATE
        ";

        $stmtControl = $this->db->prepare($sqlControl);
        $stmtControl->execute([
            ':almacen_id' => $almacenId,
            ':tipo_solicitud' => $tipoSolicitud
        ]);

        $ultimoNumero = $stmtControl->fetchColumn();

        if ($ultimoNumero === false) {
            /*
             * Permite comenzar correctamente en almacenes nuevos y
             * también recupera el consecutivo si todavía no existe
             * la fila de control para alguno de los dos módulos.
             */
            $sqlUltimoFolio = "
                SELECT COALESCE(
                    MAX(
                        CASE
                            WHEN folio REGEXP :expresion_folio
                            THEN CAST(
                                SUBSTRING_INDEX(folio, '-', -1)
                                AS UNSIGNED
                            )
                            ELSE 0
                        END
                    ),
                    0
                )
                FROM resurtidos
                WHERE
                    almacen_id = :almacen_id
                    AND tipo_solicitud = :tipo_solicitud
            ";

            $stmtUltimoFolio = $this->db->prepare(
                $sqlUltimoFolio
            );

            $stmtUltimoFolio->execute([
                ':expresion_folio' => '^'
                    . $prefijo
                    . '-[0-9]+-[0-9]+$',
                ':almacen_id' => $almacenId,
                ':tipo_solicitud' => $tipoSolicitud
            ]);

            $ultimoNumero = (int) (
                $stmtUltimoFolio->fetchColumn() ?: 0
            );

            $sqlCrearControl = "
                INSERT INTO control_folios_solicitudes (
                    almacen_id,
                    tipo_solicitud,
                    ultimo_numero,
                    creado_en,
                    actualizado_en
                ) VALUES (
                    :almacen_id,
                    :tipo_solicitud,
                    :ultimo_numero,
                    NOW(),
                    NOW()
                )
            ";

            $stmtCrearControl = $this->db->prepare(
                $sqlCrearControl
            );

            $stmtCrearControl->execute([
                ':almacen_id' => $almacenId,
                ':tipo_solicitud' => $tipoSolicitud,
                ':ultimo_numero' => $ultimoNumero
            ]);
        }

        $nuevoNumero = (int) $ultimoNumero + 1;

        $sqlActualizarControl = "
            UPDATE control_folios_solicitudes
            SET
                ultimo_numero = :ultimo_numero,
                actualizado_en = NOW()
            WHERE
                almacen_id = :almacen_id
                AND tipo_solicitud = :tipo_solicitud
        ";

        $stmtActualizarControl = $this->db->prepare(
            $sqlActualizarControl
        );

        $stmtActualizarControl->execute([
            ':ultimo_numero' => $nuevoNumero,
            ':almacen_id' => $almacenId,
            ':tipo_solicitud' => $tipoSolicitud
        ]);

        if ($stmtActualizarControl->rowCount() !== 1) {
            throw new RuntimeException(
                'No fue posible actualizar el control de folios.'
            );
        }

        return sprintf(
            '%s-%d-%06d',
            $prefijo,
            $almacenId,
            $nuevoNumero
        );
    }

    private function normalizarTipoSolicitud(
        string $tipoSolicitud
    ): string {
        $tipoSolicitud = strtoupper(
            trim($tipoSolicitud)
        );

        if (
            !in_array(
                $tipoSolicitud,
                self::TIPOS_SOLICITUD,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'El tipo de solicitud no es válido.'
            );
        }

        return $tipoSolicitud;
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
    // EXISTENCIA DISPONIBLE DEL ALMACÉN
    // ==================================================

    private function obtenerExistenciasDisponibles(
        array $productos,
        int $almacenId
    ): array {
        $sucursales = $this->obtenerSucursalesAlmacen(
            $almacenId
        );

        if (empty($sucursales)) {
            throw new RuntimeException(
                'No fue posible identificar las existencias del almacén.'
            );
        }

        $params = [
            ':almacen_reservado_stock' =>
                $almacenId,
            ':almacen_devolucion_stock' =>
                $almacenId
        ];

        $placeholdersProductos = [];
        $placeholdersSucursales = [];

        foreach ($productos as $indice => $producto) {
            $placeholder = ':producto_stock_'
                . $indice;

            $placeholdersProductos[] = $placeholder;
            $params[$placeholder] = (int) (
                $producto['producto_id']
            );
        }

        foreach ($sucursales as $indice => $sucursal) {
            $placeholder = ':sucursal_stock_'
                . $indice;

            $placeholdersSucursales[] = $placeholder;
            $params[$placeholder] = $sucursal;
        }

        $sql = "
            SELECT
                p.id,
                p.codigo,
                p.descripcion,

                GREATEST(
                    COALESCE(
                        SUM(pe.existencia),
                        0
                    )
                    - COALESCE(
                        reservas.cantidad_reservada,
                        0
                    )
                    - COALESCE(
                        devoluciones_activas.cantidad_devolucion,
                        0
                    ),
                    0
                ) AS existencia_disponible,

                COALESCE(
                    SUM(pe.existencia),
                    0
                ) AS existencia_bodega,

                COALESCE(
                    reservas.cantidad_reservada,
                    0
                ) AS cantidad_reservada,

                COALESCE(
                    devoluciones_activas.cantidad_devolucion,
                    0
                ) AS cantidad_devolucion

            FROM productos AS p

            LEFT JOIN producto_existencias AS pe
                ON pe.producto_id = p.id
                AND UPPER(
                    TRIM(
                        COALESCE(pe.sucursal, '')
                    )
                ) COLLATE utf8mb4_general_ci
                IN (
                    " . implode(
                        ', ',
                        $placeholdersSucursales
                    ) . "
                )
                AND COALESCE(pe.existencia, 0) > 0
                AND pe.ubicacion IS NOT NULL
                AND TRIM(pe.ubicacion) <> ''
                AND UPPER(
                    TRIM(pe.ubicacion)
                ) COLLATE utf8mb4_general_ci
                NOT IN (
                    'SIN UBICACION',
                    'SIN UBICACIÓN'
                )

            LEFT JOIN (
                SELECT
                    rd.producto_id,
                    SUM(
                        GREATEST(
                            COALESCE(
                                rd.cantidad_solicitada,
                                0
                            )
                            - COALESCE(
                                rd.cantidad_surtida,
                                0
                            ),
                            0
                        )
                    ) AS cantidad_reservada
                FROM resurtido_detalles AS rd
                INNER JOIN resurtidos AS r
                    ON r.id = rd.resurtido_id
                WHERE
                    r.almacen_id =
                        :almacen_reservado_stock
                    AND r.estado IN (
                        'PENDIENTE',
                        'EN_PROCESO',
                        'PARCIAL'
                    )
                GROUP BY rd.producto_id
            ) AS reservas
                ON reservas.producto_id = p.id

            LEFT JOIN (
                SELECT
                    d.producto_id,
                    SUM(d.piezas) AS cantidad_devolucion
                FROM devoluciones AS d
                WHERE
                    d.almacen_id =
                        :almacen_devolucion_stock
                    AND d.estatus IN (
                        'PENDIENTE',
                        'EN_PROCESO'
                    )
                GROUP BY d.producto_id
            ) AS devoluciones_activas
                ON devoluciones_activas.producto_id = p.id

            WHERE
                p.estado = 1
                AND p.id IN (
                    " . implode(
                        ', ',
                        $placeholdersProductos
                    ) . "
                )
            GROUP BY
                p.id,
                p.codigo,
                p.descripcion,
                reservas.cantidad_reservada,
                devoluciones_activas.cantidad_devolucion
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $existencias = [];

        foreach ($stmt->fetchAll() as $producto) {
            $productoId = (int) (
                $producto['id'] ?? 0
            );

            if ($productoId <= 0) {
                continue;
            }

            $producto['id'] = $productoId;

            $producto['existencia_disponible'] =
                (float) (
                    $producto['existencia_disponible']
                    ?? 0
                );

            $producto['existencia_bodega'] =
                (float) (
                    $producto['existencia_bodega']
                    ?? 0
                );

            $producto['cantidad_reservada'] =
                (float) (
                    $producto['cantidad_reservada']
                    ?? 0
                );

            $producto['cantidad_devolucion'] =
                (float) (
                    $producto['cantidad_devolucion']
                    ?? 0
                );

            $existencias[$productoId] = $producto;
        }

        return $existencias;
    }

    // ==================================================
    // BLOQUEAR ALMACÉN DURANTE LA RESERVA
    // ==================================================

    private function bloquearAlmacenParaResurtido(
        int $almacenId
    ): void {
        $sql = "
            SELECT id
            FROM almacenes
            WHERE
                id = :almacen_id
                AND estado = 1
            LIMIT 1
            FOR UPDATE
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':almacen_id' => $almacenId
        ]);

        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException(
                'El almacén que surtirá la solicitud no existe o está inactivo.'
            );
        }
    }

    // ==================================================
    // SUCURSALES EQUIVALENTES DE UN ALMACÉN
    // ==================================================

    private function obtenerSucursalesAlmacen(
        int $almacenId
    ): array {
        if ($almacenId <= 0) {
            return [];
        }

        $sql = "
            SELECT nombre
            FROM almacenes
            WHERE
                id = :almacen_id
                AND estado = 1
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':almacen_id' => $almacenId
        ]);

        $nombre = strtoupper(
            trim(
                (string) (
                    $stmt->fetchColumn() ?: ''
                )
            )
        );

        if ($nombre === '') {
            return [];
        }

        if (str_contains($nombre, 'HIDALGO')) {
            return [
                'CIUDAD HIDALGO',
                'CD HIDALGO'
            ];
        }

        if (str_contains($nombre, 'TUXTLA')) {
            return [
                'TUXTLA',
                'TUXTLA GUTIERREZ',
                'TUXTLA GUTIÉRREZ'
            ];
        }

        return [$nombre];
    }

    // ==================================================
    // FORMATEAR CANTIDAD PARA MENSAJES
    // ==================================================

    private function formatearCantidad(
        float $cantidad
    ): string {
        if (floor($cantidad) === $cantidad) {
            return (string) (int) $cantidad;
        }

        return rtrim(
            rtrim(
                number_format(
                    $cantidad,
                    3,
                    '.',
                    ''
                ),
                '0'
            ),
            '.'
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
                tipo_solicitud,
                folio_documento,
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

            if (array_key_exists('verificador_id', $resurtido)) {
                $resurtido['verificador_id'] =
                    $resurtido['verificador_id'] !== null
                        ? (int) $resurtido['verificador_id']
                        : null;
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

            if (isset($resurtido['total_cantidad_surtida'])) {
                $resurtido['total_cantidad_surtida'] = (float) (
                    $resurtido['total_cantidad_surtida']
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
