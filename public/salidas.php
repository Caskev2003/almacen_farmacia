<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/SalidaController.php';
require_once __DIR__ . '/../app/controllers/ResurtidoController.php';

requireLogin();

date_default_timezone_set('America/Mexico_City');

$user = currentUser();
$controller = new SalidaController();

$message = '';
$messageType = 'danger';

/* =========================================================
   FUNCIONES AUXILIARES DEL MODULO
========================================================= */

if (!function_exists('salidasNormalizarTexto')) {
    function salidasNormalizarTexto(?string $texto): string
    {
        $texto = strtoupper(trim((string)$texto));

        $texto = strtr($texto, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'Ä' => 'A', 'Ë' => 'E', 'Ï' => 'I', 'Ö' => 'O', 'Ü' => 'U',
            'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
            'Ñ' => 'N'
        ]);

        return preg_replace('/\s+/', ' ', $texto) ?? '';
    }
}

if (!function_exists('salidasSugerirTipoSalida')) {
    /**
     * Intenta seleccionar automáticamente el tipo de salida
     * comparando el nombre de la tienda que solicitó el resurtido
     * contra las descripciones de los tipos de salida.
     */
    function salidasSugerirTipoSalida(string $almacenDestino, array $tiposSalida): string
    {
        $destino = salidasNormalizarTexto($almacenDestino);

        if ($destino === '') {
            return '';
        }

        $ignoradas = ['TIENDA', 'ALMACEN', 'SUCURSAL', 'BODEGA', 'MATRIZ', 'SALIDA'];

        $palabras = array_filter(
            explode(' ', $destino),
            static function ($palabra) use ($ignoradas) {
                return mb_strlen($palabra) > 3 && !in_array($palabra, $ignoradas, true);
            }
        );

        $mejorValor = '';
        $mejorPuntaje = 0;

        foreach ($tiposSalida as $tipo) {
            $descripcion = salidasNormalizarTexto($tipo['descripcion'] ?? '');

            if (strpos($descripcion, 'TIENDA') === false) {
                continue;
            }

            $puntaje = 0;

            foreach ($palabras as $palabra) {
                if (strpos($descripcion, $palabra) !== false) {
                    $puntaje++;
                }
            }

            if ($puntaje > $mejorPuntaje) {
                $mejorPuntaje = $puntaje;
                $mejorValor = $tipo['clave'] . ' - ' . $tipo['descripcion'];
            }
        }

        return $mejorValor;
    }
}

if (!function_exists('salidasCantidadesSurtidas')) {
    /**
     * Toma lo capturado en la salida y deja únicamente los productos
     * que sí pertenecen al resurtido, sumando cantidades repetidas.
     */
    function salidasCantidadesSurtidas(array $postData, array $detallesResurtido): array
    {
        $permitidos = [];

        foreach ($detallesResurtido as $detalle) {
            $permitidos[(int)($detalle['producto_id'] ?? 0)] = 0.0;
        }

        $productoIds = $postData['producto_id'] ?? [];
        $cantidades = $postData['cantidad'] ?? [];

        foreach ($productoIds as $indice => $productoId) {
            $productoId = (int)$productoId;

            if (!array_key_exists($productoId, $permitidos)) {
                continue;
            }

            $permitidos[$productoId] += (float)($cantidades[$indice] ?? 0);
        }

        $resultado = [];

        foreach ($permitidos as $productoId => $cantidad) {
            if ($cantidad > 0) {
                $resultado[] = [
                    'producto_id' => $productoId,
                    'cantidad_surtida' => $cantidad
                ];
            }
        }

        return $resultado;
    }
}

if (!function_exists('salidasValidarCantidadesPendientes')) {
    /**
     * Impide que una salida nueva descuente más unidades que las
     * que todavía están pendientes en el resurtido o ticket.
     */
    function salidasValidarCantidadesPendientes(
        array $postData,
        array $detallesResurtido
    ): string {
        $pendientes = [];

        foreach ($detallesResurtido as $detalle) {
            $productoId = (int) (
                $detalle['producto_id'] ?? 0
            );

            if ($productoId <= 0) {
                continue;
            }

            $solicitada = (float) (
                $detalle['cantidad_solicitada'] ?? 0
            );

            $surtida = (float) (
                $detalle['cantidad_surtida'] ?? 0
            );

            $pendientes[$productoId] = [
                'cantidad' => max(0, $solicitada - $surtida),
                'codigo' => (string) (
                    $detalle['codigo'] ?? ('#' . $productoId)
                )
            ];
        }

        $cantidadesCapturadas = salidasCantidadesSurtidas(
            $postData,
            $detallesResurtido
        );

        foreach ($cantidadesCapturadas as $producto) {
            $productoId = (int) (
                $producto['producto_id'] ?? 0
            );

            $cantidad = (float) (
                $producto['cantidad_surtida'] ?? 0
            );

            if (!isset($pendientes[$productoId])) {
                continue;
            }

            $pendiente = (float) (
                $pendientes[$productoId]['cantidad']
            );

            if ($cantidad > $pendiente) {
                return 'La cantidad del producto '
                    . $pendientes[$productoId]['codigo']
                    . ' supera lo que queda pendiente ('
                    . rtrim(
                        rtrim(
                            number_format(
                                $pendiente,
                                3,
                                '.',
                                ''
                            ),
                            '0'
                        ),
                        '.'
                    )
                    . ').';
            }
        }

        return '';
    }
}

/* =========================================================
   MODO EDICION
========================================================= */

$folioOperacionEditar = '';
$observacionesLimpiasEditar = '';
$editarId = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
$modoEdicion = $editarId > 0;
$salidaEditar = null;

if ($modoEdicion) {
    $salidaEditar = $controller->obtenerSalida($editarId);

    if (!$salidaEditar) {
        $message = 'La salida que intentas editar no existe.';
        $messageType = 'danger';
        $modoEdicion = false;
    } elseif ((int)($salidaEditar['cancelado'] ?? 0) === 1) {
        $message = 'No puedes editar una salida cancelada.';
        $messageType = 'danger';
        $modoEdicion = false;
    } else {
        $observacionesEditar = (string)($salidaEditar['observaciones'] ?? '');
        $observacionesLimpiasEditar = $observacionesEditar;

        $patterns = [
            '/Folio\s+ticket:\s*([^|]+)\|?(.*)/i',
            '/Folio\s+resurtido:\s*([^|]+)\|?(.*)/i',
            '/Folio\s+nota_remision:\s*([^|]+)\|?(.*)/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $observacionesEditar, $matches)) {
                $folioOperacionEditar = trim($matches[1]);
                $observacionesLimpiasEditar = trim($matches[2] ?? '');
                break;
            }
        }
    }
}

/* =========================================================
   RESURTIDO QUE SE VA A SURTIR
========================================================= */

