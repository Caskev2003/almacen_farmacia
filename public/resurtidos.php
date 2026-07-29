<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/controllers/ResurtidoController.php';

requireLogin();

date_default_timezone_set('America/Mexico_City');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$user = currentUser();

$rol = strtoupper(
    trim($user['rol'] ?? '')
);

$usuarioId = (int) (
    $user['id'] ?? 0
);

$almacenId = (int) (
    $user['almacen_id'] ?? 0
);

$tipoSolicitudModulo = strtoupper(
    trim((string) ($tipoSolicitudModulo ?? 'RESURTIDO'))
);

if (!in_array($tipoSolicitudModulo, ['RESURTIDO', 'TICKET'], true)) {
    $tipoSolicitudModulo = 'RESURTIDO';
}

$esModuloTicket = $tipoSolicitudModulo === 'TICKET';
$endpointModulo = $esModuloTicket
    ? 'tickets.php'
    : 'resurtidos.php';
$moduloAuditoria = $esModuloTicket
    ? 'Tickets'
    : 'Resurtidos';
$nombreSolicitud = $esModuloTicket
    ? 'ticket'
    : 'resurtido';
$nombreSolicitudes = $esModuloTicket
    ? 'tickets'
    : 'resurtidos';
$puedeCrearSolicitud = $esModuloTicket
    ? $rol === 'GERENTE'
    : in_array($rol, ['GERENTE', 'ADMINISTRADOR'], true);

$rolesPermitidos = [
    'ADMINISTRADOR',
    'GERENTE',
    'ENCARGADO'
];

if (!in_array($rol, $rolesPermitidos, true)) {
    http_response_code(403);
    exit('Acceso denegado.');
}

if (
    $esModuloTicket
    && $rol !== 'ADMINISTRADOR'
    && !(
        $almacenId === 1
        && in_array($rol, ['GERENTE', 'ENCARGADO'], true)
    )
) {
    http_response_code(403);
    exit('El módulo Tickets es exclusivo de Ciudad Hidalgo.');
}

$csrfSessionKey = $esModuloTicket
    ? 'csrf_tickets'
    : 'csrf_resurtidos';

