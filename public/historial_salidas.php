<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/SalidaController.php';

requireLogin();

$user = currentUser();
$controller = new SalidaController();

$rolUsuario = strtoupper(trim($user['rol'] ?? ''));
$almacenSesion = (int)($user['almacen_id'] ?? 0);

$buscar = trim($_GET['buscar'] ?? '');
$fechaInicio = trim($_GET['fecha_inicio'] ?? '');
$fechaFinal = trim($_GET['fecha_final'] ?? '');

if ($rolUsuario === 'ADMINISTRADOR') {
    $almacenId = isset($_GET['almacen_id']) ? (int)$_GET['almacen_id'] : 0;
} else {
    $almacenId = $almacenSesion;
}

$mensaje = '';
$tipoMensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['accion'] ?? '') === 'cancelar_salida'
) {
    $movimientoId = (int)($_POST['movimiento_id'] ?? 0);

    $motivo = trim(
        $_POST['motivo_cancelacion']
        ?? 'Cancelado desde historial de salidas'
    );

    $resultado = $controller->cancelarSalida(
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

$salidas = $controller->historialSalidas(
    $buscar,
    $almacenId,
    $fechaInicio,
    $fechaFinal
);

$totalSalidas = count($salidas);
$totalProductos = 0;
$totalUnidades = 0;
$detallesSalidas = [];

foreach ($salidas as $salida) {
    $totalProductos += (int)($salida['total_productos'] ?? 0);
    $totalUnidades += (int)($salida['total_unidades'] ?? 0);
}

/*
 * Carga todos los detalles con una sola consulta. Antes se ejecutaban
 * varias consultas por cada salida dentro de la tabla; con cientos de
 * registros el servidor agotaba el tiempo y dejaba el cuerpo vacío.
 */
if (!empty($salidas)) {
    try {
        $detallesSalidas = $controller->obtenerDetallesSalidas(
            array_column($salidas, 'id')
        );
    } catch (Throwable $e) {
        error_log(
            'Error al cargar detalles del historial de salidas: '
            . $e->getMessage()
        );

        $detallesSalidas = [];
        $mensaje = 'Las salidas se cargaron, pero no fue posible consultar sus detalles.';
        $tipoMensaje = 'error';
    }
}

$moduleCss = 'historial_entradas';

include __DIR__ . '/../app/views/layouts/header.php';
?>

<style>
.tabla-historial tbody tr:not(.detalle-row) {
    display: table-row !important;
    background: #ffffff !important;
    color: #111827 !important;
}

.tabla-historial tbody td {
    display: table-cell !important;
    color: #111827 !important;
    padding: 10px !important;
    border-bottom: 1px solid #e5e7eb !important;
    vertical-align: middle !important;
}

.detalle-row {
    background: #f8fafc !important;
}

.detalle-row[style*="display:none"] {
    display: none !important;
}

.detalle-box {
    padding: 15px;
    background: #f8fafc;
    border-radius: 10px;
}

.detalle-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}

.detalle-table th {
    background: #1e293b;
    color: #fff;
    padding: 8px;
}

.detalle-table td {
    padding: 8px !important;
    border-bottom: 1px solid #cbd5e1 !important;
}

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
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 15px;
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
    align-items: flex-start;
    gap: 5px;
}

.folio-text {
    font-weight: 800;
    color: #0f172a;
}

.control-interno-text {
    display: inline-flex;
    align-items: center;
    white-space: nowrap;
    color: #075985;
    background: #e0f2fe;
    border: 1px solid #7dd3fc;
    border-radius: 999px;
    padding: 4px 8px;
    font-size: 11px;
    font-weight: 800;
}

.control-interno-vacio {
    color: #94a3b8;
}
</style>

<div class="module-header">
    <div>
        <h2>Historial de Salidas</h2>
        <p>Consulta, filtra, reimprime, edita o cancela salidas registradas en el almacén.</p>
    </div>
</div>

<?php if ($mensaje !== ''): ?>
    <div class="alert <?= $tipoMensaje === 'success' ? 'alert-success' : 'alert-error' ?>">
        <?= e($mensaje) ?>
    </div>
<?php endif; ?>

<div class="historial-resumen-grid">
    <div class="historial-card">
        <span>Total salidas</span>
        <strong><?= number_format($totalSalidas) ?></strong>
    </div>

    <div class="historial-card">
        <span>Productos capturados</span>
        <strong><?= number_format($totalProductos) ?></strong>
    </div>

    <div class="historial-card">
        <span>Unidades salidas</span>
        <strong><?= number_format($totalUnidades) ?></strong>
    </div>
</div>

<div class="historial-filter-card">
    <form method="GET" action="historial_salidas.php" class="historial-filter-form">

        <div class="historial-field search-field">
            <label>Buscar</label>
            <input
                type="text"
                name="buscar"
                value="<?= e($buscar) ?>"
                placeholder="Folio de salida, control interno, ticket, usuario o producto..."
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
                <input type="hidden" name="almacen_id" value="<?= (int)$almacenSesion ?>">
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
            <a href="historial_salidas.php" class="btn-secondary-action">Limpiar</a>
        </div>

    </form>
</div>

