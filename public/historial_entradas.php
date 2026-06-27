<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/HistorialEntradaController.php';
require_once __DIR__ . '/../app/controllers/EntradaController.php';

requireLogin();

$user = currentUser();

$controller = new HistorialEntradaController();
$entradaCancelController = new EntradaController();

$mensaje = '';
$tipoMensaje = '';

$rolUsuario = strtoupper(trim($user['rol'] ?? ''));
$almacenSesion = (int)($user['almacen_id'] ?? 0);

$buscar = trim($_GET['buscar'] ?? '');

if ($rolUsuario === 'ADMINISTRADOR') {
    $almacenId = isset($_GET['almacen_id']) ? (int)$_GET['almacen_id'] : 0;
} else {
    $almacenId = $almacenSesion;
}

$fechaInicio = trim($_GET['fecha_inicio'] ?? '');
$fechaFinal = trim($_GET['fecha_final'] ?? '');

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['accion'] ?? '') === 'cancelar_entrada'
) {
    $movimientoId = (int)($_POST['movimiento_id'] ?? 0);

    $motivo = trim(
        $_POST['motivo_cancelacion']
        ?? 'Cancelado desde historial de entradas'
    );

    $resultado = $entradaCancelController->cancelarEntrada(
        $movimientoId,
        (int)$user['id'],
        $motivo
    );

    $mensaje = $resultado['message'] ?? '';

    $tipoMensaje = !empty($resultado['success'])
        ? 'success'
        : 'error';
}

$almacenes = $controller->almacenes();
$entradas = $controller->index($buscar, $almacenId, $fechaInicio, $fechaFinal);
$resumen = $controller->resumen($entradas);

$moduleCss = 'historial_entradas';

include __DIR__ . '/../app/views/layouts/header.php';
?>

<style>
.table-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}

.btn-cancel {
    background: #dc2626;
    color: #fff;
    border: none;
    cursor: pointer;
}

.btn-cancel:hover {
    background: #b91c1c;
}

.btn-edit {
    background: #2563eb;
    color: #fff;
    text-decoration: none;
}

.btn-edit:hover {
    background: #1d4ed8;
}

.badge-cancelado {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
    padding: 4px 8px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .3px;
    text-transform: uppercase;
    line-height: 1;
}

.alert {
    padding: 12px 16px;
    border-radius: 10px;
    margin: 12px 20px;
    font-weight: 600;
}

.alert-success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}

.folio-wrap {
    display: flex;
    flex-direction: column;
    gap: 4px;
    align-items: flex-start;
}

.folio-text {
    font-weight: 800;
    color: #0f172a;
}
</style>

<div class="module-header">
    <div>
        <h2>Historial de Entradas</h2>
        <p>Consulta, filtra, reimprime, edita o cancela entradas registradas en el almacén.</p>
    </div>
</div>

<?php if ($mensaje !== ''): ?>
    <div class="alert <?= $tipoMensaje === 'success' ? 'alert-success' : 'alert-error' ?>">
        <?= e($mensaje) ?>
    </div>
<?php endif; ?>

<div class="historial-resumen-grid">
    <div class="historial-card">
        <span>Total entradas</span>
        <strong><?= number_format((int)$resumen['total_entradas']) ?></strong>
    </div>

    <div class="historial-card">
        <span>Productos capturados</span>
        <strong><?= number_format((int)$resumen['total_productos']) ?></strong>
    </div>

    <div class="historial-card">
        <span>Unidades ingresadas</span>
        <strong><?= number_format((int)$resumen['total_unidades']) ?></strong>
    </div>
</div>

