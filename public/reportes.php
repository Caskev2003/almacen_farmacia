<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/ReporteController.php';

requireLogin();

$user = currentUser();
$rol = strtoupper($user['rol'] ?? '');
$isAdmin = $rol === 'ADMINISTRADOR';

$sucursalUsuario = $user['almacen_nombre'] 
    ?? $user['sucursal'] 
    ?? $user['almacen'] 
    ?? '';

$controller = new ReporteController();

$columnasDisponibles = $controller->columnasDisponibles();
$almacenes = $controller->obtenerAlmacenes();

$columnasPredeterminadas = [
    'codigo',
    'descripcion',
    'sucursal',
    'ubicacion',
    'existencia',
    'estado_stock'
];

$columnasSeleccionadas = $_GET['columnas'] ?? $columnasPredeterminadas;

if (!is_array($columnasSeleccionadas)) {
    $columnasSeleccionadas = [];
}

$filtros = [
    'buscar' => trim($_GET['buscar'] ?? ''),
    'sucursal' => trim($_GET['sucursal'] ?? ''),
    'rack' => trim($_GET['rack'] ?? ''),
    'existencia' => trim($_GET['existencia'] ?? ''),
    'orden' => trim($_GET['orden'] ?? 'descripcion'),
];

$datos = $controller->obtenerDatosReporte(
    $filtros,
    $columnasSeleccionadas,
    $isAdmin,
    $sucursalUsuario
);

$moduleCss = 'reportes';
include __DIR__ . '/../app/views/layouts/header.php';

$queryExport = http_build_query([
    'buscar' => $filtros['buscar'],
    'sucursal' => $filtros['sucursal'],
    'rack' => $filtros['rack'],
    'existencia' => $filtros['existencia'],
    'orden' => $filtros['orden'],
    'columnas' => $columnasSeleccionadas
]);
?>

<div class="reportes-page">

    <div class="module-header">
        <div>
            <h2>Reportes</h2>
            <p>Genera reportes personalizados por ubicación, rack, existencia y almacén.</p>
        </div>
    </div>

    <form method="GET" class="report-card filtros-card">

        <h3>Filtros del reporte</h3>

        <div class="filtros-grid">

            <div class="form-group">
                <label>Buscar producto</label>
                <input 
                    type="text" 
                    name="buscar" 
                    value="<?= e($filtros['buscar']) ?>" 
                    placeholder="Código, descripción, proveedor, ubicación..."
                >
            </div>

            <?php if ($isAdmin): ?>
                <div class="form-group">
                    <label>Almacén</label>
                    <select name="sucursal">
                        <option value="">Todos</option>
                        <?php foreach ($almacenes as $almacen): ?>
                            <option 
                                value="<?= e($almacen['sucursal']) ?>"
                                <?= $filtros['sucursal'] === $almacen['sucursal'] ? 'selected' : '' ?>
                            >
                                <?= e($almacen['sucursal']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Rack</label>
                <select name="rack">
                    <option value="">Todos</option>
                    <?php for ($i = 1; $i <= 9; $i++): ?>
                        <?php $rack = 'R' . $i; ?>
                        <option value="<?= $rack ?>" <?= $filtros['rack'] === $rack ? 'selected' : '' ?>>
                            <?= $rack ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Existencia</label>
                <select name="existencia">
                    <option value="">Todas</option>
                    <option value="agotado" <?= $filtros['existencia'] === 'agotado' ? 'selected' : '' ?>>Agotados</option>
                    <option value="bajo" <?= $filtros['existencia'] === 'bajo' ? 'selected' : '' ?>>Stock bajo</option>
                    <option value="normal" <?= $filtros['existencia'] === 'normal' ? 'selected' : '' ?>>Stock normal</option>
                </select>
            </div>

            <div class="form-group">
                <label>Ordenar por</label>
                <select name="orden">
                    <option value="descripcion" <?= $filtros['orden'] === 'descripcion' ? 'selected' : '' ?>>Descripción</option>
                    <option value="codigo" <?= $filtros['orden'] === 'codigo' ? 'selected' : '' ?>>Código</option>
                    <option value="ubicacion" <?= $filtros['orden'] === 'ubicacion' ? 'selected' : '' ?>>Ubicación</option>
                    <option value="existencia" <?= $filtros['orden'] === 'existencia' ? 'selected' : '' ?>>Existencia</option>
                    <option value="sucursal" <?= $filtros['orden'] === 'sucursal' ? 'selected' : '' ?>>Almacén</option>
                </select>
            </div>

        </div>

        <h3>Columnas que llevará el reporte</h3>

        <div class="checkbox-grid">
            <?php foreach ($columnasDisponibles as $key => $label): ?>
                <label class="check-item">
                    <input 
                        type="checkbox" 
                        name="columnas[]" 
                        value="<?= e($key) ?>"
                        <?= in_array($key, $columnasSeleccionadas, true) ? 'checked' : '' ?>
                    >
                    <span><?= e($label) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="acciones-reportes">
            <button type="submit" class="btn btn-primary">Generar reporte</button>

            <a 
                href="exportar_reporte_excel.php?<?= $queryExport ?>" 
                class="btn btn-success"
            >
                Exportar Excel
            </a>

            <a 
                href="exportar_reporte_pdf.php?<?= $queryExport ?>" 
                target="_blank"
                class="btn btn-danger"
            >
                Exportar PDF
            </a>
        </div>

    </form>

    <div class="report-card">
        <div class="tabla-header">
            <h3>Resultado del reporte</h3>
            <span>Total: <?= count($datos) ?> registros</span>
        </div>

        <div class="table-responsive">
            <table class="report-table">
                <thead>
                    <tr>
                        <?php foreach ($columnasSeleccionadas as $col): ?>
                            <?php if (isset($columnasDisponibles[$col])): ?>
                                <th><?= e($columnasDisponibles[$col]) ?></th>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($datos)): ?>
                        <tr>
                            <td colspan="<?= max(count($columnasSeleccionadas), 1) ?>" class="empty">
                                No se encontraron datos.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($datos as $fila): ?>
                            <tr>
                                <?php foreach ($columnasSeleccionadas as $col): ?>
                                    <?php if (isset($columnasDisponibles[$col])): ?>
                                        <td>
                                            <?php if ($col === 'estado_stock'): ?>
                                                <span class="badge-stock <?= strtolower(str_replace(' ', '-', $fila[$col])) ?>">
                                                    <?= e($fila[$col]) ?>
                                                </span>
                                            <?php elseif ($col === 'costo_ultimo' && $isAdmin): ?>
                                                $<?= number_format((float)($fila['costo_ultimo'] ?? $fila['precio_compra'] ?? 0), 2) ?>
                                            <?php elseif ($col === 'costo_promedio' && $isAdmin): ?>
                                                $<?= number_format((float)($fila['costo_promedio'] ?? $fila['costo_ultimo'] ?? $fila['precio_compra'] ?? 0), 4) ?>
                                            <?php elseif ($col === 'valor_costo_promedio' && $isAdmin): ?>
                                                $<?= number_format((float)($fila['valor_costo_promedio'] ?? 0), 2) ?>
                                            <?php else: ?>
                                                <?= e((string)($fila[$col] ?? '')) ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>


<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>