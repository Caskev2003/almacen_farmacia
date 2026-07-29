<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/controllers/DevolucionController.php';

requireLogin();

date_default_timezone_set('America/Mexico_City');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$user = currentUser();
$rol = strtoupper(trim((string) ($user['rol'] ?? '')));
$usuarioId = (int) ($user['id'] ?? 0);
$almacenId = (int) ($user['almacen_id'] ?? 0);

$rolesPermitidos = [
    'ADMINISTRADOR',
    'ENCARGADO',
    'GERENTE',
];

if (!in_array($rol, $rolesPermitidos, true)) {
    http_response_code(403);
    exit('Acceso denegado.');
}

$esAdmin = $rol === 'ADMINISTRADOR';
$puedeModificar = in_array(
    $rol,
    ['ADMINISTRADOR', 'ENCARGADO'],
    true
);

if (!$esAdmin && $almacenId <= 0) {
    http_response_code(403);
    exit('Su cuenta no tiene un almacén asignado.');
}

$almacenLimite = $esAdmin ? null : $almacenId;

if (empty($_SESSION['csrf_devoluciones'])) {
    $_SESSION['csrf_devoluciones'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = (string) $_SESSION['csrf_devoluciones'];
$controller = new DevolucionController();
$action = trim((string) ($_GET['action'] ?? ''));

function responderJsonDevoluciones(
    bool $ok,
    string $mensaje = '',
    array $datos = [],
    int $codigoHttp = 200
): void {
    http_response_code($codigoHttp);
    header('Content-Type: application/json; charset=utf-8');
    header(
        'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
    );

    echo json_encode(
        [
            'ok' => $ok,
            'mensaje' => $mensaje,
            'datos' => $datos,
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function leerJsonDevoluciones(): array
{
    $contenido = file_get_contents('php://input');
    $datos = json_decode($contenido ?: '', true);

    if (!is_array($datos)) {
        responderJsonDevoluciones(
            false,
            'Los datos enviados no son válidos.',
            [],
            422
        );
    }

    return $datos;
}

function validarCsrfDevoluciones(array $datos): void
{
    $recibido = (string) ($datos['csrf_token'] ?? '');
    $sesion = (string) ($_SESSION['csrf_devoluciones'] ?? '');

    if (
        $recibido === ''
        || $sesion === ''
        || !hash_equals($sesion, $recibido)
    ) {
        responderJsonDevoluciones(
            false,
            'La sesión de seguridad venció. Recargue la página.',
            [],
            419
        );
    }
}

function filtrosDevolucionesDesdeSolicitud(): array
{
    return [
        'texto' => trim((string) ($_GET['texto'] ?? '')),
        'estatus' => trim((string) ($_GET['estatus'] ?? '')),
        'ubicacion' => trim((string) ($_GET['ubicacion'] ?? '')),
        'ticket' => trim((string) ($_GET['ticket'] ?? '')),
    ];
}

function huellaDevoluciones(array $registros): string
{
    $json = json_encode(
        $registros,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    return hash(
        'sha256',
        is_string($json) ? $json : ''
    );
}

if ($action !== '') {
    if ($action === 'buscar_productos') {
        if (!$puedeModificar) {
            responderJsonDevoluciones(
                false,
                'La cuenta de Gerente es únicamente de consulta.',
                [],
                403
            );
        }

        try {
            $productos = $controller->buscarProductos(
                trim((string) ($_GET['q'] ?? '')),
                $almacenId
            );

            responderJsonDevoluciones(
                true,
                count($productos) > 0
                    ? 'Productos encontrados.'
                    : 'No se encontraron productos.',
                [
                    'productos' => $productos,
                ]
            );
        } catch (InvalidArgumentException $e) {
            responderJsonDevoluciones(
                false,
                $e->getMessage(),
                [],
                422
            );
        } catch (Throwable $e) {
            error_log(
                'Error al buscar productos en devoluciones: '
                . $e->getMessage()
            );

            responderJsonDevoluciones(
                false,
                'No fue posible buscar productos.',
                [],
                500
            );
        }
    }

    if ($action === 'actualizaciones') {
        try {
            $registros = $controller->listar(
                $almacenLimite,
                filtrosDevolucionesDesdeSolicitud()
            );
            $huellaActual = huellaDevoluciones($registros);
            $huellaCliente = trim(
                (string) ($_GET['huella'] ?? '')
            );
            $cambio = $huellaCliente === ''
                || !hash_equals($huellaActual, $huellaCliente);

            responderJsonDevoluciones(
                true,
                $cambio ? 'La tabla cambió.' : 'Sin cambios.',
                [
                    'cambio' => $cambio,
                    'huella' => $huellaActual,
                    'registros' => $cambio ? $registros : [],
                    'consultado_en' => date('c'),
                ]
            );
        } catch (Throwable $e) {
            error_log(
                'Error al actualizar devoluciones: '
                . $e->getMessage()
            );

            responderJsonDevoluciones(
                false,
                'No fue posible actualizar la tabla.',
                [],
                500
            );
        }
    }

    if (
        in_array(
            $action,
            ['guardar', 'actualizar', 'ticket'],
            true
        )
    ) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responderJsonDevoluciones(
                false,
                'Método no permitido.',
                [],
                405
            );
        }

        if (!$puedeModificar) {
            responderJsonDevoluciones(
                false,
                'La cuenta de Gerente es únicamente de consulta.',
                [],
                403
            );
        }

        $datos = leerJsonDevoluciones();
        validarCsrfDevoluciones($datos);

        try {
            if ($action === 'ticket') {
                $id = (int) ($datos['id'] ?? 0);
                $tieneTicket = filter_var(
                    $datos['tiene_ticket'] ?? false,
                    FILTER_VALIDATE_BOOL
                );

                $registro = $controller->marcarTicket(
                    $id,
                    $tieneTicket,
                    $usuarioId,
                    $almacenLimite
                );

                responderJsonDevoluciones(
                    true,
                    $tieneTicket
                        ? 'Ticket marcado. La devolución sigue pendiente.'
                        : 'Se quitó la marca de ticket.',
                    [
                        'registro' => $registro,
                    ]
                );
            }

            if (!$esAdmin) {
                $datos['almacen_id'] = $almacenId;
            }

            if ($action === 'guardar') {
                $registro = $controller->crear(
                    $datos,
                    $usuarioId
                );

                responderJsonDevoluciones(
                    true,
                    'La devolución se agregó correctamente.',
                    [
                        'registro' => $registro,
                    ]
                );
            }

            $id = (int) ($datos['id'] ?? 0);
            $registro = $controller->actualizar(
                $id,
                $datos,
                $usuarioId,
                $almacenLimite
            );

            responderJsonDevoluciones(
                true,
                'La devolución se actualizó correctamente.',
                [
                    'registro' => $registro,
                ]
            );
        } catch (InvalidArgumentException $e) {
            responderJsonDevoluciones(
                false,
                $e->getMessage(),
                [],
                422
            );
        } catch (RuntimeException $e) {
            responderJsonDevoluciones(
                false,
                $e->getMessage(),
                [],
                404
            );
        } catch (Throwable $e) {
            error_log(
                'Error en acción de devoluciones: '
                . $e->getMessage()
            );

            responderJsonDevoluciones(
                false,
                'No fue posible guardar el cambio.',
                [],
                500
            );
        }
    }

    responderJsonDevoluciones(
        false,
        'Acción no reconocida.',
        [],
        404
    );
}

try {
    $registrosIniciales = $controller->listar(
        $almacenLimite
    );
    $almacenes = $esAdmin
        ? $controller->obtenerAlmacenesActivos()
        : [];
} catch (Throwable $e) {
    error_log(
        'Error al abrir el módulo Devoluciones: '
        . $e->getMessage()
    );

    http_response_code(500);
    exit(
        'No fue posible abrir Devoluciones. '
        . 'Verifique que haya importado database/instalar_devoluciones.sql.'
    );
}

$huellaInicial = huellaDevoluciones(
    $registrosIniciales
);
$moduleCss = 'devoluciones';

require __DIR__ . '/../app/views/layouts/header.php';

$meses = [
    1 => 'Enero',
    2 => 'Febrero',
    3 => 'Marzo',
    4 => 'Abril',
    5 => 'Mayo',
    6 => 'Junio',
    7 => 'Julio',
    8 => 'Agosto',
    9 => 'Septiembre',
    10 => 'Octubre',
    11 => 'Noviembre',
    12 => 'Diciembre',
];

?>

<main class="main-content devoluciones-page">
    <section class="module-header devoluciones-header">
        <div>
            <p class="devoluciones-eyebrow">Control por sucursal</p>
            <h2>Devoluciones</h2>
            <p>
                Registro y seguimiento de productos que deben devolverse.
            </p>
        </div>

        <div class="actualizacion-viva" id="estadoActualizacion">
            <span class="punto-vivo" aria-hidden="true"></span>
            Actualización automática
        </div>
    </section>



    <div
        id="mensajeDevoluciones"
        class="mensaje-devoluciones"
        role="status"
        aria-live="polite"
        hidden
    ></div>

    <?php if ($puedeModificar): ?>
        <section class="devolucion-card captura-card">
            <div class="card-title-row">
                <div>
                    <span class="paso-numero">1</span>
                    <div>
                        <h3>Buscar producto</h3>
                        <p>
                            Busque por código, código de barras o descripción.
                        </p>
                    </div>
                </div>
            </div>

            <div class="busqueda-producto">
                <label for="busquedaProducto">
                    Producto
                </label>

                <div class="busqueda-controles">
                    <input
                        type="search"
                        id="busquedaProducto"
                        placeholder="Ej. 750100, paracetamol..."
                        autocomplete="off"
                    >

                    <button
                        type="button"
                        class="btn-devolucion btn-buscar"
                        id="btnBuscarProducto"
                    >
                        Buscar
                    </button>
                </div>
            </div>

            <div
                id="resultadosProducto"
                class="resultados-producto"
                hidden
            ></div>
        </section>

        <section
            class="devolucion-card captura-card formulario-card"
            id="seccionFormulario"
            hidden
        >
            <div class="card-title-row">
                <div>
                    <span class="paso-numero">2</span>
                    <div>
                        <h3 id="tituloFormulario">
                            Agregar devolución
                        </h3>
                        <p>
                            Complete la información del producto seleccionado.
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    class="btn-link-cancelar"
                    id="btnCancelarEdicion"
                    hidden
                >
                    Cancelar edición
                </button>
            </div>

            <form id="formDevolucion" novalidate>
                <input type="hidden" id="devolucionId">
                <input type="hidden" id="productoId">

                <div class="producto-seleccionado">
                    <div>
                        <span class="dato-label">Código</span>
                        <strong id="productoCodigo">—</strong>
                    </div>
                    <div>
                        <span class="dato-label">Descripción</span>
                        <strong id="productoDescripcion">—</strong>
                    </div>
                </div>

                <div class="form-devolucion-grid">
                    <div class="campo-devolucion">
                        <label for="piezas">Piezas</label>
                        <input
                            type="number"
                            id="piezas"
                            min="1"
                            step="1"
                            required
                        >
                    </div>

                    <div class="campo-devolucion">
                        <label for="anio">Año</label>
                        <input
                            type="number"
                            id="anio"
                            min="2000"
                            max="2200"
                            value="<?= (int) date('Y') ?>"
                            required
                        >
                    </div>

                    <div class="campo-devolucion">
                        <label for="mes">Mes</label>
                        <select id="mes" required>
                            <?php foreach ($meses as $numero => $nombre): ?>
                                <option
                                    value="<?= (int) $numero ?>"
                                    <?= (int) date('n') === $numero
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e($nombre) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="campo-devolucion">
                        <label for="fecha">Fecha</label>
                        <input
                            type="date"
                            id="fecha"
                            value="<?= e(date('Y-m-d')) ?>"
                            required
                        >
                    </div>

                    <div class="campo-devolucion">
                        <label for="ubicacion">Ubicación</label>
                        <select id="ubicacion" required>
                            <option value="FARMACIA">Farmacia</option>
                            <option value="BODEGA">Bodega</option>
                        </select>
                    </div>

                    <div class="campo-devolucion">
                        <label for="estatus">Status</label>
                        <select id="estatus" required>
                            <option value="PENDIENTE">Pendiente</option>
                            <option value="EN_PROCESO">En proceso</option>
                            <option value="DEVUELTO">Devuelto</option>
                            <option value="CANCELADO">Cancelado</option>
                        </select>
                    </div>

                    <?php if ($esAdmin): ?>
                        <div class="campo-devolucion campo-almacen">
                            <label for="almacenRegistro">
                                Sucursal / almacén
                            </label>
                            <select id="almacenRegistro" required>
                                <option value="">Seleccione</option>
                                <?php foreach ($almacenes as $almacen): ?>
                                    <option
                                        value="<?= (int) $almacen['id'] ?>"
                                    >
                                        <?= e((string) $almacen['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="campo-devolucion campo-motivo">
                        <label for="motivo">Motivo</label>
                        <input
                            type="text"
                            id="motivo"
                            maxlength="255"
                            placeholder="Ej. caducidad, daño, producto incorrecto..."
                            required
                        >
                    </div>

                    <div class="campo-devolucion campo-observaciones">
                        <label for="observaciones">Observaciones</label>
                        <textarea
                            id="observaciones"
                            maxlength="3000"
                            rows="3"
                            placeholder="Información adicional (opcional)"
                        ></textarea>
                    </div>
                </div>

                <div class="acciones-formulario">
                    <button
                        type="submit"
                        class="btn-devolucion btn-guardar"
                        id="btnGuardarDevolucion"
                    >
                        Agregar a la tabla
                    </button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="devolucion-card tabla-card">
        <div class="tabla-encabezado">
            <div>
                <h3>Tabla de devoluciones</h3>
                <p>
                    Las filas amarillas ya cuentan con ticket, aunque su
                    status puede continuar como pendiente.
                </p>
            </div>

            <span class="contador-registros" id="contadorRegistros">
                0 registros
            </span>
        </div>

        <div class="filtros-devoluciones">
            <div class="filtro-texto">
                <label for="filtroTexto">Buscar en la tabla</label>
                <input
                    type="search"
                    id="filtroTexto"
                    placeholder="Código, descripción, motivo..."
                >
            </div>

            <div>
                <label for="filtroEstatus">Status</label>
                <select id="filtroEstatus">
                    <option value="">Todos</option>
                    <option value="PENDIENTE">Pendiente</option>
                    <option value="EN_PROCESO">En proceso</option>
                    <option value="DEVUELTO">Devuelto</option>
                    <option value="CANCELADO">Cancelado</option>
                </select>
            </div>

            <div>
                <label for="filtroUbicacion">Ubicación</label>
                <select id="filtroUbicacion">
                    <option value="">Todas</option>
                    <option value="FARMACIA">Farmacia</option>
                    <option value="BODEGA">Bodega</option>
                </select>
            </div>

            <div>
                <label for="filtroTicket">Ticket</label>
                <select id="filtroTicket">
                    <option value="">Todos</option>
                    <option value="CON">Con ticket</option>
                    <option value="SIN">Sin ticket</option>
                </select>
            </div>
        </div>

        <div
            class="tabla-devoluciones-wrap"
            id="tablaDevoluciones"
        ></div>
    </section>
<script>
    (function () {
        'use strict';

        const configuracion = <?= json_encode(
            [
                'csrfToken' => $csrfToken,
                'puedeModificar' => $puedeModificar,
                'esAdmin' => $esAdmin,
                'almacenId' => $almacenId,
                'anioActual' => (int) date('Y'),
                'mesActual' => (int) date('n'),
                'fechaActual' => date('Y-m-d'),
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        ) ?>;

        let registros = <?= json_encode(
            $registrosIniciales,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        ) ?>;
        let huellaActual = <?= json_encode($huellaInicial) ?>;
        let editandoId = 0;
        let actualizando = false;
        let temporizadorFiltro = null;

        const meses = [
            '',
            'Enero',
            'Febrero',
            'Marzo',
            'Abril',
            'Mayo',
            'Junio',
            'Julio',
            'Agosto',
            'Septiembre',
            'Octubre',
            'Noviembre',
            'Diciembre'
        ];

        const tabla = document.getElementById('tablaDevoluciones');
        const contador = document.getElementById('contadorRegistros');
        const mensaje = document.getElementById('mensajeDevoluciones');
        const estadoActualizacion =
            document.getElementById('estadoActualizacion');
        const filtroTexto = document.getElementById('filtroTexto');
        const filtroEstatus = document.getElementById('filtroEstatus');
        const filtroUbicacion =
            document.getElementById('filtroUbicacion');
        const filtroTicket = document.getElementById('filtroTicket');

        renderizarTabla();

        if (configuracion.puedeModificar) {
            prepararCaptura();
        }

        prepararFiltros();
        prepararTabla();

        window.setInterval(function () {
            if (!document.hidden) {
                cargarRegistros(false);
            }
        }, 3000);

        function prepararCaptura() {
            const buscador = document.getElementById('busquedaProducto');
            const botonBuscar =
                document.getElementById('btnBuscarProducto');
            const formulario = document.getElementById('formDevolucion');
            const cancelar =
                document.getElementById('btnCancelarEdicion');

            botonBuscar.addEventListener('click', buscarProductos);

            buscador.addEventListener('keydown', function (evento) {
                if (evento.key === 'Enter') {
                    evento.preventDefault();
                    buscarProductos();
                }
            });

            document
                .getElementById('resultadosProducto')
                .addEventListener('click', function (evento) {
                    const boton = evento.target.closest(
                        '[data-seleccionar-producto]'
                    );

                    if (!boton) {
                        return;
                    }

                    seleccionarProducto({
                        id: boton.dataset.id,
                        codigo: boton.dataset.codigo,
                        descripcion: boton.dataset.descripcion
                    });
                });

            formulario.addEventListener(
                'submit',
                guardarDevolucion
            );

            cancelar.addEventListener(
                'click',
                limpiarFormulario
            );
        }

        function prepararFiltros() {
            filtroTexto.addEventListener('input', function () {
                window.clearTimeout(temporizadorFiltro);
                temporizadorFiltro = window.setTimeout(
                    function () {
                        huellaActual = '';
                        cargarRegistros(true);
                    },
                    350
                );
            });

            [
                filtroEstatus,
                filtroUbicacion,
                filtroTicket
            ].forEach(function (control) {
                control.addEventListener('change', function () {
                    huellaActual = '';
                    cargarRegistros(true);
                });
            });
        }

        function prepararTabla() {
            tabla.addEventListener('click', function (evento) {
                const editar = evento.target.closest('[data-editar]');

                if (editar && configuracion.puedeModificar) {
                    iniciarEdicion(Number(editar.dataset.editar));
                }
            });

            tabla.addEventListener('change', function (evento) {
                const check = evento.target.closest(
                    '[data-ticket-devolucion]'
                );

                if (check && configuracion.puedeModificar) {
                    cambiarTicket(check);
                }
            });
        }

        async function buscarProductos() {
            const buscador = document.getElementById('busquedaProducto');
            const resultados =
                document.getElementById('resultadosProducto');
            const termino = buscador.value.trim();

            if (termino.length < 2) {
                mostrarMensaje(
                    'Escriba por lo menos 2 caracteres para buscar.',
                    'error'
                );
                buscador.focus();
                return;
            }

            resultados.hidden = false;
            resultados.innerHTML =
                '<div class="resultado-cargando">Buscando...</div>';

            try {
                const respuesta = await fetchJson(
                    'devoluciones.php?action=buscar_productos&q='
                    + encodeURIComponent(termino)
                );

                const productos = respuesta.datos?.productos ?? [];
                renderizarResultados(productos);
            } catch (error) {
                resultados.innerHTML =
                    '<div class="resultado-vacio">'
                    + escaparHtml(error.message)
                    + '</div>';
                mostrarMensaje(error.message, 'error');
            }
        }

        function renderizarResultados(productos) {
            const contenedor =
                document.getElementById('resultadosProducto');

            if (!Array.isArray(productos) || productos.length === 0) {
                contenedor.innerHTML =
                    '<div class="resultado-vacio">'
                    + 'No se encontraron productos con esa búsqueda.'
                    + '</div>';
                return;
            }

            contenedor.innerHTML = productos.map(function (producto) {
                const codigoBarras = producto.codigo_barras
                    ? '<span>Código de barras: '
                        + escaparHtml(producto.codigo_barras)
                        + '</span>'
                    : '';

                return ''
                    + '<article class="resultado-item">'
                    + '  <div>'
                    + '    <strong>'
                    + escaparHtml(producto.codigo)
                    + '</strong>'
                    + '    <p>'
                    + escaparHtml(producto.descripcion)
                    + '</p>'
                    + codigoBarras
                    + '  </div>'
                    + '  <button type="button"'
                    + '    class="btn-agregar-producto"'
                    + '    data-seleccionar-producto'
                    + '    data-id="' + Number(producto.id) + '"'
                    + '    data-codigo="'
                    + escaparAtributo(producto.codigo)
                    + '"'
                    + '    data-descripcion="'
                    + escaparAtributo(producto.descripcion)
                    + '">Agregar</button>'
                    + '</article>';
            }).join('');
        }

        function seleccionarProducto(producto) {
            document.getElementById('productoId').value =
                String(producto.id ?? '');
            document.getElementById('productoCodigo').textContent =
                producto.codigo ?? '—';
            document.getElementById('productoDescripcion').textContent =
                producto.descripcion ?? '—';

            const seccion = document.getElementById('seccionFormulario');
            seccion.hidden = false;
            seccion.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });

            document.getElementById('piezas').focus();
        }

        async function guardarDevolucion(evento) {
            evento.preventDefault();

            const productoId = Number(
                document.getElementById('productoId').value
            );

            if (!productoId) {
                mostrarMensaje(
                    'Primero seleccione un producto de la búsqueda.',
                    'error'
                );
                return;
            }

            const boton =
                document.getElementById('btnGuardarDevolucion');
            const accion = editandoId > 0
                ? 'actualizar'
                : 'guardar';

            const datos = {
                csrf_token: configuracion.csrfToken,
                id: editandoId,
                producto_id: productoId,
                piezas: document.getElementById('piezas').value,
                anio: document.getElementById('anio').value,
                mes: document.getElementById('mes').value,
                motivo: document.getElementById('motivo').value,
                estatus: document.getElementById('estatus').value,
                fecha: document.getElementById('fecha').value,
                ubicacion: document.getElementById('ubicacion').value,
                observaciones:
                    document.getElementById('observaciones').value,
                almacen_id: configuracion.esAdmin
                    ? document.getElementById('almacenRegistro').value
                    : configuracion.almacenId
            };

            boton.disabled = true;
            boton.textContent = editandoId > 0
                ? 'Guardando cambios...'
                : 'Agregando...';

            try {
                const respuesta = await fetchJson(
                    'devoluciones.php?action=' + accion,
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify(datos)
                    }
                );

                mostrarMensaje(respuesta.mensaje, 'exito');
                limpiarFormulario();
                huellaActual = '';
                await cargarRegistros(true);
            } catch (error) {
                mostrarMensaje(error.message, 'error');
            } finally {
                boton.disabled = false;
                boton.textContent = editandoId > 0
                    ? 'Guardar cambios'
                    : 'Agregar a la tabla';
            }
        }

        function iniciarEdicion(id) {
            const registro = registros.find(function (item) {
                return Number(item.id) === id;
            });

            if (!registro) {
                mostrarMensaje(
                    'La devolución ya no está disponible.',
                    'error'
                );
                return;
            }

            editandoId = id;
            seleccionarProducto({
                id: registro.producto_id,
                codigo: registro.codigo,
                descripcion: registro.descripcion
            });

            document.getElementById('devolucionId').value = String(id);
            document.getElementById('piezas').value = registro.piezas;
            document.getElementById('anio').value = registro.anio;
            document.getElementById('mes').value = registro.mes;
            document.getElementById('motivo').value = registro.motivo;
            document.getElementById('estatus').value = registro.estatus;
            document.getElementById('fecha').value = registro.fecha;
            document.getElementById('ubicacion').value =
                registro.ubicacion;
            document.getElementById('observaciones').value =
                registro.observaciones ?? '';

            if (configuracion.esAdmin) {
                document.getElementById('almacenRegistro').value =
                    registro.almacen_id;
            }

            document.getElementById('tituloFormulario').textContent =
                'Editar devolución';
            document.getElementById('btnGuardarDevolucion').textContent =
                'Guardar cambios';
            document.getElementById('btnCancelarEdicion').hidden = false;
        }

        function limpiarFormulario() {
            if (!configuracion.puedeModificar) {
                return;
            }

            editandoId = 0;
            document.getElementById('formDevolucion').reset();
            document.getElementById('devolucionId').value = '';
            document.getElementById('productoId').value = '';
            document.getElementById('productoCodigo').textContent = '—';
            document.getElementById('productoDescripcion').textContent =
                '—';
            document.getElementById('anio').value =
                configuracion.anioActual;
            document.getElementById('mes').value =
                configuracion.mesActual;
            document.getElementById('fecha').value =
                configuracion.fechaActual;
            document.getElementById('estatus').value = 'PENDIENTE';
            document.getElementById('ubicacion').value = 'FARMACIA';
            document.getElementById('tituloFormulario').textContent =
                'Agregar devolución';
            document.getElementById('btnGuardarDevolucion').textContent =
                'Agregar a la tabla';
            document.getElementById('btnCancelarEdicion').hidden = true;
            document.getElementById('seccionFormulario').hidden = true;
            document.getElementById('resultadosProducto').hidden = true;
            document.getElementById('busquedaProducto').value = '';
        }

        async function cambiarTicket(check) {
            const id = Number(check.dataset.ticketDevolucion);
            const marcado = check.checked;
            const fila = check.closest('tr');
            const textoTicket = check
                .closest('.ticket-check')
                ?.querySelector('small');
            const registro = registros.find(function (item) {
                return Number(item.id) === id;
            });

            fila?.classList.toggle('fila-con-ticket', marcado);
            if (textoTicket) {
                textoTicket.textContent = marcado ? 'Sí' : 'No';
            }
            check.disabled = true;

            if (registro) {
                registro.tiene_ticket = marcado ? 1 : 0;
            }

            try {
                const respuesta = await fetchJson(
                    'devoluciones.php?action=ticket',
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            csrf_token: configuracion.csrfToken,
                            id: id,
                            tiene_ticket: marcado
                        })
                    }
                );

                mostrarMensaje(respuesta.mensaje, 'exito');
                huellaActual = '';
            } catch (error) {
                check.checked = !marcado;
                fila?.classList.toggle('fila-con-ticket', !marcado);
                if (textoTicket) {
                    textoTicket.textContent = marcado ? 'No' : 'Sí';
                }

                if (registro) {
                    registro.tiene_ticket = marcado ? 0 : 1;
                }

                mostrarMensaje(error.message, 'error');
            } finally {
                check.disabled = false;
            }
        }

        async function cargarRegistros(forzar) {
            if (actualizando) {
                return;
            }

            actualizando = true;
            estadoActualizacion?.classList.add('consultando');

            const parametros = new URLSearchParams({
                action: 'actualizaciones',
                huella: forzar ? '' : huellaActual,
                texto: filtroTexto.value.trim(),
                estatus: filtroEstatus.value,
                ubicacion: filtroUbicacion.value,
                ticket: filtroTicket.value
            });

            try {
                const respuesta = await fetchJson(
                    'devoluciones.php?' + parametros.toString()
                );

                huellaActual = respuesta.datos?.huella ?? huellaActual;

                if (respuesta.datos?.cambio) {
                    registros = respuesta.datos?.registros ?? [];
                    renderizarTabla();
                }
            } catch (error) {
                console.warn(
                    'No fue posible actualizar Devoluciones.',
                    error
                );
            } finally {
                actualizando = false;
                estadoActualizacion?.classList.remove('consultando');
            }
        }

        function renderizarTabla() {
            contador.textContent = registros.length === 1
                ? '1 registro'
                : registros.length + ' registros';

            if (!Array.isArray(registros) || registros.length === 0) {
                tabla.innerHTML =
                    '<div class="tabla-vacia">'
                    + '  <span aria-hidden="true">↩</span>'
                    + '  <strong>No hay devoluciones para mostrar</strong>'
                    + '  <p>Agregue un producto o cambie los filtros.</p>'
                    + '</div>';
                return;
            }

            const filas = registros.map(function (registro) {
                const tieneTicket =
                    Number(registro.tiene_ticket) === 1;
                const ticket = ''
                    + '<label class="ticket-check">'
                    + '  <input type="checkbox"'
                    + '    data-ticket-devolucion="'
                    + Number(registro.id)
                    + '"'
                    + (tieneTicket ? ' checked' : '')
                    + (configuracion.puedeModificar ? '' : ' disabled')
                    + '>'
                    + '  <span aria-hidden="true"></span>'
                    + '  <small>'
                    + (tieneTicket ? 'Sí' : 'No')
                    + '</small>'
                    + '</label>';

                const accion = configuracion.puedeModificar
                    ? '<button type="button" class="btn-editar-fila"'
                        + ' data-editar="' + Number(registro.id) + '">'
                        + 'Editar</button>'
                    : '<span class="solo-lectura">Consulta</span>';

                return ''
                    + '<tr class="'
                    + (tieneTicket ? 'fila-con-ticket' : '')
                    + '">'
                    + '<td><strong class="codigo-tabla">'
                    + escaparHtml(registro.codigo)
                    + '</strong></td>'
                    + '<td class="descripcion-tabla">'
                    + escaparHtml(registro.descripcion)
                    + '</td>'
                    + '<td class="numero-tabla">'
                    + formatearNumero(registro.piezas)
                    + '</td>'
                    + '<td>' + escaparHtml(registro.anio) + '</td>'
                    + '<td>'
                    + escaparHtml(meses[Number(registro.mes)] ?? registro.mes)
                    + '</td>'
                    + '<td>' + escaparHtml(registro.motivo) + '</td>'
                    + '<td>'
                    + '<span class="badge-status status-'
                    + escaparAtributo(
                        String(registro.estatus).toLowerCase()
                    )
                    + '">'
                    + escaparHtml(etiquetaEstatus(registro.estatus))
                    + '</span>'
                    + '</td>'
                    + '<td>' + formatearFecha(registro.fecha) + '</td>'
                    + '<td><span class="badge-ubicacion">'
                    + escaparHtml(capitalizar(registro.ubicacion))
                    + '</span></td>'
                    + '<td class="observaciones-tabla" title="'
                    + escaparAtributo(registro.observaciones ?? '')
                    + '">'
                    + escaparHtml(registro.observaciones || '—')
                    + '</td>'
                    + '<td>' + ticket + '</td>'
                    + (configuracion.esAdmin
                        ? '<td>'
                            + escaparHtml(registro.almacen_nombre ?? '—')
                            + '</td>'
                        : '')
                    + '<td>' + accion + '</td>'
                    + '</tr>';
            }).join('');

            tabla.innerHTML = ''
                + '<table class="tabla-devoluciones">'
                + '<thead><tr>'
                + '<th>Código</th>'
                + '<th>Descripción</th>'
                + '<th>Piezas</th>'
                + '<th>Año</th>'
                + '<th>Mes</th>'
                + '<th>Motivo</th>'
                + '<th>Status</th>'
                + '<th>Fecha</th>'
                + '<th>Ubicación</th>'
                + '<th>Observaciones</th>'
                + '<th>Ticket devolución</th>'
                + (configuracion.esAdmin ? '<th>Almacén</th>' : '')
                + '<th>Acción</th>'
                + '</tr></thead>'
                + '<tbody>' + filas + '</tbody>'
                + '</table>';
        }

        function mostrarMensaje(texto, tipo) {
            mensaje.textContent = texto;
            mensaje.className =
                'mensaje-devoluciones mensaje-' + tipo;
            mensaje.hidden = false;

            window.clearTimeout(mostrarMensaje.temporizador);
            mostrarMensaje.temporizador = window.setTimeout(
                function () {
                    mensaje.hidden = true;
                },
                5000
            );
        }

        async function fetchJson(url, opciones) {
            const respuesta = await fetch(
                url,
                Object.assign(
                    {
                        cache: 'no-store',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    },
                    opciones ?? {}
                )
            );

            let resultado;

            try {
                resultado = await respuesta.json();
            } catch (error) {
                throw new Error(
                    'El servidor devolvió una respuesta inesperada.'
                );
            }

            if (!respuesta.ok || !resultado.ok) {
                throw new Error(
                    resultado.mensaje || 'No fue posible completar la acción.'
                );
            }

            return resultado;
        }

        function etiquetaEstatus(estatus) {
            const etiquetas = {
                PENDIENTE: 'Pendiente',
                EN_PROCESO: 'En proceso',
                DEVUELTO: 'Devuelto',
                CANCELADO: 'Cancelado'
            };

            return etiquetas[estatus] ?? estatus;
        }

        function capitalizar(valor) {
            const texto = String(valor ?? '').toLowerCase();

            return texto
                ? texto.charAt(0).toUpperCase() + texto.slice(1)
                : '—';
        }

        function formatearFecha(valor) {
            const partes = String(valor ?? '').split('-');

            if (partes.length !== 3) {
                return escaparHtml(valor);
            }

            return escaparHtml(
                partes[2] + '/' + partes[1] + '/' + partes[0]
            );
        }

        function formatearNumero(valor) {
            const numero = Number(valor);

            return Number.isFinite(numero)
                ? numero.toLocaleString('es-MX')
                : escaparHtml(valor);
        }

        function escaparHtml(valor) {
            const elemento = document.createElement('div');
            elemento.textContent = String(valor ?? '');
            return elemento.innerHTML;
        }

        function escaparAtributo(valor) {
            return String(valor ?? '')
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/`/g, '&#96;');
        }
    })();
</script>

<?php

require __DIR__ . '/../app/views/layouts/footer.php';

?>
