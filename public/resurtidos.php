<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/controllers/ResurtidoController.php';

requireLogin();

date_default_timezone_set('America/Mexico_City');

$user = currentUser();
$rol = strtoupper(trim($user['rol'] ?? ''));

$rolesPermitidos = [
    'ADMINISTRADOR',
    'GERENTE',
    'ENCARGADO'
];

if (!in_array($rol, $rolesPermitidos, true)) {
    http_response_code(403);
    exit('Acceso denegado.');
}

$controller = new ResurtidoController();
$action = trim($_GET['action'] ?? '');

// ======================================================
// FUNCIÓN PARA RESPONDER JSON
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
    );

    exit;
}

// ======================================================
// ACCIONES INTERNAS DEL MISMO ARCHIVO
// ======================================================

if ($action !== '') {

    // --------------------------------------------------
    // BUSCAR PRODUCTO
    // --------------------------------------------------

    if ($action === 'buscar_producto') {
        $codigo = trim($_GET['codigo'] ?? '');

        if (!preg_match('/^\d{4}$/', $codigo)) {
            responderJson(
                false,
                'Ingrese exactamente los últimos 4 dígitos del código.',
                [],
                422
            );
        }

        try {
            $productos = $controller->buscarPorUltimosDigitos(
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

        if (!in_array($rol, ['GERENTE', 'ADMINISTRADOR'], true)) {
            responderJson(
                false,
                'No tiene permisos para crear resurtidos.',
                [],
                403
            );
        }

        $contenido = file_get_contents('php://input');
        $datos = json_decode($contenido, true);

        if (!is_array($datos)) {
            responderJson(
                false,
                'Los datos enviados no son válidos.',
                [],
                422
            );
        }

        $productos = $datos['productos'] ?? [];
        $observaciones = trim(
            $datos['observaciones'] ?? ''
        );

        if (empty($productos)) {
            responderJson(
                false,
                'Debe agregar por lo menos un producto.',
                [],
                422
            );
        }

        try {
            $resultado = $controller->crear([
                'usuario_id' => (int) ($user['id'] ?? 0),
                'almacen_id' => (int) ($user['almacen_id'] ?? 0),
                'observaciones' => $observaciones,
                'productos' => $productos
            ]);

            responderJson(
                true,
                'La solicitud de resurtido se registró correctamente.',
                $resultado
            );
        } catch (Throwable $e) {
            responderJson(
                false,
                'No se pudo registrar la solicitud de resurtido.',
                [],
                500
            );
        }
    }

    // --------------------------------------------------
    // OBTENER UN RESURTIDO
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
                $resurtidoId
            );

            if (!$resurtido) {
                responderJson(
                    false,
                    'No se encontró el resurtido.',
                    [],
                    404
                );
            }

            responderJson(
                true,
                'Resurtido encontrado.',
                [
                    'resurtido' => $resurtido
                ]
            );
        } catch (Throwable $e) {
            responderJson(
                false,
                'No fue posible consultar el resurtido.',
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
            $notificaciones = $controller->obtenerPendientes(
                (int) ($user['almacen_id'] ?? 0)
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
            responderJson(
                false,
                'No fue posible consultar las notificaciones.',
                [],
                500
            );
        }
    }

    // --------------------------------------------------
    // CAMBIAR ESTADO DEL RESURTIDO
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

        $contenido = file_get_contents('php://input');
        $datos = json_decode($contenido, true);

        $resurtidoId = (int) ($datos['id'] ?? 0);
        $estado = strtoupper(
            trim($datos['estado'] ?? '')
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
            || !in_array($estado, $estadosPermitidos, true)
        ) {
            responderJson(
                false,
                'La información enviada no es válida.',
                [],
                422
            );
        }

        try {
            $controller->cambiarEstado(
                $resurtidoId,
                $estado,
                (int) ($user['id'] ?? 0)
            );

            responderJson(
                true,
                'El estado se actualizó correctamente.'
            );
        } catch (Throwable $e) {
            responderJson(
                false,
                'No se pudo actualizar el estado.',
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
// CARGAR INFORMACIÓN PARA MOSTRAR LA PÁGINA
// ======================================================

try {
    if ($rol === 'GERENTE') {
        $resurtidos = $controller->obtenerPorGerente(
            (int) ($user['id'] ?? 0)
        );
    } else {
        $resurtidos = $controller->obtenerTodos();
    }
} catch (Throwable $e) {
    $resurtidos = [];
    $errorPagina = 'No fue posible cargar los resurtidos.';
}

?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Resurtidos</title>

    <link
        rel="stylesheet"
        href="assets/css/global.css"
    >

    <link
        rel="stylesheet"
        href="assets/css/resurtidos.css"
    >
</head>

<body>

<?php require __DIR__ . '/../app/views/layouts/header.php'; ?>

<main class="resurtidos-contenedor">

    <section class="resurtidos-encabezado">

        <div>
            <h1>Solicitudes de resurtido</h1>

            <p>
                Registra y consulta solicitudes de productos.
            </p>
        </div>

        <?php if ($rol === 'GERENTE'): ?>

            <button
                type="button"
                id="btnNuevoResurtido"
                class="btn-principal"
            >
                + Nuevo resurtido
            </button>

        <?php endif; ?>

    </section>

    <?php if (!empty($errorPagina)): ?>

        <div class="alerta alerta-error">
            <?= htmlspecialchars(
                $errorPagina,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>

    <?php endif; ?>

    <?php if ($rol === 'GERENTE'): ?>

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
                                    strtolower(
                                        $resurtido['estado']
                                    )
                                ?>"
                            >
                                <?= htmlspecialchars(
                                    $resurtido['estado'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>

                        </div>

                        <div class="resurtido-acciones">

                            <button
                                type="button"
                                class="btn-ver"
                                data-id="<?= (int) $resurtido['id'] ?>"
                            >
                                Ver
                            </button>

                            <?php if (
                                in_array(
                                    $rol,
                                    ['ENCARGADO', 'ADMINISTRADOR'],
                                    true
                                )
                                && $resurtido['estado'] === 'PENDIENTE'
                            ): ?>

                                <a
                                    class="btn-surtir"
                                    href="salidas.php?resurtido_id=<?=
                                        (int) $resurtido['id']
                                    ?>"
                                >
                                    Surtir
                                </a>

                            <?php endif; ?>

                        </div>

                    </article>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </section>

</main>

<script>
    const rolActual = <?= json_encode($rol) ?>;
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

    // ==================================================
    // BUSCAR PRODUCTOS
    // ==================================================

    botonBuscar?.addEventListener('click', buscarProducto);

    codigoInput?.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 4);
    });

    codigoInput?.addEventListener('keydown', function (evento) {
        if (evento.key === 'Enter') {
            evento.preventDefault();
            buscarProducto();
        }
    });

    async function buscarProducto() {
        const codigo = codigoInput.value.trim();

        if (!/^\d{4}$/.test(codigo)) {
            alert('Ingrese exactamente los últimos 4 dígitos.');
            codigoInput.focus();
            return;
        }

        resultados.innerHTML = 'Buscando producto...';

        try {
            const respuesta = await fetch(
                'resurtidos.php?action=buscar_producto&codigo='
                + encodeURIComponent(codigo)
            );

            const resultado = await respuesta.json();

            if (!resultado.ok) {
                throw new Error(resultado.mensaje);
            }

            mostrarResultados(
                resultado.datos.productos
            );
        } catch (error) {
            resultados.innerHTML =
                '<div class="alerta alerta-error">'
                + escaparHtml(error.message)
                + '</div>';
        }
    }

    function mostrarResultados(productos) {
        resultados.innerHTML = '';

        if (!productos.length) {
            resultados.innerHTML =
                '<div class="lista-vacia">'
                + 'No se encontraron productos.'
                + '</div>';

            return;
        }

        productos.forEach(function (producto) {
            const elemento = document.createElement('button');

            elemento.type = 'button';
            elemento.className = 'resultado-producto';

            elemento.innerHTML =
                '<strong>'
                + escaparHtml(producto.descripcion)
                + '</strong>'
                + '<span>Código: '
                + escaparHtml(producto.codigo)
                + '</span>';

            elemento.addEventListener('click', function () {
                agregarProducto(producto);
            });

            resultados.appendChild(elemento);
        });
    }

    // ==================================================
    // AGREGAR PRODUCTOS
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

        codigoInput.value = '';
        resultados.innerHTML = '';

        renderizarProductos();
        codigoInput.focus();
    }

    function renderizarProductos() {
        contenedorProductos.innerHTML = '';

        productosSolicitud.forEach(function (producto, indice) {
            const fila = document.createElement('div');

            fila.className = 'producto-agregado';

            fila.innerHTML = `
                <div class="producto-datos">
                    <strong>
                        ${escaparHtml(producto.descripcion)}
                    </strong>

                    <span>
                        Código: ${escaparHtml(producto.codigo)}
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
        });

        document
            .querySelectorAll('.cantidad-producto')
            .forEach(function (input) {
                input.addEventListener('change', function () {
                    const indice = Number(this.dataset.indice);
                    const cantidad = Number(this.value);

                    productosSolicitud[indice].cantidad =
                        cantidad > 0 ? cantidad : 1;

                    this.value =
                        productosSolicitud[indice].cantidad;
                });
            });

        document
            .querySelectorAll('[data-eliminar]')
            .forEach(function (boton) {
                boton.addEventListener('click', function () {
                    const indice = Number(
                        this.dataset.eliminar
                    );

                    productosSolicitud.splice(indice, 1);
                    renderizarProductos();
                });
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
            alert('Agregue por lo menos un producto.');
            return;
        }

        const confirmar = confirm(
            '¿Desea enviar esta solicitud de resurtido?'
        );

        if (!confirmar) {
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
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        observaciones:
                            document
                                .getElementById('observaciones')
                                .value
                                .trim(),

                        productos: productosSolicitud
                    })
                }
            );

            const resultado = await respuesta.json();

            if (!resultado.ok) {
                throw new Error(resultado.mensaje);
            }

            alert(resultado.mensaje);
            window.location.href = 'resurtidos.php';
        } catch (error) {
            alert(error.message);

            botonGuardar.disabled = false;
            botonGuardar.textContent = 'Enviar solicitud';
        }
    }

    // ==================================================
    // CONSULTAR NOTIFICACIONES
    // ==================================================

    if (
        rolActual === 'ENCARGADO'
        || rolActual === 'ADMINISTRADOR'
    ) {
        consultarNotificaciones();

        setInterval(
            consultarNotificaciones,
            30000
        );
    }

    async function consultarNotificaciones() {
        try {
            const respuesta = await fetch(
                'resurtidos.php?action=notificaciones'
            );

            const resultado = await respuesta.json();

            if (!resultado.ok) {
                return;
            }

            const cantidad = resultado.datos.cantidad;

            // Después conectaremos este valor con la campana
            // del encabezado del sistema.
            console.log(
                'Resurtidos pendientes:',
                cantidad
            );
        } catch (error) {
            console.error(
                'No se pudieron consultar las notificaciones.'
            );
        }
    }

    function escaparHtml(valor) {
        const elemento = document.createElement('div');
        elemento.textContent = String(valor ?? '');

        return elemento.innerHTML;
    }
</script>

<?php require __DIR__ . '/../app/views/layouts/footer.php'; ?>

</body>
</html>