$resurtidoId = (int)($_GET['resurtido_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resurtidoId = (int)($_POST['resurtido_id'] ?? $resurtidoId);
}

$resurtido = null;
$resurtidoController = null;
$resurtidoAviso = '';
$resurtidoAvisoTipo = 'warning';
$resurtidoBloqueado = false;

if ($resurtidoId > 0 && !$modoEdicion) {
    try {
        $resurtidoController = new ResurtidoController();
        $resurtido = $resurtidoController->obtenerPorId($resurtidoId);

        if ($resurtido) {
            $estadoAntesDeSincronizar = strtoupper(
                trim((string) ($resurtido['estado'] ?? ''))
            );

            if (
                in_array(
                    $estadoAntesDeSincronizar,
                    ['EN_PROCESO', 'PARCIAL'],
                    true
                )
                && !empty($resurtido['salida_id'])
            ) {
                $resurtidoController
                    ->sincronizarCantidadesSurtidas($resurtidoId);

                $resurtido = $resurtidoController
                    ->obtenerPorId($resurtidoId);
            }
        }

        if (!$resurtido) {
            $resurtido = null;
            $resurtidoAviso = 'No se encontró la solicitud de resurtido indicada.';
            $resurtidoAvisoTipo = 'danger';
            $resurtidoBloqueado = true;
        } else {
            $estadoResurtido = strtoupper(
                trim((string) ($resurtido['estado'] ?? ''))
            );

            if ($estadoResurtido === 'CANCELADO') {
                $resurtidoAviso = 'La solicitud ' . ($resurtido['folio'] ?? '') . ' está cancelada y no puede surtirse.';
                $resurtidoAvisoTipo = 'danger';
                $resurtidoBloqueado = true;
            } elseif ($estadoResurtido === 'SURTIDO') {
                $resurtidoAviso = 'La solicitud ' . ($resurtido['folio'] ?? '') . ' ya fue surtida y está vinculada a una salida anterior.';
                $resurtidoAvisoTipo = 'danger';
                $resurtidoBloqueado = true;
            } elseif (
                !empty($resurtido['salida_id'])
                && $estadoResurtido !== 'PARCIAL'
            ) {
                $resurtidoAviso = 'La solicitud ' . ($resurtido['folio'] ?? '') . ' ya está vinculada a una salida anterior.';
                $resurtidoAvisoTipo = 'danger';
                $resurtidoBloqueado = true;
            } elseif (
                !in_array(
                    $estadoResurtido,
                    ['PENDIENTE', 'EN_PROCESO', 'PARCIAL'],
                    true
                )
            ) {
                $resurtidoAviso = 'El estado de la solicitud ' . ($resurtido['folio'] ?? '') . ' no permite continuar el surtido.';
                $resurtidoAvisoTipo = 'danger';
                $resurtidoBloqueado = true;
            }
        }
    } catch (Throwable $e) {
        error_log('Error al cargar el resurtido en salidas: ' . $e->getMessage());

        $resurtido = null;
        $resurtidoAviso = 'No fue posible cargar la solicitud de resurtido.';
        $resurtidoAvisoTipo = 'danger';
        $resurtidoBloqueado = true;
    }
}

$modoResurtido = ($resurtido !== null && !$resurtidoBloqueado);
$tipoSolicitudOrigen = $resurtido !== null
    ? strtoupper(
        trim((string) ($resurtido['tipo_solicitud'] ?? 'RESURTIDO'))
    )
    : 'RESURTIDO';
$esSolicitudTicket =
    $tipoSolicitudOrigen === 'TICKET';
$nombreSolicitudOrigen = $esSolicitudTicket
    ? 'ticket'
    : 'resurtido';
$paginaSolicitudOrigen = $esSolicitudTicket
    ? 'tickets.php'
    : 'resurtidos.php';
$folioSolicitudOrigen = $esSolicitudTicket
    ? (string) (
        $resurtido['folio_documento']
        ?? $resurtido['folio']
        ?? ''
    )
    : (string) ($resurtido['folio'] ?? '');

/* =========================================================
   PROCESAR FORMULARIO
========================================================= */

$movimientoGuardadoId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editarPostId = (int)($_POST['editar_id'] ?? 0);

    $errorCantidadPendiente = '';

    if (
        $modoResurtido
        && $editarPostId <= 0
        && $resurtido !== null
    ) {
        $errorCantidadPendiente =
            salidasValidarCantidadesPendientes(
                $_POST,
                $resurtido['productos'] ?? []
            );
    }

    if ($errorCantidadPendiente !== '') {
        $result = [
            'success' => false,
            'message' => $errorCantidadPendiente
        ];
    } elseif ($editarPostId > 0) {
        $result = $controller->actualizar($editarPostId, $_POST, (int)$user['id']);
    } else {
        $result = $controller->guardar($_POST, (int)$user['id']);
    }

    if ($result['success']) {
        $movimientoId = (int)($result['movimiento_id'] ?? 0);
        $movimientoGuardadoId = $movimientoId;

        $errorVinculo = '';

        // ---- Vincular la salida con la solicitud de origen ----
        if ($modoResurtido && $movimientoId > 0 && $resurtidoController !== null) {
            try {
                $cantidadesSurtidas = salidasCantidadesSurtidas(
                    $_POST,
                    $resurtido['productos'] ?? []
                );

                if (empty($cantidadesSurtidas)) {
                    $errorVinculo = 'La salida se guardó, pero ningún producto capturado pertenece al '
                        . $nombreSolicitudOrigen . ' '
                        . $folioSolicitudOrigen
                        . ', por lo que no se marcó como surtido.';
                } else {
                    $resurtidoController->finalizarConSalida(
                        $resurtidoId,
                        $movimientoId,
                        (int)$user['id'],
                        $cantidadesSurtidas
                    );
                }
            } catch (Throwable $e) {
                error_log(
                    'Error al vincular salida con '
                    . $nombreSolicitudOrigen . ': '
                    . $e->getMessage()
                );

                $errorVinculo = 'La salida se guardó correctamente, pero no fue posible marcar el '
                    . $nombreSolicitudOrigen . ' como surtido: '
                    . $e->getMessage();
            }
        }

        if ($errorVinculo !== '') {
            $message = '⚠️ ' . $errorVinculo;
            $messageType = 'warning';
        } elseif ($movimientoId > 0) {
            header('Location: imprimir_salida.php?id=' . $movimientoId . '&preview=1');
            exit;
        } else {
            $message = '✅ La salida se guardó correctamente.';
            $messageType = 'success';
        }
    } else {
        $message = '❌ ' . ($result['message'] ?? 'Error al guardar la salida');
        $messageType = 'danger';
    }
}

/* =========================================================
   DATOS NECESARIOS PARA LA VISTA
========================================================= */

$almacenes = $controller->almacenes();
$productos = $controller->productos();
$productosPorId = [];

foreach ($productos as $productoTmp) {
    $productosPorId[(int)$productoTmp['id']] = $productoTmp;
}

$tiposSalida = $controller->tiposSalida();
$almacenSesion = (int)($user['almacen_id'] ?? 0);
$rolUsuario = strtoupper(trim($user['rol'] ?? ''));

$folio = $controller->generarFolio($almacenSesion);

if ($modoEdicion && $salidaEditar) {
    $folio = $salidaEditar['folio'];
}

$folioAnterior = $controller->ultimoFolioSalida($almacenSesion);

// FECHA AUTOMÁTICA
$fechaActual = date('Y-m-d\TH:i');

/* =========================================================
   PRECARGA DEL RESURTIDO
========================================================= */

$tipoOperacionSeleccionado = $modoEdicion
    ? (string)($salidaEditar['tipo_operacion'] ?? '')
    : ($modoResurtido ? $tipoSolicitudOrigen : '');

$folioOperacionValor = $modoEdicion
    ? $folioOperacionEditar
    : ($modoResurtido ? $folioSolicitudOrigen : '');

$tipoSalidaSeleccionado = $modoEdicion
    ? (string)($salidaEditar['referencia'] ?? '')
    : ($modoResurtido ? salidasSugerirTipoSalida((string)($resurtido['almacen_nombre'] ?? ''), $tiposSalida) : '');

$observacionesValor = $modoEdicion ? $observacionesLimpiasEditar : '';

/*
 * En una salida nueva, incluso cuando proviene de un
 * resurtido, el campo Observaciones debe aparecer vacío.
 *
 * Si el formulario fue enviado y ocurrió un error,
 * se conserva únicamente lo escrito por el usuario.
 */
if (
    !$modoEdicion
    && $_SERVER['REQUEST_METHOD'] === 'POST'
) {
    $observacionesValor = trim(
        (string) ($_POST['observaciones'] ?? '')
    );
}

/* ---------------------------------------------------------
   Si el guardado falló, se reconstruye lo que ya estaba
   capturado para que el usuario no lo pierda.
--------------------------------------------------------- */

$filasPrevias = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $message !== '' && $messageType !== 'success') {
    $idsPrevios = $_POST['producto_id'] ?? [];
    $cantidadesPrevias = $_POST['cantidad'] ?? [];
    $preciosPrevios = $_POST['precio_unitario'] ?? [];
    $ubicacionesPrevias = $_POST['ubicacion'] ?? [];

    foreach ($idsPrevios as $indice => $idPrevio) {
        $idPrevio = (int)$idPrevio;

        if ($idPrevio <= 0 || !isset($productosPorId[$idPrevio])) {
            continue;
        }

        $filasPrevias[] = [
            'producto_id' => $idPrevio,
            'cantidad' => max(1, (int)($cantidadesPrevias[$indice] ?? 1)),
            'precio' => (float)($preciosPrevios[$indice] ?? 0),
            'ubicacion' => strtoupper(trim((string)($ubicacionesPrevias[$indice] ?? '')))
        ];
    }
}

// Datos que se envían a JavaScript para llenar la tabla
$resurtidoJs = null;
$resurtidoSinRegistro = [];