<div class="erp-table-card">
    <div class="table-topbar">
        <h3>Salidas registradas</h3>
    </div>

    <div class="table-responsive">
        <table class="erp-table tabla-historial">
            <thead>
                <tr>
                    <th>Folio</th>
                    <th>Control interno</th>
                    <th>Fecha</th>
                    <th>Movimiento</th>
                    <th>Documento</th>
                    <th>Almacén</th>
                    <th>Usuario</th>
                    <th>Productos</th>
                    <th>Unidades</th>
                    <th>Total</th>
                    <th>Acciones</th>
                </tr>
            </thead>

            <tbody>
                <?php if (empty($salidas)): ?>
                    <tr>
                        <td colspan="11" class="empty-table">
                            No hay salidas registradas.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($salidas as $salida): ?>
                        <?php
                            $detalleId = 'detalleSalida' . (int)$salida['id'];
                            $detalleProductos = $detallesSalidas[
                                (int)$salida['id']
                            ] ?? [];
                            $estaCancelada = (int)($salida['cancelado'] ?? 0) === 1;
                        ?>

                        <tr>
                            <td class="folio-cell">
                                <div class="folio-wrap">
                                    <span class="folio-text">
                                        <?= e($salida['folio']) ?>
                                    </span>

                                    <?php if ($estaCancelada): ?>
                                        <span class="badge-cancelado">
                                            CANCELADO
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td>
                                <?php if (!empty($salida['folio_control_interno'])): ?>
                                    <span class="control-interno-text">
                                        <?= e($salida['folio_control_interno']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="control-interno-vacio">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= !empty($salida['fecha']) ? date('d/m/Y H:i', strtotime($salida['fecha'])) : '' ?>
                            </td>

                            <td><?= e($salida['referencia'] ?? '') ?></td>

                            <td><?= e($salida['tipo_operacion'] ?? '') ?></td>

                            <td><?= e($salida['almacen_nombre'] ?? '') ?></td>

                            <td><?= e($salida['usuario_nombre'] ?? '') ?></td>

                            <td class="text-right">
                                <?= number_format((int)($salida['total_productos'] ?? 0)) ?>
                            </td>

                            <td class="text-right">
                                <?= number_format((int)($salida['total_unidades'] ?? 0)) ?>
                            </td>

                            <td class="text-right">
                                $<?= number_format((float)($salida['total'] ?? 0), 2) ?>
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
                                        href="imprimir_salida.php?id=<?= (int)$salida['id'] ?>&preview=1"
                                        class="btn-small btn-print"
                                        target="_blank"
                                    >
                                        Reimprimir
                                    </a>

                                    <?php if (!$estaCancelada): ?>
                                        <a
                                            href="salidas.php?editar=<?= (int)$salida['id'] ?>"
                                            class="btn-small btn-edit"
                                        >
                                            Editar
                                        </a>

                                        <form
                                            method="POST"
                                            style="display:inline"
                                            onsubmit="return confirm(
                                                '¿Seguro que deseas cancelar esta salida?\n\nEl stock regresará a las ubicaciones originales.'
                                            );"
                                        >
                                            <input
                                                type="hidden"
                                                name="accion"
                                                value="cancelar_salida"
                                            >

                                            <input
                                                type="hidden"
                                                name="movimiento_id"
                                                value="<?= (int)$salida['id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="motivo_cancelacion"
                                                value="Cancelado desde historial de salidas"
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
                            <td colspan="11">
                                <div class="detalle-box">
                                    <h4>Detalle de productos</h4>

                                    <?php if ($estaCancelada): ?>
                                        <div class="alert alert-error" style="margin: 10px 0;">
                                            Esta salida fue cancelada.
                                            <?php if (!empty($salida['fecha_cancelacion'])): ?>
                                                Fecha de cancelación:
                                                <?= e(date('d/m/Y H:i', strtotime($salida['fecha_cancelacion']))) ?>.
                                            <?php endif; ?>

                                            <?php if (!empty($salida['motivo_cancelacion'])): ?>
                                                Motivo:
                                                <?= e($salida['motivo_cancelacion']) ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <table class="detalle-table">
                                        <thead>
                                            <tr>
                                                <th>Cantidad</th>
                                                <th>Código</th>
                                                <th>Descripción</th>
                                                <th>Ubicación</th>
                                                <th>Precio U.</th>
                                                <th>Importe</th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <?php if (!empty($detalleProductos)): ?>
                                                <?php foreach ($detalleProductos as $item): ?>
                                                    <?php
                                                        $cantidad = (int)($item['cantidad'] ?? 0);
                                                        $precio = (float)($item['precio_unitario'] ?? 0);
                                                        $importe = $cantidad * $precio;
                                                    ?>

                                                    <tr>
                                                        <td class="text-right"><?= number_format($cantidad) ?></td>
                                                        <td><?= e($item['codigo'] ?? '') ?></td>
                                                        <td><?= e($item['descripcion'] ?? '') ?></td>
                                                        <td><?= e($item['ubicacion'] ?? '') ?></td>
                                                        <td class="text-right">$<?= number_format($precio, 2) ?></td>
                                                        <td class="text-right">$<?= number_format($importe, 2) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="empty-table">
                                                        No hay productos.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>

                                    <?php if (!empty($salida['observaciones'])): ?>
                                        <div class="observaciones-detalle">
                                            <strong>Observaciones:</strong>
                                            <?= e($salida['observaciones']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

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
if (file_exists(__DIR__ . '/../app/views/layouts/footer.php')) {
    include __DIR__ . '/../app/views/layouts/footer.php';
}
?>