if (empty($_SESSION[$csrfSessionKey])) {
    $_SESSION[$csrfSessionKey] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION[$csrfSessionKey];

$controller = new ResurtidoController();

$action = trim(
    (string) ($_GET['action'] ?? '')
);

// ======================================================
// RESPONDER EN FORMATO JSON
// ======================================================

function responderJson(
    bool $ok,
    string $mensaje = '',
    array $datos = [],
    int $codigoHttp = 200
): void {
    http_response_code($codigoHttp);

    header(
        'Content-Type: application/json; charset=utf-8'
    );
    header(
        'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
    );

    echo json_encode(
        [
            'ok' => $ok,
            'mensaje' => $mensaje,
            'datos' => $datos
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}

// ======================================================
// LEER JSON ENVIADO POR JAVASCRIPT
// ======================================================

function leerJson(): array
{
    $contenido = file_get_contents('php://input');

    $datos = json_decode(
        $contenido ?: '',
        true
    );

    if (!is_array($datos)) {
        responderJson(
            false,
            'Los datos enviados no son válidos.',
            [],
            422
        );
    }

    return $datos;
}

// ======================================================
// VALIDAR TOKEN DE SEGURIDAD
// ======================================================

function validarTokenResurtidos(array $datos): void
{
    global $csrfSessionKey;

    $tokenRecibido = (string) (
        $datos['csrf_token'] ?? ''
    );

    $tokenSesion = (string) (
        $_SESSION[$csrfSessionKey] ?? ''
    );

    if (
        $tokenRecibido === ''
        || $tokenSesion === ''
        || !hash_equals($tokenSesion, $tokenRecibido)
    ) {
        responderJson(
            false,
            'La sesión de seguridad venció. Recargue la página.',
            [],
            419
        );
    }
}

function verificarTipoSolicitudModulo(
    array $resurtido,
    string $tipoSolicitudModulo
): void {
    $tipoRegistro = strtoupper(
        trim((string) ($resurtido['tipo_solicitud'] ?? 'RESURTIDO'))
    );

    if ($tipoRegistro !== $tipoSolicitudModulo) {
        responderJson(
            false,
            'La solicitud no pertenece a este módulo.',
            [],
            404
        );
    }
}

// ======================================================
// VERIFICAR ACCESO A UN RESURTIDO
// ======================================================

function verificarAccesoResurtido(
    array $resurtido,
    string $rol,
    int $usuarioId,
    int $almacenId
): void {
    if ($rol === 'ADMINISTRADOR') {
        return;
    }

    if (
        $rol === 'GERENTE'
        && (int) $resurtido['solicitante_id'] !== $usuarioId
    ) {
        responderJson(
            false,
            'No tiene permiso para consultar esta solicitud.',
            [],
            403
        );
    }

    if (
        $rol === 'ENCARGADO'
        && (
            $almacenId <= 0
            || (int) $resurtido['almacen_id'] !== $almacenId
        )
    ) {
        responderJson(
            false,
            'Esta solicitud no pertenece a su almacén.',
            [],
            403
        );
    }
}

function obtenerSolicitudesVisibles(
    ResurtidoController $controller,
    string $rol,
    int $usuarioId,
    int $almacenId,
    string $tipoSolicitudModulo
): array {
    if ($rol === 'GERENTE') {
        return $controller->obtenerPorGerente(
            $usuarioId,
            100,
            $tipoSolicitudModulo
        );
    }

    if ($rol === 'ENCARGADO') {
        return $controller->obtenerTodos(
            $almacenId > 0 ? $almacenId : null,
            150,
            $tipoSolicitudModulo
        );
    }

    return $controller->obtenerTodos(
        null,
        150,
        $tipoSolicitudModulo
    );
}

function generarHuellaSolicitudes(
    array $solicitudes
): string {
    $json = json_encode(
        $solicitudes,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    return hash(
        'sha256',
        is_string($json) ? $json : ''
    );
}

// ======================================================
// ACCIONES DEL MISMO ARCHIVO
// ======================================================

if ($action !== '') {

    // --------------------------------------------------
    // BUSCAR PRODUCTO
    // --------------------------------------------------

    if ($action === 'buscar_producto') {
        if (
            !in_array(
                $rol,
                ['GERENTE', 'ADMINISTRADOR'],
                true
            )
        ) {
            responderJson(
                false,
                'No tiene permisos para buscar productos.',
                [],
                403
            );
        }

        $codigo = trim(
            (string) ($_GET['codigo'] ?? '')
        );

        if (!preg_match('/^\d{4}$/', $codigo)) {
            responderJson(
                false,
                'Ingrese exactamente los últimos 4 dígitos del código.',
                [],
                422
            );
        }

        try {
            $productos =
                $controller->buscarPorUltimosDigitos(
                    $codigo,
                    $almacenId
                );

            auditLog([
                'modulo' => $moduloAuditoria,
                'accion' => 'BUSQUEDA_PRODUCTO',
                'entidad' => 'producto',
                'descripcion' => 'Buscó productos cuyos códigos terminan en '
                    . $codigo . '; se encontraron '
                    . count($productos) . ' resultado(s).',
                'metadata' => [
                    'ultimos_cuatro_digitos' => $codigo,
                    'resultados' => count($productos),
                    'almacen_id' => $almacenId,
                ],
            ]);

            responderJson(
                true,
                'Búsqueda realizada correctamente.',
                [
                    'productos' => $productos
                ]
            );
        } catch (Throwable $e) {
            error_log(
                'Error al buscar producto para '
                . $nombreSolicitud . ': '
                . $e->getMessage()
            );

            responderJson(
                false,
                'No fue posible buscar el producto.',
                [],
                500
            );
        }
    }

    // --------------------------------------------------
    // GUARDAR RESURTIDO
    // --------------------------------------------------

    if ($action === 'guardar') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responderJson(
                false,
                'Método no permitido.',
                [],
                405
            );
        }

        if (!$puedeCrearSolicitud) {
            responderJson(
                false,
                'No tiene permisos para crear '
                . $nombreSolicitudes . '.',
                [],
                403
            );
        }

        $datos = leerJson();
        validarTokenResurtidos($datos);

        $productos = $datos['productos'] ?? [];

        $passwordGerente = trim(
            (string) (
                $datos['password_gerente'] ?? ''
            )
        );

        $observaciones = trim(
            (string) (
                $datos['observaciones'] ?? ''
            )
        );

        $folioDocumento = strtoupper(
            trim((string) ($datos['folio_documento'] ?? ''))
        );

        if ($esModuloTicket && $folioDocumento === '') {
            responderJson(
                false,
                'El folio del ticket es obligatorio.',
                [],
                422
            );
        }

        if (!is_array($productos) || empty($productos)) {
            responderJson(
                false,
                'Debe agregar por lo menos un producto.',
                [],
                422
            );
        }

        try {
            if (
                $rol === 'GERENTE'
                && !$controller->validarPasswordGerente(
                    $usuarioId,
                    $passwordGerente
                )
            ) {
                responderJson(
                    false,
                    'La contraseña del gerente es incorrecta.',
                    [],
                    403
                );
            }

            $resultado = $controller->crear([
                'usuario_id' => $usuarioId,
                'almacen_id' => $almacenId,
                'tipo_solicitud' => $tipoSolicitudModulo,
                'folio_documento' => $folioDocumento,
                'observaciones' => $observaciones,
                'productos' => $productos
            ]);

            responderJson(
                true,
                'La solicitud de ' . $nombreSolicitud
                . ' se registró correctamente.',
                $resultado
            );
        } catch (InvalidArgumentException $e) {
            responderJson(
                false,
                $e->getMessage(),
                [],
                422
            );
        } catch (Throwable $e) {
            error_log(
                'Error al guardar ' . $nombreSolicitud . ': '
                . $e->getMessage()
            );

            responderJson(
                false,
                'No se pudo registrar la solicitud de '
                . $nombreSolicitud . '.',
                [],
                500
            );
        }
    }

    // --------------------------------------------------
    // OBTENER RESURTIDO PARA EL MODAL
    // --------------------------------------------------

    if ($action === 'obtener') {
        $resurtidoId = filter_input(
            INPUT_GET,
            'id',
            FILTER_VALIDATE_INT
        );

        if (!$resurtidoId) {
            responderJson(
                false,
                'El resurtido solicitado no es válido.',
                [],
                422
            );
        }

        try {
            $resurtido = $controller->obtenerPorId(
                (int) $resurtidoId
            );

            if (!$resurtido) {
                responderJson(
                    false,
                    'No se encontró el resurtido.',
                    [],
                    404
                );
            }

            verificarTipoSolicitudModulo(
                $resurtido,
                $tipoSolicitudModulo
            );

            verificarAccesoResurtido(
                $resurtido,
                $rol,
                $usuarioId,
                $almacenId
            );

            responderJson(
                true,
                'Resurtido encontrado.',
                [
                    'resurtido' => $resurtido
                ]
            );
        } catch (Throwable $e) {
            error_log(
                'Error al consultar resurtido: '
                . $e->getMessage()
            );

            responderJson(
                false,
                'No fue posible consultar el resurtido.',
                [],
                500
            );
        }
    }

    // --------------------------------------------------
    // INICIAR SURTIDO
    // --------------------------------------------------

    if ($action === 'iniciar_surtido') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responderJson(
                false,
                'Método no permitido.',
                [],
                405
            );
        }

        if (
            !in_array(
                $rol,
                ['ENCARGADO', 'ADMINISTRADOR'],
                true
            )
        ) {
            responderJson(
                false,
                'No tiene permisos para surtir solicitudes.',
                [],
                403
            );
        }

        $datos = leerJson();
        validarTokenResurtidos($datos);

        $resurtidoId = (int) (
            $datos['id'] ?? 0
        );

        if ($resurtidoId <= 0) {
            responderJson(
                false,
                'La solicitud indicada no es válida.',
                [],
                422
            );
        }

        try {
            $resurtido = $controller->obtenerPorId(
                $resurtidoId
            );

            if (!$resurtido) {
                responderJson(
                    false,
                    'No se encontró la solicitud.',
                    [],
                    404
                );
            }

            verificarTipoSolicitudModulo(
                $resurtido,
                $tipoSolicitudModulo
            );

            verificarAccesoResurtido(
                $resurtido,
                $rol,
                $usuarioId,
                $almacenId
            );

            $resultado = $controller->iniciarSurtido(
                $resurtidoId,
                $usuarioId
            );

            responderJson(
                true,
                'La solicitud está lista para surtirse.',
                [
                    'resurtido' => $resultado,
                    'url' =>
                        'salidas.php?resurtido_id='
                        . $resurtidoId
                ]
            );
        } catch (Throwable $e) {
            error_log(
                'Error al iniciar surtido: '
                . $e->getMessage()
            );

            responderJson(
                false,
                $e->getMessage(),
                [],
                500
            );
        }
    }

    // --------------------------------------------------
    // ACTUALIZAR LISTA SIN RECARGAR LA PÁGINA
    // --------------------------------------------------

    if ($action === 'actualizaciones') {
        try {
            $solicitudes = obtenerSolicitudesVisibles(
                $controller,
                $rol,
                $usuarioId,
                $almacenId,
                $tipoSolicitudModulo
            );

            $huellaActual = generarHuellaSolicitudes(
                $solicitudes
            );

            $huellaCliente = trim(
                (string) ($_GET['huella'] ?? '')
            );

            $hayCambios =
                $huellaCliente === ''
                || !hash_equals(
                    $huellaActual,
                    $huellaCliente
                );

            responderJson(
                true,
                $hayCambios
                    ? 'La lista cambió.'
                    : 'Sin cambios.',
                [
                    'cambio' => $hayCambios,
                    'huella' => $huellaActual,
                    'solicitudes' => $hayCambios
                        ? $solicitudes
                        : [],
                    'consultado_en' => date('c')
                ]
            );
        } catch (Throwable $e) {
            error_log(
                'Error al actualizar solicitudes en tiempo real: '
                . $e->getMessage()
            );

            responderJson(
                false,
                'No fue posible actualizar la lista.',
                [],
                500
            );
        }
    }

    // --------------------------------------------------
    // CONSULTAR NOTIFICACIONES
    // --------------------------------------------------

    if ($action === 'notificaciones') {
        if (
            !in_array(
                $rol,
                ['ENCARGADO', 'ADMINISTRADOR'],
                true
            )
        ) {
            responderJson(
                false,
                'No tiene permisos para consultar notificaciones.',
                [],
                403
            );
        }

        try {
            $filtroAlmacen =
                $rol === 'ADMINISTRADOR'
                    ? null
                    : $almacenId;

            $notificaciones =
                $controller->obtenerPendientes(
                    $filtroAlmacen,
                    $tipoSolicitudModulo
                );

            responderJson(
                true,
                'Notificaciones consultadas.',
                [
                    'cantidad' => count($notificaciones),
                    'resurtidos' => $notificaciones
                ]
            );
        } catch (Throwable $e) {
            error_log(
                'Error al consultar notificaciones: '
                . $e->getMessage()
            );

            responderJson(
                false,
                'No fue posible consultar las notificaciones.',
                [],
                500
            );
        }
    }

    // --------------------------------------------------
    // CAMBIAR ESTADO
    // --------------------------------------------------

    if ($action === 'cambiar_estado') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responderJson(
                false,
                'Método no permitido.',
                [],
                405
            );
        }

        if (
            !in_array(
                $rol,
                ['ENCARGADO', 'ADMINISTRADOR'],
                true
            )
        ) {
            responderJson(
                false,
                'No tiene permisos para cambiar el estado.',
                [],
                403
            );
        }

        $datos = leerJson();
        validarTokenResurtidos($datos);

        $resurtidoId = (int) (
            $datos['id'] ?? 0
        );

        $estado = strtoupper(
            trim(
                (string) (
                    $datos['estado'] ?? ''
                )
            )
        );

        $estadosPermitidos = [
            'PENDIENTE',
            'EN_PROCESO',
            'SURTIDO',
            'PARCIAL',
            'CANCELADO'
        ];

        if (
            $resurtidoId <= 0
            || !in_array(
                $estado,
                $estadosPermitidos,
                true
            )
        ) {
            responderJson(
                false,
                'La información enviada no es válida.',
                [],
                422
            );
        }

        try {
            $resurtido = $controller->obtenerPorId(
                $resurtidoId
            );

            if (!$resurtido) {
                responderJson(
                    false,
                    'No se encontró la solicitud.',
                    [],
                    404
                );
            }

            verificarTipoSolicitudModulo(
                $resurtido,
                $tipoSolicitudModulo
            );

            verificarAccesoResurtido(
                $resurtido,
                $rol,
                $usuarioId,
                $almacenId
            );

            $controller->cambiarEstado(
                $resurtidoId,
                $estado,
                $usuarioId
            );

            responderJson(
                true,
                'El estado se actualizó correctamente.'
            );
        } catch (Throwable $e) {
            error_log(
                'Error al cambiar estado: '
                . $e->getMessage()
            );

            responderJson(
                false,
                $e->getMessage(),
                [],
                500
            );
        }
    }

    responderJson(
        false,
        'La acción solicitada no existe.',
        [],
        404
    );
}