if ($modoResurtido) {
    $productosResurtido = [];

    foreach (($resurtido['productos'] ?? []) as $detalle) {
        $productoId = (int)($detalle['producto_id'] ?? 0);

        $solicitada = (float)($detalle['cantidad_solicitada'] ?? 0);
        $surtida = (float)($detalle['cantidad_surtida'] ?? 0);
        $pendiente = max(0, (int)round($solicitada - $surtida));

        // Al continuar una solicitud parcial no se vuelven a cargar las
        // piezas que ya salieron; únicamente se muestran las pendientes.
        if ($pendiente <= 0) {
            continue;
        }

        $existeEnCatalogo = isset($productosPorId[$productoId]);

        if (!$existeEnCatalogo) {
            $resurtidoSinRegistro[] = [
                'codigo' => (string)($detalle['codigo'] ?? ''),
                'descripcion' => (string)($detalle['descripcion'] ?? ''),
                'motivo' => 'No está disponible en el catálogo de esta bodega.'
            ];
        }

        $productosResurtido[] = [
            'producto_id' => $productoId,
            'codigo' => (string)($detalle['codigo'] ?? ''),
            'descripcion' => (string)($detalle['descripcion'] ?? ''),
            'unidad' => (string)($detalle['unidad'] ?? ''),
            'solicitada' => (int)round($solicitada),
            'surtida' => (int)round($surtida),
            'pendiente' => $pendiente,
            'en_catalogo' => $existeEnCatalogo
        ];
    }

    $resurtidoJs = [
        'id' => (int)$resurtido['id'],
        'folio' => (string)($resurtido['folio'] ?? ''),
        'tipo_solicitud' => $tipoSolicitudOrigen,
        'folio_documento' => (
            $resurtido['folio_documento'] ?? null
        ),
        'almacen' => (string)($resurtido['almacen_nombre'] ?? ''),
        'verificador_solicitante' => (string) (
            $resurtido['verificador_nombre'] ?? ''
        ),
        'gerente_autorizo' => (string) (
            $resurtido['solicitante_nombre'] ?? ''
        ),
        'fecha' => (string)($resurtido['fecha_solicitud'] ?? ''),
        'estado' => (string)($resurtido['estado'] ?? ''),
        'observaciones' => (string)($resurtido['observaciones'] ?? ''),
        'productos' => $productosResurtido
    ];
}

$moduleCss = 'salidas';
include __DIR__ . '/../app/views/layouts/header.php';
?>

<!-- HEADER -->
<div class="page-header">
    <h1>
        <?php if ($modoEdicion): ?>
            ✏️ Editar Salida
        <?php elseif ($modoResurtido): ?>
            <?= $esSolicitudTicket ? '🎫' : '🔄' ?>
            Surtir <?= e($nombreSolicitudOrigen) ?>
            <?= e($folioSolicitudOrigen) ?>
        <?php else: ?>
            ➕ Nueva Salida de Almacén
        <?php endif; ?>
    </h1>
</div>

<div class="main-container">

<?php if ($message): ?>
    <div class="alert alert-<?= e($messageType) ?>">
        <?= e($message) ?>
        <?php if ($movimientoGuardadoId > 0): ?>
            <a class="alert-link" href="imprimir_salida.php?id=<?= (int)$movimientoGuardadoId ?>&preview=1">
                🖨️ Ver / imprimir la salida
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($resurtidoAviso !== ''): ?>
    <div class="alert alert-<?= e($resurtidoAvisoTipo) ?>">
        <?= e($resurtidoAviso) ?>
        <a class="alert-link" href="<?= e($paginaSolicitudOrigen) ?>">
            ↩️ Volver a <?= e($esSolicitudTicket ? 'tickets' : 'resurtidos') ?>
        </a>
    </div>
<?php endif; ?>