<div class="historial-filter-card">
    <form method="GET" action="historial_entradas.php" class="historial-filter-form">

        <div class="historial-field search-field">
            <label>Buscar</label>
            <input 
                type="text" 
                name="buscar" 
                value="<?= e($buscar) ?>" 
                placeholder="Folio, referencia, proveedor, producto, usuario..."
            >
        </div>

        <div class="historial-field">
            <label>Almacén</label>

            <select
                name="almacen_id"
                <?= $rolUsuario !== 'ADMINISTRADOR' ? 'disabled' : '' ?>
            >
                <?php if ($rolUsuario === 'ADMINISTRADOR'): ?>
                    <option value="0">Todos los almacenes</option>
                <?php endif; ?>

                <?php foreach ($almacenes as $almacen): ?>
                    <option 
                        value="<?= (int)$almacen['id'] ?>"
                        <?= $almacenId === (int)$almacen['id'] ? 'selected' : '' ?>
                    >
                        <?= e($almacen['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ($rolUsuario !== 'ADMINISTRADOR'): ?>
                <input 
                    type="hidden" 
                    name="almacen_id" 
                    value="<?= (int)$almacenSesion ?>"
                >
            <?php endif; ?>
        </div>

        <div class="historial-field">
            <label>Fecha inicio</label>
            <input type="date" name="fecha_inicio" value="<?= e($fechaInicio) ?>">
        </div>

        <div class="historial-field">
            <label>Fecha final</label>
            <input type="date" name="fecha_final" value="<?= e($fechaFinal) ?>">
        </div>

        <div class="historial-actions">
            <button type="submit" class="btn-primary-action">Filtrar</button>
            <a href="historial_entradas.php" class="btn-secondary-action">Limpiar</a>
        </div>

    </form>
</div>

<div class="erp-table-card">
    <div class="table-topbar">
        <h3>Entradas registradas</h3>
    </div>

    <div class="table-responsive">
        <table class="erp-table tabla-historial">
            <thead>
                <tr>
                    <th>Folio</th>
                    <th>Fecha</th>
                    <th>Movimiento</th>
                    <th>Almacén</th>
                    <th>Proveedor</th>
                    <th>Usuario</th>
                    <th>Productos</th>
                    <th>Unidades</th>
                    <th>Total</th>
                    <th>Acciones</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!empty($entradas)): ?>

                    <?php foreach ($entradas as $entrada): ?>
                        <?php
                            $entradaDetalle = $controller->obtenerEntrada((int)$entrada['id']);

                            $referenciaCompleta = trim($entrada['referencia'] ?? '');
                            $partesReferencia = explode('|', $referenciaCompleta);
                            $movimientoTexto = trim($partesReferencia[0] ?? 'Entrada de almacén');

                            $fecha = '';
                            if (!empty($entrada['fecha'])) {
                                $fecha = date('d/m/Y', strtotime($entrada['fecha']));
                            }

                            $detalleId = 'detalleEntrada' . (int)$entrada['id'];
                            $estaCancelada = (int)($entrada['cancelado'] ?? 0) === 1;
                        ?>

                        <tr>
                            <td class="folio-cell">
                                <div class="folio-wrap">
                                    <span class="folio-text">
                                        <?= e($entrada['folio']) ?>
                                    </span>

                                    <?php if ($estaCancelada): ?>
                                        <span class="badge-cancelado">
                                            CANCELADO
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td><?= e($fecha) ?></td>

                            <td><?= e($movimientoTexto) ?></td>

                            <td><?= e($entrada['almacen_nombre'] ?? '') ?></td>

                            <td><?= e($entrada['proveedor_nombre'] ?? '') ?></td>

                            <td><?= e($entrada['usuario_nombre'] ?? '') ?></td>

                            <td class="text-right">
                                <?= number_format((int)$entrada['total_productos']) ?>
                            </td>

                            <td class="text-right">
                                <?= number_format((int)$entrada['total_unidades']) ?>
                            </td>

                            <td class="text-right">
                                $<?= number_format((float)$entrada['total'], 2) ?>
                            </td>

                            <td>
                                <div class="table-actions">

                                    <button 
                                        type="button" 
                                        class="btn-small btn-detail"
                                        onclick="toggleDetalle('<?= e($detalleId) ?>')"
                                    >
                                        Ver detalle
                                    </button>

                                    <a 
                                        class="btn-small btn-print"
                                        href="imprimir_entrada.php?id=<?= (int)$entrada['id'] ?>&preview=1"
                                        target="_blank"
                                    >
                                        Reimprimir
                                    </a>

                                    <?php if (!$estaCancelada): ?>

                                        <a
                                            href="entradas.php?editar=<?= (int)$entrada['id'] ?>"
                                            class="btn-small btn-edit"
                                        >
                                            Editar
                                        </a>

                                        <form 
                                            method="POST" 
                                            style="display:inline;"
                                            onsubmit="return confirm('¿Seguro que deseas cancelar esta entrada? Se descontará el stock ingresado.');"
                                        >
                                            <input 
                                                type="hidden" 
                                                name="accion" 
                                                value="cancelar_entrada"
                                            >

                                            <input 
                                                type="hidden" 
                                                name="movimiento_id" 
                                                value="<?= (int)$entrada['id'] ?>"
                                            >

                                            <input 
                                                type="hidden" 
                                                name="motivo_cancelacion" 
                                                value="Cancelado desde historial de entradas"
                                            >

                                            <button 
                                                type="submit" 
                                                class="btn-small btn-cancel"
                                            >
                                                Cancelar
                                            </button>
                                        </form>

                                    <?php else: ?>

                                        <span class="badge-cancelado">
                                            CANCELADO
                                        </span>

                                    <?php endif; ?>

                                </div>
                            </td>
                        </tr>

                        <tr id="<?= e($detalleId) ?>" class="detalle-row" style="display:none;">
                            <td colspan="10">
                                <div class="detalle-box">
                                    <h4>Detalle de productos</h4>

                                    <?php if ($estaCancelada): ?>
                                        <div class="alert alert-error" style="margin: 10px 0;">
                                            Esta entrada fue cancelada.

                                            <?php if (!empty($entrada['fecha_cancelacion'])): ?>
                                                Fecha de cancelación:
                                                <?= e(date('d/m/Y H:i', strtotime($entrada['fecha_cancelacion']))) ?>.
                                            <?php endif; ?>

                                            <?php if (!empty($entrada['motivo_cancelacion'])): ?>
                                                Motivo:
                                                <?= e($entrada['motivo_cancelacion']) ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <table class="detalle-table">
                                        <thead>
                                            <tr>
                                                <th>Cantidad</th>
                                                <th>Código</th>
                                                <th>Descripción</th>
                                                <th>Lote</th>
                                                <th>Caducidad</th>
                                                <th>Ubicación</th>
                                                <th>Costo U.</th>
                                                <th>Importe</th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <?php if (!empty($entradaDetalle['detalles'])): ?>

                                                <?php foreach ($entradaDetalle['detalles'] as $detalle): ?>
                                                    <?php
                                                        $cantidad = (int)($detalle['cantidad'] ?? 0);
                                                        $costo = (float)($detalle['costo_unitario'] ?? 0);
                                                        $importe = $cantidad * $costo;

                                                        $caducidad = '';
                                                        if (!empty($detalle['fecha_caducidad'])) {
                                                            $caducidad = date('d/m/Y', strtotime($detalle['fecha_caducidad']));
                                                        }
                                                    ?>

                                                    <tr>
                                                        <td class="text-right">
                                                            <?= number_format($cantidad) ?>
                                                        </td>

                                                        <td>
                                                            <?= e($detalle['codigo'] ?? '') ?>
                                                        </td>

                                                        <td>
                                                            <?= e($detalle['descripcion'] ?? '') ?>
                                                        </td>

                                                        <td>
                                                            <?= e($detalle['numero_lote'] ?? '') ?>
                                                        </td>

                                                        <td>
                                                            <?= e($caducidad) ?>
                                                        </td>

                                                        <td>
                                                            <?= e($detalle['ubicacion'] ?? '') ?>
                                                        </td>

                                                        <td class="text-right">
                                                            $<?= number_format($costo, 2) ?>
                                                        </td>

                                                        <td class="text-right">
                                                            $<?= number_format($importe, 2) ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>

                                            <?php else: ?>

                                                <tr>
                                                    <td colspan="8" class="empty-table">
                                                        No hay productos en esta entrada.
                                                    </td>
                                                </tr>

                                            <?php endif; ?>
                                        </tbody>
                                    </table>

                                    <?php if (!empty($entrada['observaciones'])): ?>
                                        <div class="observaciones-detalle">
                                            <strong>Observaciones:</strong>
                                            <?= e($entrada['observaciones']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="10" class="empty-table">
                            No se encontraron entradas con los filtros seleccionados.
                        </td>
                    </tr>

                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function toggleDetalle(id) {
    const fila = document.getElementById(id);

    if (!fila) return;

    fila.style.display = fila.style.display === 'none'
        ? 'table-row'
        : 'none';
}
</script>

<?php
include __DIR__ . '/../app/views/layouts/footer.php';
?>