// ======================================================
// CARGAR SOLICITUDES PARA LA PÁGINA
// ======================================================

$errorPagina = '';
$huellaSolicitudes = '';
$idsSolicitudesIniciales = [];

try {
    $resurtidos = obtenerSolicitudesVisibles(
        $controller,
        $rol,
        $usuarioId,
        $almacenId,
        $tipoSolicitudModulo
    );

    $huellaSolicitudes = generarHuellaSolicitudes(
        $resurtidos
    );

    $idsSolicitudesIniciales = array_map(
        static fn (array $solicitud): int =>
            (int) ($solicitud['id'] ?? 0),
        $resurtidos
    );
} catch (Throwable $e) {
    error_log(
        'Error al cargar ' . $nombreSolicitudes . ': '
        . $e->getMessage()
    );

    $resurtidos = [];
    $huellaSolicitudes = generarHuellaSolicitudes([]);

    $errorPagina =
        'No fue posible cargar las solicitudes de '
        . $nombreSolicitud . '.';
}

// El encabezado ya genera DOCTYPE, head y body.
$moduleCss = 'resurtidos';

require __DIR__
    . '/../app/views/layouts/header.php';

?>

<div class="resurtidos-contenedor">

    <section class="resurtidos-encabezado">

        <div>
            <h1>
                Solicitudes de <?= $esModuloTicket ? 'ticket' : 'resurtido' ?>
            </h1>

            <p>
                <?= $esModuloTicket
                    ? 'Registra tickets y consulta su surtido como salida.'
                    : 'Registra y consulta solicitudes de productos.' ?>
            </p>
        </div>

        <?php if ($puedeCrearSolicitud): ?>

            <button
                type="button"
                id="btnNuevoResurtido"
                class="btn-principal"
            >
                + Nuevo <?= $esModuloTicket ? 'ticket' : 'resurtido' ?>
            </button>

        <?php endif; ?>

    </section>

    <?php if ($errorPagina !== ''): ?>

        <div class="alerta alerta-error">
            <?= htmlspecialchars(
                $errorPagina,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>

    <?php endif; ?>

    <?php if ($puedeCrearSolicitud): ?>

        <section
            id="formularioResurtido"
            class="tarjeta formulario-resurtido"
        >

            <h2>
                Nueva solicitud de
                <?= $esModuloTicket ? 'ticket' : 'resurtido' ?>
            </h2>

            <?php if ($esModuloTicket): ?>

                <div class="campo">
                    <label for="folioDocumento">
                        Folio del ticket
                    </label>

                    <input
                        type="text"
                        id="folioDocumento"
                        maxlength="100"
                        autocomplete="off"
                        placeholder="Escriba el folio impreso en el ticket"
                        required
                    >
                </div>

            <?php endif; ?>

            <div class="campo-busqueda">

                <label for="codigoProducto">
                    Últimos 4 dígitos del código
                </label>

                <div class="busqueda-fila">

                    <input
                        type="tel"
                        id="codigoProducto"
                        inputmode="numeric"
                        maxlength="4"
                        autocomplete="off"
                        placeholder="Ejemplo: 3538"
                    >

                    <button
                        type="button"
                        id="btnBuscarProducto"
                        class="btn-principal"
                    >
                        Buscar
                    </button>

                </div>

            </div>

            <div
                id="resultadosBusqueda"
                class="resultados-busqueda"
            ></div>

            <div id="productosAgregados"></div>

            <div class="campo">

                <label for="observaciones">
                    Observaciones
                </label>

                <textarea
                    id="observaciones"
                    rows="3"
                    maxlength="1000"
                    placeholder="Escriba una observación opcional"
                ></textarea>

            </div>

            <button
                type="button"
                id="btnGuardarResurtido"
                class="btn-guardar"
            >
                Enviar solicitud
            </button>

        </section>

    <?php endif; ?>

    <section class="tarjeta">

        <h2>
            <?= $rol === 'GERENTE'
                ? 'Mis solicitudes'
                : 'Solicitudes recibidas' ?>
        </h2>

        <div
            class="lista-resurtidos"
            id="listaSolicitudesActuales"
            aria-live="polite"
        >

            <?php if (empty($resurtidos)): ?>

                <div class="lista-vacia">
                    No existen solicitudes de
                    <?= $esModuloTicket ? 'ticket' : 'resurtido' ?>.
                </div>

            <?php else: ?>

                <?php foreach ($resurtidos as $resurtido): ?>

                    <?php
                    $estado = strtoupper(
                        (string) $resurtido['estado']
                    );

                    $puedeSurtir =
                        in_array(
                            $rol,
                            ['ENCARGADO', 'ADMINISTRADOR'],
                            true
                        )
                        && in_array(
                            $estado,
                            [
                                'PENDIENTE',
                                'EN_PROCESO',
                                'PARCIAL'
                            ],
                            true
                        );
                    ?>

                    <article class="resurtido-item">

                        <div class="resurtido-informacion">

                            <strong>
                                <?= htmlspecialchars(
                                    $esModuloTicket
                                        ? (
                                            $resurtido['folio_documento']
                                            ?? $resurtido['folio']
                                        )
                                        : $resurtido['folio'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </strong>

                            <?php if ($esModuloTicket): ?>
                                <span>
                                    Control interno:
                                    <?= htmlspecialchars(
                                        $resurtido['folio'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>
                            <?php endif; ?>

                            <span>
                                <?= htmlspecialchars(
                                    $resurtido['fecha'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>

                            <span
                                class="estado estado-<?=
                                    strtolower($estado)
                                ?>"
                            >
                                <?= htmlspecialchars(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $estado
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>

                        </div>

                        <div class="resurtido-acciones">

                            <button
                                type="button"
                                class="btn-ver"
                                data-ver-resurtido="<?=
                                    (int) $resurtido['id']
                                ?>"
                            >
                                Ver
                            </button>

                            <?php if ($puedeSurtir): ?>

                                <button
                                    type="button"
                                    class="btn-surtir"
                                    data-surtir-resurtido="<?=
                                        (int) $resurtido['id']
                                    ?>"
                                >
                                    <?= $estado === 'PENDIENTE'
                                        ? 'Surtir'
                                        : 'Continuar' ?>
                                </button>

                            <?php endif; ?>

                        </div>

                    </article>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </section>

</div>

<!-- ===================================================
     MODAL PARA VER RESURTIDO
==================================================== -->

<div
    id="resurtidoModal"
    class="resurtido-modal"
    aria-hidden="true"
>

    <div
        class="resurtido-modal-contenido"
        role="dialog"
        aria-modal="true"
        aria-labelledby="tituloModalResurtido"
    >

        <div class="resurtido-modal-encabezado">

            <h2 id="tituloModalResurtido">
                Detalle del
                <?= $esModuloTicket ? 'ticket' : 'resurtido' ?>
            </h2>

            <button
                type="button"
                id="cerrarModalResurtido"
                class="resurtido-modal-cerrar"
                aria-label="Cerrar"
            >
                ×
            </button>

        </div>

        <div
            id="contenidoModalResurtido"
            class="resurtido-modal-cuerpo"
        >
            Cargando solicitud...
        </div>

        <div class="resurtido-modal-acciones">

            <button
                type="button"
                id="cerrarModalResurtidoInferior"
                class="btn-cancelar"
            >
                Cerrar
            </button>

        </div>

    </div>

</div>

<?php if ($rol === 'GERENTE'): ?>

<!-- ===================================================
     MODAL DE AUTORIZACIÓN DEL GERENTE
==================================================== -->

<div
    id="passwordGerenteModal"
    class="resurtido-modal"
    aria-hidden="true"
>

    <div
        class="resurtido-modal-contenido modal-password-contenido"
        role="dialog"
        aria-modal="true"
        aria-labelledby="tituloPasswordGerente"
    >

        <div class="resurtido-modal-encabezado">

            <h2 id="tituloPasswordGerente">
                Autorizar solicitud
            </h2>

            <button
                type="button"
                id="cerrarPasswordGerente"
                class="resurtido-modal-cerrar"
                aria-label="Cerrar"
            >
                ×
            </button>

        </div>

        <div class="resurtido-modal-cuerpo">

            <p class="password-gerente-ayuda">
                El gerente debe escribir su contraseña de
                inicio de sesión para autorizar el envío.
            </p>

            <div class="campo-password">

                <label for="passwordGerente">
                    Contraseña del gerente
                </label>

                <input
                    type="password"
                    id="passwordGerente"
                    maxlength="255"
                    autocomplete="off"
                    placeholder="Escriba la contraseña"
                >

            </div>

            <div
                id="errorPasswordGerente"
                class="password-gerente-error"
                role="alert"
            ></div>

        </div>

        <div class="resurtido-modal-acciones">

            <button
                type="button"
                id="cancelarPasswordGerente"
                class="btn-cancelar"
            >
                Cancelar
            </button>

            <button
                type="button"
                id="confirmarPasswordGerente"
                class="btn-guardar"
            >
                Autorizar y enviar
            </button>

        </div>

    </div>

</div>

<?php endif; ?>

<script>
    'use strict';

    const rolActual = <?= json_encode($rol) ?>;
    const csrfToken = <?= json_encode($csrfToken) ?>;
    const endpointModulo = <?= json_encode($endpointModulo) ?>;
    const tipoSolicitudModulo =
        <?= json_encode($tipoSolicitudModulo) ?>;
    const esModuloTicket =
        tipoSolicitudModulo === 'TICKET';
    let huellaSolicitudes =
        <?= json_encode($huellaSolicitudes) ?>;
    const idsSolicitudesConocidas = new Set(
        <?= json_encode($idsSolicitudesIniciales) ?>
            .map(Number)
    );
    const listaSolicitudesActuales =
        document.getElementById(
            'listaSolicitudesActuales'
        );
    const puedeSurtirSolicitudes =
        ['ENCARGADO', 'ADMINISTRADOR']
            .includes(rolActual);
    let consultaActualizacionesEnCurso = false;

    const productosSolicitud = [];

    const codigoInput =
        document.getElementById('codigoProducto');

    const botonBuscar =
        document.getElementById('btnBuscarProducto');

    const resultados =
        document.getElementById('resultadosBusqueda');

    const contenedorProductos =
        document.getElementById('productosAgregados');

    const botonGuardar =
        document.getElementById('btnGuardarResurtido');

    const botonNuevo =
        document.getElementById('btnNuevoResurtido');

    const folioDocumentoInput =
        document.getElementById('folioDocumento');

    const formularioResurtido =
        document.getElementById('formularioResurtido');

    const modal =
        document.getElementById('resurtidoModal');

    const contenidoModal =
        document.getElementById(
            'contenidoModalResurtido'
        );

    const modalPasswordGerente =
        document.getElementById(
            'passwordGerenteModal'
        );

    const inputPasswordGerente =
        document.getElementById(
            'passwordGerente'
        );

    const errorPasswordGerente =
        document.getElementById(
            'errorPasswordGerente'
        );

    const botonConfirmarPassword =
        document.getElementById(
            'confirmarPasswordGerente'
        );

    // ==================================================
    // BOTÓN NUEVO RESURTIDO
    // ==================================================

    botonNuevo?.addEventListener('click', function () {
        formularioResurtido?.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });

        window.setTimeout(function () {
            codigoInput?.focus();
        }, 400);
    });

    // ==================================================
    // BUSCAR PRODUCTOS
    // ==================================================

    botonBuscar?.addEventListener(
        'click',
        buscarProducto
    );

    codigoInput?.addEventListener(
        'input',
        function () {
            this.value = this.value
                .replace(/\D/g, '')
                .slice(0, 4);
        }
    );

    codigoInput?.addEventListener(
        'keydown',
        function (evento) {
            if (evento.key === 'Enter') {
                evento.preventDefault();
                buscarProducto();
            }
        }
    );

    async function buscarProducto() {
        if (!codigoInput || !resultados) {
            return;
        }

        const codigo = codigoInput.value.trim();

        if (!/^\d{4}$/.test(codigo)) {
            alert(
                'Ingrese exactamente los últimos 4 dígitos.'
            );

            codigoInput.focus();
            return;
        }

        resultados.innerHTML =
            '<div class="lista-vacia">'
            + 'Buscando producto...'
            + '</div>';

        try {
            const respuesta = await fetch(
                endpointModulo
                + '?action=buscar_producto&codigo='
                + encodeURIComponent(codigo),
                {
                    cache: 'no-store'
                }
            );

            const resultado = await respuesta.json();

            if (!respuesta.ok || !resultado.ok) {
                throw new Error(
                    resultado.mensaje
                    || 'No fue posible buscar.'
                );
            }

            mostrarResultados(
                resultado.datos.productos ?? []
            );
        } catch (error) {
            resultados.innerHTML =
                '<div class="alerta alerta-error">'
                + escaparHtml(error.message)
                + '</div>';
        }
    }

    function mostrarResultados(productos) {
        if (!resultados) {
            return;
        }

        resultados.innerHTML = '';

        if (!productos.length) {
            resultados.innerHTML =
                '<div class="lista-vacia">'
                + 'No se encontraron productos.'
                + '</div>';

            return;
        }

        productos.forEach(function (producto) {
            const existenciaDisponible =
                obtenerExistenciaDisponible(
                    producto
                );

            const existenciaBodega =
                normalizarCantidadVisual(
                    producto.existencia_bodega
                );

            const cantidadReservada =
                normalizarCantidadVisual(
                    producto.cantidad_reservada
                );

            const elemento =
                document.createElement('button');

            elemento.type = 'button';
            elemento.className = 'resultado-producto';
            elemento.disabled =
                existenciaDisponible <= 0;

            elemento.innerHTML = `
                <strong>
                    ${escaparHtml(producto.descripcion)}
                </strong>

                <span>
                    Código:
                    ${escaparHtml(producto.codigo)}
                </span>

                <span>
                    Unidad:
                    ${escaparHtml(
                        producto.unidad ?? 'PIEZA'
                    )}
                </span>

                <span class="existencia-disponible">
                    Existencia física en bodega:
                    <strong>
                        ${formatearCantidad(
                            existenciaBodega
                        )}
                    </strong>
                </span>

                <span class="existencia-disponible">
                    Disponible para solicitar:
                    <strong>
                        ${formatearCantidad(
                            existenciaDisponible
                        )}
                    </strong>
                </span>

                ${cantidadReservada > 0
                    ? `
                        <span class="cantidad-reservada">
                            Apartado en otras solicitudes:
                            <strong>
                                ${formatearCantidad(
                                    cantidadReservada
                                )}
                            </strong>
                        </span>
                    `
                    : ''}
            `;

            elemento.addEventListener(
                'click',
                function () {
                    agregarProducto(producto);
                }
            );

            resultados.appendChild(elemento);
        });
    }

    // ==================================================
    // AGREGAR Y QUITAR PRODUCTOS
    // ==================================================

    function agregarProducto(producto) {
        const existenciaDisponible =
            obtenerExistenciaDisponible(
                producto
            );

        if (existenciaDisponible <= 0) {
            alert(
                'Este producto no tiene existencia disponible en bodega.'
            );

            return;
        }

        const existente = productosSolicitud.find(
            function (item) {
                return Number(item.producto_id)
                    === Number(producto.id);
            }
        );

        if (existente) {
            if (
                existente.cantidad
                >= existente.existencia_disponible
            ) {
                alert(
                    'Ya alcanzó la existencia disponible de este producto.'
                );

                return;
            }

            existente.cantidad += 1;
        } else {
            productosSolicitud.push({
                producto_id: Number(producto.id),
                codigo: producto.codigo,
                descripcion: producto.descripcion,
                unidad: producto.unidad ?? 'PIEZA',
                cantidad: 1,
                existencia_disponible:
                    existenciaDisponible,
                existencia_bodega:
                    normalizarCantidadVisual(
                        producto.existencia_bodega
                    ),
                cantidad_reservada:
                    normalizarCantidadVisual(
                        producto.cantidad_reservada
                    )
            });
        }

        if (codigoInput) {
            codigoInput.value = '';
            codigoInput.focus();
        }

        if (resultados) {
            resultados.innerHTML = '';
        }

        renderizarProductos();
    }

    function renderizarProductos() {
        if (!contenedorProductos) {
            return;
        }

        contenedorProductos.innerHTML = '';

        productosSolicitud.forEach(
            function (producto, indice) {
                const fila =
                    document.createElement('div');

                fila.className = 'producto-agregado';

                fila.innerHTML = `
                    <div class="producto-datos">
                        <strong>
                            ${escaparHtml(
                                producto.descripcion
                            )}
                        </strong>

                        <span>
                            Código:
                            ${escaparHtml(producto.codigo)}
                        </span>

                        <span>
                            Unidad:
                            ${escaparHtml(producto.unidad)}
                        </span>

                        <span class="existencia-disponible">
                            Existencia física en bodega:
                            <strong>
                                ${formatearCantidad(
                                    producto
                                        .existencia_bodega
                                )}
                            </strong>
                        </span>

                        <span class="existencia-disponible">
                            Disponible para solicitar:
                            <strong>
                                ${formatearCantidad(
                                    producto
                                        .existencia_disponible
                                )}
                            </strong>
                        </span>
                    </div>

                    <div class="producto-cantidad">
                        <label>
                            Cantidad
                        </label>

                        <input
                            type="number"
                            min="1"
                            step="1"
                            max="${
                                producto
                                    .existencia_disponible
                            }"
                            value="${producto.cantidad}"
                            data-indice="${indice}"
                            class="cantidad-producto"
                        >
                    </div>

                    <button
                        type="button"
                        class="btn-eliminar"
                        data-eliminar="${indice}"
                    >
                        Eliminar
                    </button>
                `;

                contenedorProductos.appendChild(fila);
            }
        );

        document
            .querySelectorAll('.cantidad-producto')
            .forEach(function (input) {
                input.addEventListener(
                    'change',
                    function () {
                        const indice = Number(
                            this.dataset.indice
                        );

                        const cantidad = Number(
                            this.value
                        );

                        const existenciaDisponible =
                            Number(
                                productosSolicitud[indice]
                                    .existencia_disponible
                            );

                        let cantidadValida =
                            Number.isFinite(cantidad)
                            && cantidad > 0
                                ? Math.floor(cantidad)
                                : 1;

                        if (
                            cantidadValida
                            > existenciaDisponible
                        ) {
                            alert(
                                'La cantidad no puede superar '
                                + 'la existencia disponible en bodega.'
                            );

                            cantidadValida =
                                existenciaDisponible;
                        }

                        productosSolicitud[indice].cantidad =
                            cantidadValida;

                        this.value =
                            productosSolicitud[indice]
                                .cantidad;
                    }
                );
            });

        document
            .querySelectorAll('[data-eliminar]')
            .forEach(function (boton) {
                boton.addEventListener(
                    'click',
                    function () {
                        const indice = Number(
                            this.dataset.eliminar
                        );

                        productosSolicitud.splice(
                            indice,
                            1
                        );

                        renderizarProductos();
                    }
                );
            });
    }

    // ==================================================
    // GUARDAR RESURTIDO
    // ==================================================

    botonGuardar?.addEventListener(
        'click',
        guardarResurtido
    );

    async function guardarResurtido() {
        const folioDocumento =
            folioDocumentoInput?.value.trim() ?? '';

        if (esModuloTicket && folioDocumento === '') {
            alert('Escriba el folio del ticket.');
            folioDocumentoInput?.focus();
            return;
        }

        if (!productosSolicitud.length) {
            alert(
                'Agregue por lo menos un producto.'
            );

            return;
        }

        const productoSinExistencia =
            productosSolicitud.find(
                function (producto) {
                    const cantidad = Number(
                        producto.cantidad
                    );

                    const existencia = Number(
                        producto.existencia_disponible
                    );

                    return (
                        !Number.isFinite(cantidad)
                        || cantidad <= 0
                        || !Number.isFinite(existencia)
                        || cantidad > existencia
                    );
                }
            );

        if (productoSinExistencia) {
            alert(
                'Revise la cantidad de '
                + productoSinExistencia.descripcion
                + '. No puede superar la existencia '
                + 'disponible en bodega.'
            );

            return;
        }

        if (rolActual === 'GERENTE') {
            abrirModalPasswordGerente();
            return;
        }

        if (
            !confirm(
                '¿Desea enviar esta solicitud de '
                + (esModuloTicket ? 'ticket' : 'resurtido')
                + '?'
            )
        ) {
            return;
        }

        await enviarResurtido('');
    }

    async function enviarResurtido(
        passwordGerente
    ) {
        botonGuardar.disabled = true;
        botonGuardar.textContent = 'Enviando...';

        if (botonConfirmarPassword) {
            botonConfirmarPassword.disabled = true;
            botonConfirmarPassword.textContent =
                'Autorizando...';
        }

        if (errorPasswordGerente) {
            errorPasswordGerente.textContent = '';
        }

        try {
            const respuesta = await fetch(
                endpointModulo + '?action=guardar',
                {
                    method: 'POST',
                    headers: {
                        'Content-Type':
                            'application/json'
                    },
                    body: JSON.stringify({
                        csrf_token: csrfToken,

                        password_gerente:
                            passwordGerente,

                        tipo_solicitud:
                            tipoSolicitudModulo,

                        folio_documento:
                            folioDocumentoInput
                                ?.value
                                .trim()
                            ?? '',

                        observaciones:
                            document
                                .getElementById(
                                    'observaciones'
                                )
                                ?.value
                                .trim()
                            ?? '',

                        productos: productosSolicitud
                    })
                }
            );

            const contenidoRespuesta =
                await respuesta.text();

            let resultado;

            try {
                resultado = JSON.parse(
                    contenidoRespuesta
                );
            } catch (errorJson) {
                throw new Error(
                    'El servidor no devolvió una respuesta válida. '
                    + 'Verifique que se ejecutó el archivo '
                    + 'database/instalar_tickets.sql.'
                );
            }

            if (!respuesta.ok || !resultado.ok) {
                const errorSolicitud = new Error(
                    resultado.mensaje
                    || 'No fue posible guardar.'
                );

                errorSolicitud.esPasswordIncorrecto =
                    respuesta.status === 403
                    && resultado.mensaje ===
                        'La contraseña del gerente es incorrecta.';

                throw errorSolicitud;
            }

            alert(resultado.mensaje);

            window.location.href =
                endpointModulo;
        } catch (error) {
            if (
                error.esPasswordIncorrecto === true
                &&
                rolActual === 'GERENTE'
                && modalPasswordGerente
                    ?.classList
                    .contains('activo')
                && errorPasswordGerente
            ) {
                errorPasswordGerente.textContent =
                    error.message;

                inputPasswordGerente?.focus();
                inputPasswordGerente?.select();
            } else {
                alert(error.message);
            }

            botonGuardar.disabled = false;
            botonGuardar.textContent =
                'Enviar solicitud';

            if (botonConfirmarPassword) {
                botonConfirmarPassword.disabled =
                    false;

                botonConfirmarPassword.textContent =
                    'Autorizar y enviar';
            }
        }
    }

    botonConfirmarPassword?.addEventListener(
        'click',
        async function () {
            const password = (
                inputPasswordGerente?.value
                ?? ''
            ).trim();

            if (password === '') {
                if (errorPasswordGerente) {
                    errorPasswordGerente.textContent =
                        'Escriba la contraseña del gerente.';
                }

                inputPasswordGerente?.focus();
                return;
            }

            await enviarResurtido(password);
        }
    );

    inputPasswordGerente?.addEventListener(
        'keydown',
        function (evento) {
            if (evento.key === 'Enter') {
                evento.preventDefault();
                botonConfirmarPassword?.click();
            }
        }
    );

    // ==================================================
    // VER RESURTIDO
    // ==================================================

    function enlazarBotonesVerSolicitud() {
        document
            .querySelectorAll('[data-ver-resurtido]')
            .forEach(function (boton) {
                if (boton.dataset.eventoVerActivo === '1') {
                    return;
                }

                boton.dataset.eventoVerActivo = '1';

                boton.addEventListener(
                    'click',
                    function () {
                        verResurtido(
                            Number(
                                this.dataset.verResurtido
                            )
                        );
                    }
                );
            });
    }

    enlazarBotonesVerSolicitud();

    async function verResurtido(id) {
        if (!id || !modal || !contenidoModal) {
            return;
        }

        abrirModal();

        contenidoModal.innerHTML =
            '<div class="lista-vacia">'
            + 'Cargando solicitud...'
            + '</div>';

        try {
            const respuesta = await fetch(
                endpointModulo + '?action=obtener&id='
                + encodeURIComponent(id),
                {
                    cache: 'no-store'
                }
            );

            const resultado = await respuesta.json();

            if (!respuesta.ok || !resultado.ok) {
                throw new Error(
                    resultado.mensaje
                    || 'No fue posible consultar.'
                );
            }

            mostrarDetalleResurtido(
                resultado.datos.resurtido
            );
        } catch (error) {
            contenidoModal.innerHTML =
                '<div class="alerta alerta-error">'
                + escaparHtml(error.message)
                + '</div>';
        }
    }

    function mostrarDetalleResurtido(resurtido) {
        const productos =
            Array.isArray(resurtido.productos)
                ? resurtido.productos
                : [];

        let productosHtml = '';

        if (!productos.length) {
            productosHtml =
                '<div class="lista-vacia">'
                + 'Esta solicitud no tiene productos.'
                + '</div>';
        } else {
            productosHtml = productos
                .map(function (producto) {
                    return `
                        <div class="producto-agregado">
                            <div class="producto-datos">
                                <strong>
                                    ${escaparHtml(
                                        producto.descripcion
                                    )}
                                </strong>

                                <span>
                                    Código:
                                    ${escaparHtml(
                                        producto.codigo
                                    )}
                                </span>

                                <span>
                                    Unidad:
                                    ${escaparHtml(
                                        producto.unidad
                                        ?? 'PIEZA'
                                    )}
                                </span>
                            </div>

                            <div class="producto-cantidad">
                                <label>
                                    Solicitado
                                </label>

                                <strong>
                                    ${formatearCantidad(
                                        producto
                                            .cantidad_solicitada
                                    )}
                                </strong>
                            </div>
                        </div>
                    `;
                })
                .join('');
        }

        contenidoModal.innerHTML = `
            <div class="detalle-resurtido">

                <p>
                    <strong>
                        ${esModuloTicket
                            ? 'Folio del ticket'
                            : 'Folio'}:
                    </strong>
                    ${escaparHtml(
                        esModuloTicket
                            ? (
                                resurtido.folio_documento
                                || resurtido.folio
                            )
                            : resurtido.folio
                    )}
                </p>

                ${esModuloTicket ? `
                    <p>
                        <strong>Control interno:</strong>
                        ${escaparHtml(resurtido.folio)}
                    </p>
                ` : ''}

                <p>
                    <strong>Fecha:</strong>
                    ${escaparHtml(
                        resurtido.fecha_solicitud
                    )}
                </p>

                <p>
                    <strong>Solicitante:</strong>
                    ${escaparHtml(
                        resurtido.solicitante_nombre
                        ?? 'Sin información'
                    )}
                </p>

                <p>
                    <strong>Almacén:</strong>
                    ${escaparHtml(
                        resurtido.almacen_nombre
                        ?? 'Sin información'
                    )}
                </p>

                <p>
                    <strong>Estado:</strong>
                    ${escaparHtml(
                        String(resurtido.estado)
                            .replaceAll('_', ' ')
                    )}
                </p>

                <p>
                    <strong>Observaciones:</strong>
                    ${escaparHtml(
                        resurtido.observaciones
                        || 'Sin observaciones'
                    )}
                </p>

                <h3>Productos solicitados</h3>

                <div class="lista-productos-modal">
                    ${productosHtml}
                </div>

            </div>
        `;
    }

    // ==================================================
    // INICIAR SURTIDO
    // ==================================================

    function enlazarBotonesSurtirSolicitud() {
        document
            .querySelectorAll('[data-surtir-resurtido]')
            .forEach(function (boton) {
                if (boton.dataset.eventoSurtirActivo === '1') {
                    return;
                }

                boton.dataset.eventoSurtirActivo = '1';

                boton.addEventListener(
                    'click',
                    function () {
                        iniciarSurtido(
                            Number(
                                this.dataset
                                    .surtirResurtido
                            ),
                            this
                        );
                    }
                );
            });
    }

    enlazarBotonesSurtirSolicitud();

    async function iniciarSurtido(id, boton) {
        if (!id) {
            return;
        }

        if (
            !confirm(
                '¿Desea abrir esta solicitud en el módulo de salidas?'
            )
        ) {
            return;
        }

        const textoOriginal =
            boton.textContent;

        boton.disabled = true;
        boton.textContent = 'Abriendo...';

        try {
            const respuesta = await fetch(
                endpointModulo + '?action=iniciar_surtido',
                {
                    method: 'POST',
                    headers: {
                        'Content-Type':
                            'application/json'
                    },
                    body: JSON.stringify({
                        id: id,
                        csrf_token: csrfToken
                    })
                }
            );

            const resultado = await respuesta.json();

            if (!respuesta.ok || !resultado.ok) {
                throw new Error(
                    resultado.mensaje
                    || 'No fue posible iniciar el surtido.'
                );
            }

            window.location.href =
                resultado.datos.url;
        } catch (error) {
            alert(error.message);

            boton.disabled = false;
            boton.textContent = textoOriginal;
        }
    }

    // ==================================================
    // ACTUALIZACIÓN AUTOMÁTICA SIN RECARGAR
    // ==================================================

    function renderizarSolicitudesActuales(
        solicitudes
    ) {
        if (!listaSolicitudesActuales) {
            return;
        }

        if (!Array.isArray(solicitudes) || !solicitudes.length) {
            listaSolicitudesActuales.innerHTML = `
                <div class="lista-vacia">
                    No existen solicitudes de
                    ${esModuloTicket ? 'ticket' : 'resurtido'}.
                </div>
            `;

            return;
        }

        listaSolicitudesActuales.innerHTML =
            solicitudes.map(function (solicitud) {
                const id = Number(solicitud.id) || 0;
                const estado = String(
                    solicitud.estado || 'PENDIENTE'
                ).toUpperCase();

                const folioVisible = esModuloTicket
                    ? (
                        solicitud.folio_documento
                        || solicitud.folio
                        || ''
                    )
                    : (solicitud.folio || '');

                const controlInterno = esModuloTicket
                    ? `
                        <span>
                            Control interno:
                            ${escaparHtml(
                                solicitud.folio || ''
                            )}
                        </span>
                    `
                    : '';

                const puedeSurtir =
                    puedeSurtirSolicitudes
                    && [
                        'PENDIENTE',
                        'EN_PROCESO',
                        'PARCIAL'
                    ].includes(estado);

                const botonSurtir = puedeSurtir
                    ? `
                        <button
                            type="button"
                            class="btn-surtir"
                            data-surtir-resurtido="${id}"
                        >
                            ${estado === 'PENDIENTE'
                                ? 'Surtir'
                                : 'Continuar'}
                        </button>
                    `
                    : '';

                return `
                    <article
                        class="resurtido-item"
                        data-solicitud-id="${id}"
                    >
                        <div class="resurtido-informacion">
                            <strong>
                                ${escaparHtml(folioVisible)}
                            </strong>

                            ${controlInterno}

                            <span>
                                ${escaparHtml(
                                    solicitud.fecha || ''
                                )}
                            </span>

                            <span
                                class="estado estado-${
                                    escaparHtml(
                                        estado.toLowerCase()
                                    )
                                }"
                            >
                                ${escaparHtml(
                                    estado.replaceAll('_', ' ')
                                )}
                            </span>
                        </div>

                        <div class="resurtido-acciones">
                            <button
                                type="button"
                                class="btn-ver"
                                data-ver-resurtido="${id}"
                            >
                                Ver
                            </button>

                            ${botonSurtir}
                        </div>
                    </article>
                `;
            }).join('');

        enlazarBotonesVerSolicitud();
        enlazarBotonesSurtirSolicitud();
    }

    function mostrarAvisoNuevaSolicitud(
        cantidad
    ) {
        const avisoAnterior =
            document.getElementById(
                'avisoNuevaSolicitud'
            );

        avisoAnterior?.remove();

        const aviso = document.createElement('div');
        aviso.id = 'avisoNuevaSolicitud';
        aviso.className = 'aviso-tiempo-real';
        aviso.setAttribute('role', 'status');

        aviso.textContent =
            cantidad === 1
                ? 'Nueva solicitud recibida.'
                : cantidad
                    + ' nuevas solicitudes recibidas.';

        document.body.appendChild(aviso);

        window.setTimeout(function () {
            aviso.classList.add('visible');
        }, 20);

        window.setTimeout(function () {
            aviso.classList.remove('visible');

            window.setTimeout(function () {
                aviso.remove();
            }, 250);
        }, 4500);
    }

    async function consultarActualizacionesSolicitudes() {
        if (
            document.hidden
            || consultaActualizacionesEnCurso
            || !listaSolicitudesActuales
        ) {
            return;
        }

        consultaActualizacionesEnCurso = true;

        try {
            const url =
                endpointModulo
                + '?action=actualizaciones&huella='
                + encodeURIComponent(huellaSolicitudes);

            const respuesta = await fetch(
                url,
                {
                    method: 'GET',
                    cache: 'no-store',
                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest'
                    }
                }
            );

            const resultado = await respuesta.json();

            if (!respuesta.ok || !resultado.ok) {
                return;
            }

            huellaSolicitudes =
                String(resultado.datos?.huella ?? '');

            if (!resultado.datos?.cambio) {
                return;
            }

            const solicitudes =
                Array.isArray(
                    resultado.datos?.solicitudes
                )
                    ? resultado.datos.solicitudes
                    : [];

            const nuevasSolicitudes =
                solicitudes.filter(function (solicitud) {
                    const id = Number(solicitud.id) || 0;

                    return (
                        id > 0
                        && !idsSolicitudesConocidas.has(id)
                    );
                });

            solicitudes.forEach(function (solicitud) {
                const id = Number(solicitud.id) || 0;

                if (id > 0) {
                    idsSolicitudesConocidas.add(id);
                }
            });

            renderizarSolicitudesActuales(
                solicitudes
            );

            if (
                nuevasSolicitudes.length > 0
                && rolActual !== 'GERENTE'
            ) {
                mostrarAvisoNuevaSolicitud(
                    nuevasSolicitudes.length
                );
            }
        } catch (error) {
            console.warn(
                'No fue posible actualizar las solicitudes.'
            );
        } finally {
            consultaActualizacionesEnCurso = false;
        }
    }

    window.setInterval(
        consultarActualizacionesSolicitudes,
        3000
    );

    document.addEventListener(
        'visibilitychange',
        function () {
            if (!document.hidden) {
                consultarActualizacionesSolicitudes();
            }
        }
    );

    // ==================================================
    // ABRIR Y CERRAR MODAL
    // ==================================================

    function abrirModal() {
        modal.classList.add('activo');
        modal.setAttribute('aria-hidden', 'false');

        document.body.style.overflow = 'hidden';
    }

    function cerrarModal() {
        modal.classList.remove('activo');
        modal.setAttribute('aria-hidden', 'true');

        document.body.style.overflow = '';
    }

    function abrirModalPasswordGerente() {
        if (!modalPasswordGerente) {
            return;
        }

        if (inputPasswordGerente) {
            inputPasswordGerente.value = '';
        }

        if (errorPasswordGerente) {
            errorPasswordGerente.textContent = '';
        }

        modalPasswordGerente.classList.add('activo');

        modalPasswordGerente.setAttribute(
            'aria-hidden',
            'false'
        );

        document.body.style.overflow = 'hidden';

        window.setTimeout(function () {
            inputPasswordGerente?.focus();
        }, 100);
    }

    function cerrarModalPasswordGerente() {
        if (
            botonConfirmarPassword
            && botonConfirmarPassword.disabled
        ) {
            return;
        }

        modalPasswordGerente?.classList.remove(
            'activo'
        );

        modalPasswordGerente?.setAttribute(
            'aria-hidden',
            'true'
        );

        if (inputPasswordGerente) {
            inputPasswordGerente.value = '';
        }

        if (errorPasswordGerente) {
            errorPasswordGerente.textContent = '';
        }

        document.body.style.overflow = '';
    }

    document
        .getElementById('cerrarModalResurtido')
        ?.addEventListener(
            'click',
            cerrarModal
        );

    document
        .getElementById(
            'cerrarModalResurtidoInferior'
        )
        ?.addEventListener(
            'click',
            cerrarModal
        );

    modal?.addEventListener(
        'click',
        function (evento) {
            if (evento.target === modal) {
                cerrarModal();
            }
        }
    );

    document
        .getElementById('cerrarPasswordGerente')
        ?.addEventListener(
            'click',
            cerrarModalPasswordGerente
        );

    document
        .getElementById('cancelarPasswordGerente')
        ?.addEventListener(
            'click',
            cerrarModalPasswordGerente
        );

    modalPasswordGerente?.addEventListener(
        'click',
        function (evento) {
            if (evento.target === modalPasswordGerente) {
                cerrarModalPasswordGerente();
            }
        }
    );

    document.addEventListener(
        'keydown',
        function (evento) {
            if (
                evento.key === 'Escape'
                && modalPasswordGerente
                    ?.classList
                    .contains('activo')
            ) {
                cerrarModalPasswordGerente();
                return;
            }

            if (
                evento.key === 'Escape'
                && modal?.classList.contains('activo')
            ) {
                cerrarModal();
            }
        }
    );

    // ==================================================
    // UTILIDADES
    // ==================================================

    function obtenerExistenciaDisponible(
        producto
    ) {
        return normalizarCantidadVisual(
            producto?.existencia_disponible
            ?? producto?.existencia_bodega
            ?? 0
        );
    }

    function normalizarCantidadVisual(valor) {
        const existencia = Number(valor);

        return Number.isFinite(existencia)
            ? Math.max(0, existencia)
            : 0;
    }

    function formatearCantidad(valor) {
        const numero = Number(valor);

        if (!Number.isFinite(numero)) {
            return '0';
        }

        return Number.isInteger(numero)
            ? String(numero)
            : numero.toFixed(3)
                .replace(/0+$/, '')
                .replace(/\.$/, '');
    }

    function escaparHtml(valor) {
        const elemento =
            document.createElement('div');

        elemento.textContent =
            String(valor ?? '');

        return elemento.innerHTML;
    }
</script>

<?php

require __DIR__
    . '/../app/views/layouts/footer.php';

?>