<?php if ($modoResurtido): ?>
    <!-- BANNER DE LA SOLICITUD -->
    <div class="resurtido-banner">
        <div class="resurtido-banner-top">
            <span class="resurtido-chip">
                <?= $esSolicitudTicket ? '🎫 Ticket' : '🔄 Resurtido' ?>
            </span>
            <strong class="resurtido-folio">
                <?= e($folioSolicitudOrigen) ?>
            </strong>
            <span class="resurtido-estado">
                <?= e(str_replace('_', ' ', strtoupper((string)($resurtido['estado'] ?? '')))) ?>
            </span>
        </div>

        <div class="resurtido-banner-datos">
            <div><span>🏪 Destino</span><strong><?= e($resurtido['almacen_nombre'] ?? '-') ?></strong></div>
            <div>
                <span>👤 Solicitó</span>
                <strong>
                    <?= e(
                        ($resurtido['verificador_nombre'] ?? '')
                        ?: 'Sin identificar'
                    ) ?>
                </strong>
            </div>
            <div>
                <span>✅ Autorizó</span>
                <strong>
                    <?= e(
                        ($resurtido['solicitante_nombre'] ?? '')
                        ?: 'Sin información'
                    ) ?>
                </strong>
            </div>
            <div><span>📅 Fecha</span><strong><?= e($resurtido['fecha_solicitud'] ?? '-') ?></strong></div>
            <div><span>📦 Productos</span><strong><?= (int)($resurtido['total_productos'] ?? 0) ?></strong></div>
            <div><span>📄 Folio de salida</span><strong><?= e($folio) ?></strong></div>
        </div>

        <?php if (!empty($resurtido['observaciones'])): ?>
            <div class="resurtido-banner-nota">
                📝 <?= e($resurtido['observaciones']) ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="POST" id="formSalida">
    <?php if ($modoEdicion && $salidaEditar): ?>
        <input type="hidden" name="editar_id" value="<?= (int)$salidaEditar['id'] ?>">
    <?php endif; ?>

    <?php if ($modoResurtido): ?>
        <input type="hidden" name="resurtido_id" value="<?= (int)$resurtido['id'] ?>">
    <?php endif; ?>

    <!-- SECCIÓN 1: DATOS DEL DOCUMENTO -->
    <div class="doc-section">
        <div class="form-grid">
            <div class="form-field">
                <label>📄 Folio</label>
                <input type="text" name="folio" value="<?= e($folio) ?>" readonly>
            </div>

            <div class="form-field">
                <label>📅 Fecha y hora</label>
                <input type="datetime-local"
                       name="fecha"
                       id="fechaInput"
                       required
                       value="<?= $modoEdicion && !empty($salidaEditar['fecha'])
                                  ? e(date('Y-m-d\TH:i', strtotime($salidaEditar['fecha'])))
                                  : $fechaActual ?>">
            </div>

            <div class="form-field">
                <label>📋 Tipo de salida</label>
                <select name="tipo_salida" id="tipoSalidaSelect" required>
                    <option value="">Seleccione...</option>
                    <?php foreach ($tiposSalida as $tipo): ?>
                        <?php $valorTipo = $tipo['clave'] . ' - ' . $tipo['descripcion']; ?>
                        <option value="<?= e($valorTipo) ?>"
                            <?= $tipoSalidaSeleccionado === $valorTipo ? 'selected' : '' ?>>
                            <?= e($tipo['clave']) ?> - <?= e($tipo['descripcion']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-field">
                <label>📑 Tipo de documento</label>
                <select
                    name="tipo_operacion"
                    id="tipoOperacionSelect"
                    required
                    <?= $modoResurtido ? 'disabled' : '' ?>
                >
                    <option value="">Seleccione...</option>
                    <option value="TICKET" <?= $tipoOperacionSeleccionado === 'TICKET' ? 'selected' : '' ?>>🎫 Ticket</option>
                    <option value="RESURTIDO" <?= $tipoOperacionSeleccionado === 'RESURTIDO' ? 'selected' : '' ?>>🔄 Resurtido</option>
                    <option value="AJUSTE" <?= $tipoOperacionSeleccionado === 'AJUSTE' ? 'selected' : '' ?>>⚙️ Ajuste</option>
                    <option value="TRASPASO" <?= $tipoOperacionSeleccionado === 'TRASPASO' ? 'selected' : '' ?>>🚚 Traspaso</option>
                    <option value="NOTA_REMISION" <?= $tipoOperacionSeleccionado === 'NOTA_REMISION' ? 'selected' : '' ?>>📝 Nota de Remisión</option>
                </select>
                <?php if ($modoResurtido): ?>
                    <input
                        type="hidden"
                        name="tipo_operacion"
                        value="<?= e($tipoOperacionSeleccionado) ?>"
                    >
                <?php endif; ?>
            </div>

            <div class="form-field" id="folioOperacionBox" style="display:none;">
                <label id="folioOperacionLabel">🔢 Folio de operación</label>
                <input type="text" name="folio_operacion" id="folioOperacionInput"
                    placeholder="Ingrese el folio"
                    value="<?= e($folioOperacionValor) ?>"
                    <?= $modoResurtido ? 'readonly' : '' ?>>
            </div>

            <div class="form-field">
                <label>🏪 Almacén que surte</label>
                <select name="almacen_id" required <?= $rolUsuario !== 'ADMINISTRADOR' ? 'disabled' : '' ?>>
                    <option value="">Seleccione...</option>
                    <?php foreach ($almacenes as $almacen): ?>
                        <option value="<?= (int)$almacen['id'] ?>" <?= (int)$almacen['id'] === $almacenSesion ? 'selected' : '' ?>>
                            <?= e($almacen['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($rolUsuario !== 'ADMINISTRADOR'): ?>
                    <input type="hidden" name="almacen_id" value="<?= (int)$almacenSesion ?>">
                <?php endif; ?>
            </div>

            <div class="form-field form-field-wide">
                <label>📝 Observaciones</label>
                <textarea name="observaciones" placeholder="Información adicional..."><?= e($observacionesValor) ?></textarea>
            </div>
        </div>

        <div class="folio-previous">
            <span>📋 Último folio registrado: <?= e($folioAnterior ?: 'Sin salidas anteriores') ?></span>
        </div>
    </div>

    <?php if ($modoResurtido): ?>
        <!-- SECCIÓN: PEDIDO DE LA SOLICITUD -->
        <div class="pedido-section">
            <div class="pedido-header">
                <h3>
                    📋 Pedido del
                    <?= e($nombreSolicitudOrigen) ?>
                </h3>
                <div class="pedido-header-acciones">
                    <span class="pedido-resumen" id="pedidoResumen">0 de 0 surtidos</span>
                    <button type="button" class="btn-mini" id="btnAgregarTodo">➕ Agregar todo lo pendiente</button>
                </div>
            </div>

            <div class="pedido-lista" id="pedidoLista"></div>

            <?php if (!empty($resurtidoSinRegistro)): ?>
                <div class="pedido-aviso">
                    ⚠️ Estos productos no están disponibles en el catálogo de esta bodega:
                    <ul>
                        <?php foreach ($resurtidoSinRegistro as $faltante): ?>
                            <li><strong><?= e($faltante['codigo']) ?></strong> — <?= e($faltante['descripcion']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- SECCIÓN 2: CAPTURA RÁPIDA -->
    <div class="capture-section">
        <div class="capture-title">Agregar productos</div>

        <div class="capture-grid">
            <div class="capture-field capture-field-producto">
                <label>🔍 Producto</label>
                <div class="capture-producto-row">
                    <input type="text" id="productoDisplayInput" placeholder="Toca Buscar o presiona Ctrl+B" readonly>
                    <button type="button" id="openModalBtn" class="btn-buscar">🔍 Buscar</button>
                </div>
            </div>

            <div class="capture-field">
                <label>🔢 Cantidad</label>
                <input type="number" id="cantidadInput" value="1" min="1" step="1" inputmode="numeric">
                <div class="qty-actions">
                    <button type="button" class="qty-btn" onclick="cambiarCantidad(1)">+1</button>
                    <button type="button" class="qty-btn" onclick="cambiarCantidad(5)">+5</button>
                    <button type="button" class="qty-btn" onclick="cambiarCantidad(10)">+10</button>
                    <button type="button" class="qty-btn" onclick="setMaxCantidad()">MAX</button>
                </div>
            </div>

            <div class="capture-field">
                <label>📍 Ubicación</label>
                <input type="text" id="ubicacionInput" list="ubicacionesList" placeholder="Seleccione ubicación">
                <datalist id="ubicacionesList"></datalist>
            </div>

            <div class="capture-field">
                <label>💰 Precio unitario</label>
                <input type="number" id="precioInput" step="0.01" value="0.00" inputmode="decimal">
            </div>

            <button type="button" class="btn-add" id="agregarBtn">
                ➕ Agregar producto
            </button>
        </div>

        <div id="selectedInfo" style="display: none;">
            <div class="selected-info">
                <div class="row"><span class="label">📦 Producto:</span><span class="value" id="infoCodigo">-</span></div>
                <div class="row"><span class="label">📝 Descripción:</span><span class="value" id="infoDescripcion">-</span></div>
                <div class="row"><span class="label">📏 Unidad:</span><span class="value" id="infoUnidad">-</span></div>
                <div class="row"><span class="label">📊 Stock disponible:</span><span class="value" id="infoStock">-</span></div>
                <div class="row" id="infoPedidoRow" style="display:none;"><span class="label">📋 Pedido solicitado:</span><span class="value" id="infoPedido">-</span></div>
            </div>
        </div>

        <div class="shortcuts-bar">
            🎯 <kbd>Ctrl</kbd> + <kbd>B</kbd> Abrir buscador &nbsp;|&nbsp;
            <kbd>↑</kbd> <kbd>↓</kbd> Navegar &nbsp;|&nbsp;
            <kbd>Enter</kbd> Seleccionar / Agregar &nbsp;|&nbsp;
            <kbd>↑</kbd> <kbd>↓</kbd> Revisar filas capturadas &nbsp;|&nbsp;
            <kbd>Ctrl</kbd> + <kbd>Enter</kbd> Guardar salida
        </div>
    </div>

    <!-- SECCIÓN 3: TABLA DE PRODUCTOS -->
    <div class="products-section">
        <div class="products-header">
            <h3>Productos en la salida</h3>
            <span class="product-badge" id="productosCount">0 productos</span>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 160px;">Cantidad</th>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th>Unidad</th>
                        <th>Ubicación</th>
                        <th>Precio</th>
                        <th>Importe</th>
                        <th style="width: 70px;"></th>
                    </tr>
                </thead>
                <tbody id="detalleBody">
                    <?php if ($modoEdicion && !empty($salidaEditar['detalles'])): ?>
                        <?php foreach ($salidaEditar['detalles'] as $item):
                            $cantidad = (int)($item['cantidad'] ?? 0);
                            $precio = (float)($item['precio_unitario'] ?? 0);
                            $importe = $cantidad * $precio;
                            $costoUnitario = (float)($item['costo_unitario'] ?? 0);
                            $productoId = (int)($item['producto_id'] ?? 0);
                            $ubicacion = trim($item['ubicacion'] ?? '');
                            $codigo = e($item['codigo'] ?? '');
                            $descripcion = e(substr($item['descripcion'] ?? '', 0, 60));
                            $unidad = e($item['unidad_medida'] ?? '');
                        ?>
                            <tr
                                class="fila-producto-salida"
                                tabindex="0"
                                data-producto-id="<?= $productoId ?>"
                                data-precio="<?= $precio ?>"
                            >
                                <td data-label="Cantidad">
                                    <div class="qty-cell">
                                        <button type="button" class="qty-step" onclick="pasoCantidadFila(this, -1)">−</button>
                                        <input type="number" class="qty-input" value="<?= $cantidad ?>" min="1" step="1" inputmode="numeric" oninput="actualizarCantidadFila(this)">
                                        <button type="button" class="qty-step" onclick="pasoCantidadFila(this, 1)">+</button>
                                    </div>
                                    <input type="hidden" name="producto_id[]" value="<?= $productoId ?>">
                                    <input type="hidden" name="cantidad[]" value="<?= $cantidad ?>">
                                    <input type="hidden" name="costo_unitario[]" value="<?= $costoUnitario ?>">
                                    <input type="hidden" name="precio_unitario[]" value="<?= $precio ?>">
                                    <input type="hidden" name="ubicacion[]" value="<?= e($ubicacion) ?>">
                                </td>
                                <td data-label="Código"><strong><?= $codigo ?></strong></td>
                                <td data-label="Descripción" class="celda-descripcion"><?= $descripcion ?></td>
                                <td data-label="Unidad"><?= $unidad ?></td>
                                <td data-label="Ubicación"><?= e($ubicacion) ?></td>
                                <td data-label="Precio">$<?= number_format($precio, 2) ?></td>
                                <td data-label="Importe" class="importe-fila" data-importe="<?= $importe ?>"><strong>$<?= number_format($importe, 2) ?></strong></td>
                                <td data-label=""><button type="button" class="delete-btn" onclick="eliminarFila(this)">🗑️</button></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr id="filaVacia">
                            <td colspan="8" class="fila-vacia-td">
                                📭 No hay productos. Toca 🔍 Buscar o presiona Ctrl+B
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
             </table>
        </div>
        <div class="table-footer">
            <button type="button" class="btn-clear" onclick="limpiarTodo()">🗑️ Limpiar todo</button>
            <div class="total-box">
                <span class="total-label">Total general:</span>
                <span class="total-amount" id="totalSalida">$0.00</span>
            </div>
            <button type="button" class="btn-save" id="guardarBtn">
                💾 <?= $modoResurtido ? 'Guardar salida y surtir' : 'Guardar salida' ?>
            </button>
        </div>
    </div>
</form>
</div>

<!-- MODAL GRANDE ESTILO EXCEL -->
<div class="modal-excel" id="modalExcel">
    <div class="modal-excel-content">
        <div class="modal-excel-header">
            <h3>
                <span>🔍</span> Buscar producto
                <span class="shortcut">Ctrl+B para abrir</span>
            </h3>
            <button class="modal-excel-close" id="closeModalBtn">✕</button>
        </div>
        <div class="modal-excel-search">
            <input type="text" id="modalSearchInput" placeholder="Escribe código o nombre del producto..." autocomplete="off">
        </div>
        <div class="modal-excel-table-container">
            <table class="modal-excel-table" id="modalTable">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th>Unidad</th>
                        <th>Stock</th>
                        <th>Precio</th>
                        <th style="min-width: 220px;">Ubicaciones</th>
                    </tr>
                </thead>
                <tbody id="modalTableBody">
                    <!-- Productos se cargan vía JS -->
                </tbody>
            </table>
        </div>
        <div class="modal-excel-footer">
            <span><kbd>↑</kbd> <kbd>↓</kbd> Navegar &nbsp;|&nbsp; <kbd>Enter</kbd> Seleccionar &nbsp;|&nbsp; <kbd>Esc</kbd> Cerrar</span>
            <span>Mostrando <span id="modalResultCount">0</span> productos</span>
        </div>
    </div>
</div>

<script>
// ===== VARIABLES =====
const productos = <?php
    $productosArray = [];
    foreach ($productos as $p) {
        $existenciaTotal = 0;
        $ubicacionesLista = [];

        if (!empty($p['ubicaciones']) && is_array($p['ubicaciones'])) {
            foreach ($p['ubicaciones'] as $ubi) {
                $existenciaTotal += (int)($ubi['existencia_actual'] ?? 0);
                $ubicacionTmp = strtoupper(trim((string)($ubi['ubicacion'] ?? '')));
                if ($ubicacionTmp !== '' && $ubicacionTmp !== 'SIN UBICACION') {
                    $ubicacionesLista[] = [
                        'ubicacion' => $ubicacionTmp,
                        'existencia' => (int)($ubi['existencia_actual'] ?? 0)
                    ];
                }
            }
        } else {
            $existenciaTotal = (int)($p['existencia_actual'] ?? $p['existencia_bodega'] ?? 0);
            $ubicacionNormal = strtoupper(trim((string)($p['ubicacion'] ?? '')));
            if ($ubicacionNormal !== '' && $ubicacionNormal !== 'SIN UBICACION') {
                $ubicacionesLista[] = [
                    'ubicacion' => $ubicacionNormal,
                    'existencia' => $existenciaTotal
                ];
            }
        }

        usort($ubicacionesLista, function($a, $b) {
            if ($a['existencia'] > 0 && $b['existencia'] <= 0) return -1;
            if ($b['existencia'] > 0 && $a['existencia'] <= 0) return 1;
            return $a['existencia'] - $b['existencia'];
        });

        $ubicacionSugerida = !empty($ubicacionesLista) ? $ubicacionesLista[0]['ubicacion'] : '';

        $productosArray[] = [
            'id' => (int)$p['id'],
            'codigo' => $p['codigo'],
            'descripcion' => $p['descripcion'],
            'unidad_medida' => $p['unidad_medida'],
            'precio_compra' => (float)$p['precio_compra'],
            'existencia_total' => $existenciaTotal,
            'ubicacion_sugerida' => $ubicacionSugerida,
            'ubicaciones' => $ubicacionesLista
        ];
    }
    echo json_encode($productosArray, JSON_UNESCAPED_UNICODE);
?>;

const resurtidoData = <?= $resurtidoJs !== null ? json_encode($resurtidoJs, JSON_UNESCAPED_UNICODE) : 'null' ?>;
const modoResurtido = resurtidoData !== null;
const esSolicitudTicketJs =
    modoResurtido
    && resurtidoData.tipo_solicitud === 'TICKET';
const nombreSolicitudJs =
    esSolicitudTicketJs ? 'ticket' : 'resurtido';
const folioSolicitudJs =
    esSolicitudTicketJs
        ? (
            resurtidoData.folio_documento
            || resurtidoData.folio
        )
        : (resurtidoData?.folio || '');
const filasPrevias = <?= json_encode($filasPrevias, JSON_UNESCAPED_UNICODE) ?>;

let productoSeleccionado = null;
let modalProductosFiltrados = [];
let modalSelectedIndex = -1;

// Elementos DOM
const modal = document.getElementById('modalExcel');
const modalSearch = document.getElementById('modalSearchInput');
const modalTableBody = document.getElementById('modalTableBody');
const modalResultCount = document.getElementById('modalResultCount');
const productoDisplayInput = document.getElementById('productoDisplayInput');
const cantidadInput = document.getElementById('cantidadInput');
const precioInput = document.getElementById('precioInput');
const ubicacionInput = document.getElementById('ubicacionInput');
const detalleBody = document.getElementById('detalleBody');

// ===== UBICACIONES =====
function generarTodasLasUbicaciones() {
    const lista = document.getElementById('ubicacionesList');
    if (!lista) return;

    const ubicaciones = [];

    function add(rack, nivel, zona) {
        const z = String(zona).padStart(2, '0');
        ubicaciones.push(`R${rack}N${nivel}Z${z}`);
    }

    for (let n = 1; n <= 3; n++) { for (let z = 1; z <= 22; z++) add(1, n, z); }
    for (let n = 1; n <= 3; n++) { for (let z = 1; z <= 20; z++) add(2, n, z); }
    for (let n = 1; n <= 3; n++) { for (let z = 1; z <= 20; z++) add(3, n, z); }
    for (let n = 1; n <= 2; n++) { for (let z = 1; z <= 16; z++) add(4, n, z); }
    for (let z = 10; z <= 16; z++) add(4, 3, z);
    for (let n = 1; n <= 2; n++) { for (let z = 1; z <= 15; z++) add(5, n, z); }
    for (let z = 10; z <= 15; z++) add(5, 3, z);
    for (let n = 1; n <= 3; n++) { for (let z = 1; z <= 22; z++) add(6, n, z); }

    ubicaciones.push('R7N1Z01 - PASILLO 3', 'R8N1Z01 - PASILLO 2', 'R9N1Z01 - PASILLO 1', 'BODEGA PEDYALITE');

    lista.innerHTML = '';
    ubicaciones.forEach(u => {
        const option = document.createElement('option');
        option.value = u;
        lista.appendChild(option);
    });
}

// ===== MODAL =====
function abrirModal() {
    modal.classList.add('active');
    modalSearch.value = '';
    cargarProductosEnModal(productos);
    setTimeout(() => modalSearch.focus(), 100);
    modalSelectedIndex = -1;
}

function cerrarModal() {
    modal.classList.remove('active');
}

function cargarProductosEnModal(productosList) {
    modalProductosFiltrados = productosList;
    modalResultCount.textContent = productosList.length;

    if (productosList.length === 0) {
        modalTableBody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;">No se encontraron productos</td></tr>';
        return;
    }

    modalTableBody.innerHTML = productosList.map((p, idx) => {
        let ubicacionesHtml = '';

        if (p.ubicaciones && p.ubicaciones.length > 0) {
            ubicacionesHtml = `
                <div class="ubicaciones-wrapper">
                    ${p.ubicaciones.map(u => `
                        <span class="ubi-chip ${u.existencia > 0 ? '' : 'ubi-chip-vacia'}">
                            ${escapeHtml(u.ubicacion)} <b>${u.existencia}</b>
                        </span>
                    `).join('')}
                </div>
            `;
        } else {
            ubicacionesHtml = `<div class="sin-ubicaciones">Sin ubicaciones</div>`;
        }

        return `
            <tr data-idx="${idx}" data-producto-id="${p.id}" class="producto-principal">
                <td
                    class="product-code copyable-cell"
                    data-label="Código"
                    data-copy-type="codigo"
                    data-copy-idx="${idx}"
                    title="Clic para copiar el código"
                >${escapeHtml(p.codigo)}</td>
                <td
                    class="copyable-cell"
                    data-label="Descripción"
                    data-copy-type="descripcion"
                    data-copy-idx="${idx}"
                    title="Clic para copiar la descripción"
                >${escapeHtml(p.descripcion)}</td>
                <td data-label="Unidad">${escapeHtml(p.unidad_medida)}</td>
                <td class="product-stock" data-label="Stock">${p.existencia_total}</td>
                <td data-label="Precio">$${p.precio_compra.toFixed(2)}</td>
                <td class="ubicaciones-cell" data-label="Ubicaciones">${ubicacionesHtml}</td>
            </tr>
        `;
    }).join('');

    document.querySelectorAll('#modalTableBody > tr.producto-principal').forEach(row => {
        row.addEventListener('click', () => {
            const idx = parseInt(row.dataset.idx);
            if (modalProductosFiltrados[idx]) {
                seleccionarProductoDelModal(modalProductosFiltrados[idx]);
            }
        });
    });

    document.querySelectorAll(
        '#modalTableBody .copyable-cell'
    ).forEach(celda => {
        celda.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();

            const indice = Number(celda.dataset.copyIdx);
            const tipo = celda.dataset.copyType;
            const producto = modalProductosFiltrados[indice];

            if (!producto) return;

            copiarTexto(
                tipo === 'codigo'
                    ? producto.codigo
                    : producto.descripcion,
                tipo === 'codigo'
                    ? 'Código'
                    : 'Descripción'
            );
        });
    });
}

async function copiarTexto(texto, etiqueta) {
    const valor = String(texto ?? '');

    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(valor);
        } else {
            const auxiliar = document.createElement('textarea');
            auxiliar.value = valor;
            auxiliar.setAttribute('readonly', '');
            auxiliar.style.position = 'fixed';
            auxiliar.style.opacity = '0';
            document.body.appendChild(auxiliar);
            auxiliar.select();

            if (!document.execCommand('copy')) {
                throw new Error('El navegador rechazó la copia.');
            }

            auxiliar.remove();
        }

        mostrarToast(`📋 ${etiqueta} copiada`);
    } catch (error) {
        mostrarToast(
            `❌ No se pudo copiar ${etiqueta.toLowerCase()}`,
            'error'
        );
    }
}

