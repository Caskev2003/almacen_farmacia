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

$rolesPermitidos = [
    'ADMINISTRADOR',
    'GERENTE',
    'ENCARGADO'
];

if (!in_array($rol, $rolesPermitidos, true)) {
    http_response_code(403);
    exit('Acceso denegado.');
}

if (empty($_SESSION['csrf_resurtidos'])) {
    $_SESSION['csrf_resurtidos'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['csrf_resurtidos'];

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
    $tokenRecibido = (string) (
        $datos['csrf_token'] ?? ''
    );

    $tokenSesion = (string) (
        $_SESSION['csrf_resurtidos'] ?? ''
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
                    $codigo
                );

            responderJson(
                true,
                'Búsqueda realizada correctamente.',
                [
                    'productos' => $productos
                ]
            );
        } catch (Throwable $e) {
            error_log(
                'Error al buscar producto para resurtido: '
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

        if (
            !in_array(
                $rol,
                ['GERENTE', 'ADMINISTRADOR'],
                true
            )
        ) {
            responderJson(
                false,
                'No tiene permisos para crear resurtidos.',
                [],
                403
            );
        }

        $datos = leerJson();
        validarTokenResurtidos($datos);

        $productos = $datos['productos'] ?? [];

        $observaciones = trim(
            (string) (
                $datos['observaciones'] ?? ''
            )
        );

        if (!is_array($productos) || empty($productos)) {
            responderJson(
                false,
                'Debe agregar por lo menos un producto.',
                [],
                422
            );
        }

        try {
            $resultado = $controller->crear([
                'usuario_id' => $usuarioId,
                'almacen_id' => $almacenId,
                'observaciones' => $observaciones,
                'productos' => $productos
            ]);

            responderJson(
                true,
                'La solicitud de resurtido se registró correctamente.',
                $resultado
            );
        } catch (Throwable $e) {
            error_log(
                'Error al guardar resurtido: '
                . $e->getMessage()
            );

            responderJson(
                false,
                'No se pudo registrar la solicitud de resurtido.',
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
                    $filtroAlmacen
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

try {
    if ($rol === 'GERENTE') {
        $resurtidos = $controller->obtenerPorGerente(
            $usuarioId
        );
    } elseif ($rol === 'ENCARGADO') {
        $resurtidos = $controller->obtenerTodos(
            $almacenId > 0 ? $almacenId : null
        );
    } else {
        $resurtidos = $controller->obtenerTodos();
    }
} catch (Throwable $e) {
    error_log(
        'Error al cargar resurtidos: '
        . $e->getMessage()
    );

    $resurtidos = [];

    $errorPagina =
        'No fue posible cargar las solicitudes de resurtido.';
}

// El encabezado ya genera DOCTYPE, head y body.
$moduleCss = 'resurtidos';

require __DIR__
    . '/../app/views/layouts/header.php';

?>

<div class="resurtidos-contenedor">

    <section class="resurtidos-encabezado">

        <div>
            <h1>Solicitudes de resurtido</h1>

            <p>
                Registra y consulta solicitudes de productos.
            </p>
        </div>

        <?php if (
            in_array(
                $rol,
                ['GERENTE', 'ADMINISTRADOR'],
                true
            )
        ): ?>

            <button
                type="button"
                id="btnNuevoResurtido"
                class="btn-principal"
            >
                + Nuevo resurtido
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

    <?php if (
        in_array(
            $rol,
            ['GERENTE', 'ADMINISTRADOR'],
            true
        )
    ): ?>

        <section
            id="formularioResurtido"
            class="tarjeta formulario-resurtido"
        >

            <h2>Nueva solicitud</h2>

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

        <div class="lista-resurtidos">

            <?php if (empty($resurtidos)): ?>

                <div class="lista-vacia">
                    No existen solicitudes de resurtido.
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
                                    $resurtido['folio'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </strong>

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
                Detalle del resurtido
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

<script>
    'use strict';

    const rolActual = <?= json_encode($rol) ?>;
    const csrfToken = <?= json_encode($csrfToken) ?>;

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

    const formularioResurtido =
        document.getElementById('formularioResurtido');

    const modal =
        document.getElementById('resurtidoModal');

    const contenidoModal =
        document.getElementById(
            'contenidoModalResurtido'
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
                'resurtidos.php?action=buscar_producto&codigo='
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
            const elemento =
                document.createElement('button');

            elemento.type = 'button';
            elemento.className = 'resultado-producto';

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
        const existente = productosSolicitud.find(
            function (item) {
                return Number(item.producto_id)
                    === Number(producto.id);
            }
        );

        if (existente) {
            existente.cantidad += 1;
        } else {
            productosSolicitud.push({
                producto_id: Number(producto.id),
                codigo: producto.codigo,
                descripcion: producto.descripcion,
                unidad: producto.unidad ?? 'PIEZA',
                cantidad: 1
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
                    </div>

                    <div class="producto-cantidad">
                        <label>
                            Cantidad
                        </label>

                        <input
                            type="number"
                            min="1"
                            step="1"
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

                        productosSolicitud[indice].cantidad =
                            Number.isFinite(cantidad)
                            && cantidad > 0
                                ? cantidad
                                : 1;

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
        if (!productosSolicitud.length) {
            alert(
                'Agregue por lo menos un producto.'
            );

            return;
        }

        if (
            !confirm(
                '¿Desea enviar esta solicitud de resurtido?'
            )
        ) {
            return;
        }

        botonGuardar.disabled = true;
        botonGuardar.textContent = 'Enviando...';

        try {
            const respuesta = await fetch(
                'resurtidos.php?action=guardar',
                {
                    method: 'POST',
                    headers: {
                        'Content-Type':
                            'application/json'
                    },
                    body: JSON.stringify({
                        csrf_token: csrfToken,

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

            const resultado = await respuesta.json();

            if (!respuesta.ok || !resultado.ok) {
                throw new Error(
                    resultado.mensaje
                    || 'No fue posible guardar.'
                );
            }

            alert(resultado.mensaje);

            window.location.href =
                'resurtidos.php';
        } catch (error) {
            alert(error.message);

            botonGuardar.disabled = false;
            botonGuardar.textContent =
                'Enviar solicitud';
        }
    }

    // ==================================================
    // VER RESURTIDO
    // ==================================================

    document
        .querySelectorAll('[data-ver-resurtido]')
        .forEach(function (boton) {
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
                'resurtidos.php?action=obtener&id='
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
                    <strong>Folio:</strong>
                    ${escaparHtml(resurtido.folio)}
                </p>

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

    document
        .querySelectorAll('[data-surtir-resurtido]')
        .forEach(function (boton) {
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
                'resurtidos.php?action=iniciar_surtido',
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

    document.addEventListener(
        'keydown',
        function (evento) {
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