function filtrarProductosModal() {
    const termino = modalSearch.value.toLowerCase().trim();

    if (!termino) {
        cargarProductosEnModal(productos);
        return;
    }

    const filtrados = productos.filter(p =>
        p.codigo.toLowerCase().includes(termino) ||
        p.descripcion.toLowerCase().includes(termino)
    );

    cargarProductosEnModal(filtrados);
    modalSelectedIndex = -1;
}

function actualizarSeleccionModal() {
    const filas = document.querySelectorAll('#modalTableBody > tr.producto-principal');
    filas.forEach((fila, idx) => {
        if (idx === modalSelectedIndex) {
            fila.classList.add('selected');
            fila.scrollIntoView({ block: 'nearest' });
        } else {
            fila.classList.remove('selected');
        }
    });
}

function seleccionarProductoDelModal(producto) {
    productoSeleccionado = producto;

    document.getElementById('selectedInfo').style.display = 'block';
    document.getElementById('infoCodigo').innerHTML = `<strong>${escapeHtml(producto.codigo)}</strong>`;
    document.getElementById('infoDescripcion').textContent = producto.descripcion;
    document.getElementById('infoUnidad').textContent = producto.unidad_medida;
    document.getElementById('infoStock').textContent = producto.existencia_total;

    const pedidoRow = document.getElementById('infoPedidoRow');
    const pedido = obtenerPedidoResurtido(producto.id);

    if (pedido) {
        pedidoRow.style.display = 'flex';
        document.getElementById('infoPedido').textContent = `${pedido.pendiente} ${producto.unidad_medida || ''}`;
        cantidadInput.value = Math.max(1, Math.min(pedido.pendiente, producto.existencia_total || pedido.pendiente));
    } else {
        pedidoRow.style.display = 'none';
        cantidadInput.value = 1;
    }

    productoDisplayInput.value = `${producto.codigo} - ${producto.descripcion.substring(0, 50)}`;
    precioInput.value = producto.precio_compra;

    if (producto.ubicaciones && producto.ubicaciones.length > 0) {
        ubicacionInput.value = producto.ubicaciones[0].ubicacion;
    } else {
        ubicacionInput.value = producto.ubicacion_sugerida || '';
    }

    cerrarModal();
    cantidadInput.focus();
    cantidadInput.select();
    mostrarToast(`✅ Producto seleccionado: ${producto.codigo}`);
}

// ===== CANTIDADES =====
function cambiarCantidad(valor) {
    let nuevo = (parseInt(cantidadInput.value) || 0) + valor;
    if (nuevo < 1) nuevo = 1;

    const pedido = productoSeleccionado
        ? obtenerPedidoResurtido(
            productoSeleccionado.id
        )
        : null;

    if (pedido && nuevo > pedido.pendiente) {
        nuevo = pedido.pendiente;
        mostrarToast(
            `⚠️ Máximo pendiente: ${pedido.pendiente}`,
            'warning'
        );
    }

    if (productoSeleccionado && nuevo > productoSeleccionado.existencia_total) {
        nuevo = productoSeleccionado.existencia_total;
        mostrarToast(`⚠️ Máximo disponible: ${productoSeleccionado.existencia_total}`, 'warning');
    }

    cantidadInput.value = nuevo;
}

function setMaxCantidad() {
    if (productoSeleccionado) {
        const pedido = obtenerPedidoResurtido(
            productoSeleccionado.id
        );

        cantidadInput.value = pedido
            ? Math.min(
                pedido.pendiente,
                productoSeleccionado.existencia_total
            )
            : productoSeleccionado.existencia_total;
    }
}

// ===== RESURTIDO: UTILIDADES =====
function obtenerPedidoResurtido(productoId) {
    if (!modoResurtido) return null;
    return resurtidoData.productos.find(p => Number(p.producto_id) === Number(productoId)) || null;
}

function cantidadEnSalida(productoId) {
    let total = 0;
    document.querySelectorAll('#detalleBody tr[data-producto-id]').forEach(tr => {
        if (Number(tr.dataset.productoId) === Number(productoId)) {
            const input = tr.querySelector('input[name="cantidad[]"]');
            total += parseInt(input ? input.value : '0') || 0;
        }
    });
    return total;
}

// ===== AGREGAR FILAS =====
function agregarFila(producto, cantidad, ubicacion, precio) {
    const filaVacia = document.getElementById('filaVacia');
    if (filaVacia) filaVacia.remove();

    const pedido = obtenerPedidoResurtido(producto.id);
    const importe = cantidad * precio;

    const tr = document.createElement('tr');
    tr.dataset.productoId = producto.id;
    tr.dataset.precio = precio;
    tr.classList.add('fila-producto-salida');
    tr.tabIndex = 0;

    if (pedido) {
        tr.dataset.solicitado = pedido.pendiente;
        tr.classList.add('fila-resurtido');
        if (cantidad < pedido.pendiente) tr.classList.add('fila-incompleta');
    }

    tr.innerHTML = `
        <td data-label="Cantidad">
            <div class="qty-cell">
                <button type="button" class="qty-step" onclick="pasoCantidadFila(this, -1)">−</button>
                <input type="number" class="qty-input" value="${cantidad}" min="1" ${pedido ? `max="${pedido.pendiente}"` : ''} step="1" inputmode="numeric" oninput="actualizarCantidadFila(this)">
                <button type="button" class="qty-step" onclick="pasoCantidadFila(this, 1)">+</button>
            </div>
            ${pedido ? `<span class="pedido-chip">Pedido: ${pedido.pendiente}</span>` : ''}
            <input type="hidden" name="producto_id[]" value="${producto.id}">
            <input type="hidden" name="cantidad[]" value="${cantidad}">
            <input type="hidden" name="costo_unitario[]" value="${producto.precio_compra}">
            <input type="hidden" name="precio_unitario[]" value="${precio}">
            <input type="hidden" name="ubicacion[]" value="${escapeHtml(ubicacion)}">
        </td>
        <td data-label="Código"><strong>${escapeHtml(producto.codigo)}</strong></td>
        <td data-label="Descripción" class="celda-descripcion">${escapeHtml(producto.descripcion.substring(0, 70))}</td>
        <td data-label="Unidad">${escapeHtml(producto.unidad_medida)}</td>
        <td data-label="Ubicación">${escapeHtml(ubicacion)}</td>
        <td data-label="Precio">$${precio.toFixed(2)}</td>
        <td data-label="Importe" class="importe-fila" data-importe="${importe}"><strong>$${importe.toFixed(2)}</strong></td>
        <td data-label=""><button type="button" class="delete-btn" onclick="eliminarFila(this)">🗑️</button></td>
    `;

    detalleBody.appendChild(tr);
    prepararNavegacionFilasSalida();
    activarFilaSalida(tr);
    actualizarTotales();

    return tr;
}

function obtenerFilasSalida() {
    return Array.from(
        detalleBody.querySelectorAll(
            'tr[data-producto-id]'
        )
    );
}

function activarFilaSalida(fila, enfocar = false, selector = '') {
    obtenerFilasSalida().forEach(item => {
        item.classList.toggle(
            'fila-salida-activa',
            item === fila
        );
    });

    if (!fila || !enfocar) return;

    const destino = selector
        ? fila.querySelector(selector)
        : fila;

    destino?.focus();

    if (destino instanceof HTMLInputElement) {
        destino.select();
    }
}

function prepararNavegacionFilasSalida() {
    obtenerFilasSalida().forEach(fila => {
        fila.classList.add('fila-producto-salida');
        fila.tabIndex = 0;

        if (fila.dataset.navegacionLista === '1') {
            return;
        }

        fila.dataset.navegacionLista = '1';

        fila.addEventListener('click', event => {
            const esControl = event.target.closest(
                'input, button, select, textarea'
            );

            activarFilaSalida(
                fila,
                !esControl
            );
        });

        fila.addEventListener('focusin', () => {
            activarFilaSalida(fila);
        });
    });
}

function navegarFilasSalida(event) {
    if (event.key !== 'ArrowUp'
        && event.key !== 'ArrowDown'
    ) {
        return;
    }

    if (modal?.classList.contains('active')) {
        return;
    }

    let filaActual = event.target.closest?.(
        'tr[data-producto-id]'
    );

    if (!filaActual) {
        const esCampoEditable = event.target.matches?.(
            'input, select, textarea'
        );

        if (esCampoEditable) return;

        filaActual = detalleBody.querySelector(
            'tr.fila-salida-activa'
        );
    }

    if (!filaActual) return;

    const filas = obtenerFilasSalida();
    const indiceActual = filas.indexOf(filaActual);
    const movimiento = event.key === 'ArrowDown'
        ? 1
        : -1;
    const indiceNuevo = Math.max(
        0,
        Math.min(
            filas.length - 1,
            indiceActual + movimiento
        )
    );
    const selector = event.target.classList?.contains(
        'qty-input'
    ) ? '.qty-input' : '';

    event.preventDefault();
    activarFilaSalida(
        filas[indiceNuevo],
        true,
        selector
    );
}

function agregarProducto() {
    if (!productoSeleccionado) {
        mostrarToast('❌ Selecciona un producto (Ctrl+B para buscar)', 'error');
        return;
    }

    const producto = productoSeleccionado;

    let cantidad = parseInt(cantidadInput.value);
    if (isNaN(cantidad) || cantidad < 1) cantidad = 1;

    const precio = parseFloat(precioInput.value);
    if (isNaN(precio) || precio < 0) {
        mostrarToast('❌ Precio inválido', 'error');
        return;
    }

    let ubicacion = ubicacionInput.value.trim().toUpperCase();
    if (!ubicacion) ubicacion = producto.ubicacion_sugerida || '';

    if (!ubicacion || ubicacion === 'SIN UBICACION') {
        mostrarToast('❌ Selecciona una ubicación válida', 'error');
        ubicacionInput.focus();
        return;
    }

    if (cantidad > producto.existencia_total) {
        mostrarToast(`❌ Stock insuficiente. Disponible: ${producto.existencia_total}`, 'error');
        return;
    }

    const pedido = obtenerPedidoResurtido(producto.id);

    if (
        pedido
        && cantidad > pedido.pendiente
    ) {
        mostrarToast(
            `❌ Solo quedan ${pedido.pendiente} unidad(es) pendientes`,
            'error'
        );
        return;
    }

    const filasExistentes = document.querySelectorAll('#detalleBody tr[data-producto-id]');
    for (let fila of filasExistentes) {
        if (Number(fila.dataset.productoId) === Number(producto.id)) {
            mostrarToast('⚠️ El producto ya está en la lista', 'warning');
            return;
        }
    }

    agregarFila(producto, cantidad, ubicacion, precio);

    productoSeleccionado = null;
    document.getElementById('selectedInfo').style.display = 'none';
    productoDisplayInput.value = '';
    cantidadInput.value = '1';
    ubicacionInput.value = '';
    precioInput.value = '0.00';

    mostrarToast(`✅ ${cantidad} x ${producto.codigo} agregado`);
}

function pasoCantidadFila(boton, paso) {
    const input = boton.parentElement.querySelector('.qty-input');
    if (!input) return;

    let cantidad = (parseInt(input.value) || 0) + paso;
    if (cantidad < 1) cantidad = 1;

    input.value = cantidad;
    actualizarCantidadFila(input);
}

function actualizarCantidadFila(input) {
    const tr = input.closest('tr');
    let cantidad = parseInt(input.value);
    if (isNaN(cantidad) || cantidad < 1) cantidad = 1;

    const solicitado = parseInt(tr.dataset.solicitado || '0');

    if (solicitado > 0 && cantidad > solicitado) {
        cantidad = solicitado;
        input.value = cantidad;

        mostrarToast(
            `⚠️ Máximo pendiente: ${solicitado}`,
            'warning'
        );
    }

    const hiddenInput = tr.querySelector('input[name="cantidad[]"]');
    if (hiddenInput) hiddenInput.value = cantidad;

    const precio = parseFloat(tr.dataset.precio || '0') || 0;
    const nuevoImporte = cantidad * precio;

    const importeTd = tr.querySelector('.importe-fila');
    if (importeTd) {
        importeTd.dataset.importe = nuevoImporte;
        importeTd.innerHTML = `<strong>$${nuevoImporte.toFixed(2)}</strong>`;
    }

    if (solicitado > 0) {
        tr.classList.toggle('fila-incompleta', cantidad < solicitado);
    }

    actualizarTotales();
}

function eliminarFila(btn) {
    const tr = btn.closest('tr');
    tr.remove();

    if (detalleBody.querySelectorAll('tr[data-producto-id]').length === 0) {
        detalleBody.innerHTML = `<tr id="filaVacia"><td colspan="8" class="fila-vacia-td">📭 No hay productos. Toca 🔍 Buscar o presiona Ctrl+B</td></tr>`;
    }

    prepararNavegacionFilasSalida();
    const filasInicialesSalida = obtenerFilasSalida();
    if (filasInicialesSalida.length > 0) {
        activarFilaSalida(filasInicialesSalida[0]);
    }
    actualizarTotales();
    mostrarToast('🗑️ Producto eliminado', 'info');
}

function limpiarTodo() {
    if (confirm('¿Eliminar todos los productos?')) {
        detalleBody.innerHTML = `<tr id="filaVacia"><td colspan="8" class="fila-vacia-td">📭 No hay productos. Toca 🔍 Buscar o presiona Ctrl+B</td></tr>`;
        prepararNavegacionFilasSalida();
        actualizarTotales();
        mostrarToast('🧹 Lista limpiada', 'info');
    }
}

function actualizarTotales() {
    let total = 0;
    let count = 0;

    document.querySelectorAll('.importe-fila').forEach(td => {
        total += parseFloat(td.dataset.importe || '0');
        count++;
    });

    document.getElementById('totalSalida').textContent = `$${total.toFixed(2)}`;
    document.getElementById('productosCount').textContent = `${count} producto${count !== 1 ? 's' : ''}`;

    renderizarPedidoResurtido();
}

// ===== PANEL DEL PEDIDO =====
function renderizarPedidoResurtido() {
    if (!modoResurtido) return;

    const contenedor = document.getElementById('pedidoLista');
    if (!contenedor) return;

    let completos = 0;

    contenedor.innerHTML = resurtidoData.productos.map(item => {
        const enSalida = cantidadEnSalida(item.producto_id);
        const catalogo = productos.find(p => Number(p.id) === Number(item.producto_id));
        const stock = catalogo ? catalogo.existencia_total : 0;

        let clase = 'pedido-item';
        let etiqueta = 'Pendiente';

        if (enSalida >= item.pendiente && item.pendiente > 0) {
            clase += ' pedido-item-ok';
            etiqueta = 'Completo';
            completos++;
        } else if (enSalida > 0) {
            clase += ' pedido-item-parcial';
            etiqueta = 'Parcial';
        } else if (!catalogo || stock <= 0) {
            clase += ' pedido-item-sin-stock';
            etiqueta = 'Sin existencia';
        }

        return `
            <div class="${clase}">
                <div class="pedido-item-info">
                    <strong>${escapeHtml(item.codigo)}</strong>
                    <span>${escapeHtml(item.descripcion)}</span>
                </div>
                <div class="pedido-item-datos">
                    <span class="pedido-dato"><b>${item.pendiente}</b> pedido</span>
                    <span class="pedido-dato"><b>${enSalida}</b> en salida</span>
                    <span class="pedido-dato"><b>${stock}</b> stock</span>
                    <span class="pedido-etiqueta">${etiqueta}</span>
                </div>
                <button type="button" class="btn-mini btn-mini-add"
                        onclick="agregarDesdePedido(${item.producto_id})"
                        ${(!catalogo || stock <= 0 || enSalida > 0) ? 'disabled' : ''}>
                    ➕ Agregar
                </button>
            </div>
        `;
    }).join('');

    const resumen = document.getElementById('pedidoResumen');
    if (resumen) {
        resumen.textContent = `${completos} de ${resurtidoData.productos.length} completos`;
    }
}

function agregarDesdePedido(productoId, silencioso) {
    const item = obtenerPedidoResurtido(productoId);
    const producto = productos.find(p => Number(p.id) === Number(productoId));

    if (!item || !producto) {
        if (!silencioso) mostrarToast('❌ El producto no está disponible en esta bodega', 'error');
        return false;
    }

    if (cantidadEnSalida(productoId) > 0) {
        if (!silencioso) mostrarToast('⚠️ El producto ya está en la salida', 'warning');
        return false;
    }

    const stock = producto.existencia_total || 0;

    if (stock <= 0) {
        if (!silencioso) mostrarToast(`❌ Sin existencia: ${producto.codigo}`, 'error');
        return false;
    }

    const cantidad = Math.min(item.pendiente, stock);

    let ubicacion = '';
    if (producto.ubicaciones && producto.ubicaciones.length > 0) {
        ubicacion = producto.ubicaciones[0].ubicacion;
    } else {
        ubicacion = producto.ubicacion_sugerida || '';
    }

    if (!ubicacion || ubicacion === 'SIN UBICACION') {
        if (!silencioso) mostrarToast(`⚠️ ${producto.codigo} no tiene ubicación asignada`, 'warning');
        return false;
    }

    agregarFila(producto, cantidad, ubicacion, producto.precio_compra);

    if (!silencioso) {
        mostrarToast(`✅ ${cantidad} x ${producto.codigo} agregado`);
    }

    return true;
}

function restaurarFilasPrevias() {
    if (!Array.isArray(filasPrevias) || filasPrevias.length === 0) {
        return false;
    }

    let restauradas = 0;

    filasPrevias.forEach(fila => {
        const producto = productos.find(p => Number(p.id) === Number(fila.producto_id));
        if (!producto) return;

        let ubicacion = fila.ubicacion;

        if (!ubicacion || ubicacion === 'SIN UBICACION') {
            ubicacion = (producto.ubicaciones && producto.ubicaciones.length > 0)
                ? producto.ubicaciones[0].ubicacion
                : (producto.ubicacion_sugerida || '');
        }

        agregarFila(producto, fila.cantidad, ubicacion, Number(fila.precio) || 0);
        restauradas++;
    });

    if (restauradas > 0) {
        mostrarToast('↩️ Se recuperó la captura anterior', 'warning');
    }

    return restauradas > 0;
}

function precargarResurtido() {
    if (!modoResurtido) return;

    let agregados = 0;
    let faltantes = 0;

    resurtidoData.productos.forEach(item => {
        if (item.pendiente <= 0) return;

        if (agregarDesdePedido(item.producto_id, true)) {
            agregados++;
        } else {
            faltantes++;
        }
    });

    actualizarTotales();

    if (agregados > 0) {
        mostrarToast(
            `${esSolicitudTicketJs ? '🎫' : '🔄'} `
            + `${agregados} producto(s) del `
            + `${nombreSolicitudJs} ${folioSolicitudJs} cargados`
        );
    }

    if (faltantes > 0) {
        setTimeout(() => {
            mostrarToast(`⚠️ ${faltantes} producto(s) sin existencia o sin ubicación`, 'warning');
        }, 800);
    }
}

// ===== TOAST =====
function mostrarToast(mensaje, tipo = 'success') {
    const toast = document.createElement('div');
    toast.className = 'toast-message';

    if (tipo === 'error') toast.style.borderLeftColor = '#ef4444';
    else if (tipo === 'warning') toast.style.borderLeftColor = '#f59e0b';
    else toast.style.borderLeftColor = '#059669';

    toast.innerHTML = mensaje;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2500);
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        if (m === '"') return '&quot;';
        return m;
    });
}

// ===== FOLIO DE OPERACION =====
const tipoOperacionSelect = document.getElementById('tipoOperacionSelect');
const folioOperacionBox = document.getElementById('folioOperacionBox');
const folioOperacionInput = document.getElementById('folioOperacionInput');
const folioOperacionLabel = document.getElementById('folioOperacionLabel');

function controlarFolioOperacion() {
    const valor = tipoOperacionSelect.value;
    const tiposRequeridos = ['TICKET', 'RESURTIDO', 'NOTA_REMISION'];

    if (tiposRequeridos.includes(valor)) {
        folioOperacionBox.style.display = 'flex';

        const etiquetas = {
            'TICKET': '🎫 Folio de Ticket',
            'RESURTIDO': '🔄 Folio de Resurtido',
            'NOTA_REMISION': '📝 N# de Nota de Remisión'
        };

        folioOperacionLabel.innerHTML = etiquetas[valor] || '🔢 Folio de operación';
        folioOperacionInput.required = true;
    } else {
        folioOperacionBox.style.display = 'none';
        folioOperacionInput.required = false;
        <?php if (!$modoEdicion && !$modoResurtido): ?>folioOperacionInput.value = '';<?php endif; ?>
    }
}

function ponerFechaActual() {
    const fechaInput = document.getElementById('fechaInput');
    if (fechaInput.value && <?= $modoEdicion ? 'true' : 'false' ?>) return;

    if (!fechaInput.value) {
        const ahora = new Date();
        const year = ahora.getFullYear();
        const month = String(ahora.getMonth() + 1).padStart(2, '0');
        const day = String(ahora.getDate()).padStart(2, '0');
        const hours = String(ahora.getHours()).padStart(2, '0');
        const minutes = String(ahora.getMinutes()).padStart(2, '0');
        fechaInput.value = `${year}-${month}-${day}T${hours}:${minutes}`;
    }
}

// ===== GUARDAR =====
let guardando = false;

function guardarSalida() {
    if (guardando) return;

    const productosEnLista = document.querySelectorAll('#detalleBody tr[data-producto-id]');

    if (productosEnLista.length === 0) {
        mostrarToast('❌ Agrega al menos un producto', 'error');
        return;
    }

    const tipoSalida = document.getElementById('tipoSalidaSelect');
    if (tipoSalida && tipoSalida.value === '') {
        mostrarToast('❌ Selecciona el tipo de salida', 'error');
        tipoSalida.focus();
        return;
    }

    if (modoResurtido) {
        const incompletos = resurtidoData.productos.filter(item => {
            return cantidadEnSalida(item.producto_id) < item.pendiente;
        }).length;

        if (incompletos > 0) {
            const seguir = confirm(
                `Hay ${incompletos} producto(s) del ${nombreSolicitudJs} ${folioSolicitudJs} que no se surten completos.\n\n`
                + `El ${nombreSolicitudJs} quedará marcado como PARCIAL. ¿Deseas continuar?`
            );

            if (!seguir) return;
        }
    }

    guardando = true;

    const boton = document.getElementById('guardarBtn');
    if (boton) {
        boton.disabled = true;
        boton.textContent = '⏳ Guardando...';
    }

    document.getElementById('formSalida').submit();
}

// ===== EVENTOS =====
document.addEventListener('DOMContentLoaded', () => {
    generarTodasLasUbicaciones();
    controlarFolioOperacion();
    ponerFechaActual();

    if (!restaurarFilasPrevias()) {
        precargarResurtido();
    }

    prepararNavegacionFilasSalida();
    actualizarTotales();

    document.addEventListener(
        'keydown',
        navegarFilasSalida
    );

    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey && (e.key === 'b' || e.key === 'B')) {
            e.preventDefault();
            abrirModal();
        }
    });

    ['cantidadInput', 'precioInput', 'ubicacionInput'].forEach(id => {
        document.getElementById(id)?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                agregarProducto();
            }
        });
    });

    document.getElementById('agregarBtn')?.addEventListener('click', agregarProducto);
    document.getElementById('guardarBtn')?.addEventListener('click', guardarSalida);
    document.getElementById('openModalBtn')?.addEventListener('click', abrirModal);
    document.getElementById('productoDisplayInput')?.addEventListener('click', abrirModal);
    document.getElementById('closeModalBtn')?.addEventListener('click', cerrarModal);

    document.getElementById('btnAgregarTodo')?.addEventListener('click', () => {
        let agregados = 0;

        resurtidoData.productos.forEach(item => {
            if (cantidadEnSalida(item.producto_id) === 0 && item.pendiente > 0) {
                if (agregarDesdePedido(item.producto_id, true)) agregados++;
            }
        });

        actualizarTotales();
        mostrarToast(agregados > 0 ? `✅ ${agregados} producto(s) agregados` : '⚠️ No hay productos por agregar', agregados > 0 ? 'success' : 'warning');
    });

    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey && e.key === 'Enter') {
            e.preventDefault();
            guardarSalida();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('active')) {
            cerrarModal();
        }
    });

    modal.addEventListener('click', (e) => {
        if (e.target === modal) cerrarModal();
    });
});

if (modalSearch) {
    modalSearch.addEventListener('keydown', (e) => {
        const filasProductos = document.querySelectorAll('#modalTableBody > tr.producto-principal');
        const totalFilas = filasProductos.length;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (totalFilas > 0) {
                modalSelectedIndex = Math.min(modalSelectedIndex + 1, totalFilas - 1);
                actualizarSeleccionModal();
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (totalFilas > 0) {
                modalSelectedIndex = Math.max(modalSelectedIndex - 1, 0);
                actualizarSeleccionModal();
            }
        } else if (e.key === 'Enter' && modalSelectedIndex >= 0 && modalProductosFiltrados[modalSelectedIndex]) {
            e.preventDefault();
            seleccionarProductoDelModal(modalProductosFiltrados[modalSelectedIndex]);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            cerrarModal();
        }
    });

    modalSearch.addEventListener('input', filtrarProductosModal);
}

tipoOperacionSelect?.addEventListener('change', controlarFolioOperacion);
</script>

<?php
if (file_exists(__DIR__ . '/../app/views/layouts/footer.php')) {
    include __DIR__ . '/../app/views/layouts/footer.php';
}
