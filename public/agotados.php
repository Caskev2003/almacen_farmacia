<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/AgotadoController.php';

requireLogin();

$controller = new AgotadoController();

$user = currentUser();
$rol = strtoupper(trim($user['rol'] ?? ''));
$esAdmin = in_array($rol, ['ADMINISTRADOR', 'ADMIN'], true);

$almacenIdSesion = (int)($user['almacen_id'] ?? 0);

if ($almacenIdSesion === 1) {
    $almacenSesionNombre = 'CIUDAD HIDALGO';
} elseif ($almacenIdSesion === 2 || $almacenIdSesion === 3) {
    $almacenSesionNombre = 'TUXTLA';
} else {
    $almacenSesionNombre = '';
}

$tipo = trim($_GET['tipo'] ?? 'sin_ubicacion');

if (!in_array($tipo, ['sin_ubicacion', 'sin_existencia', 'ambas'], true)) {
    $tipo = 'sin_ubicacion';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$filtros = [
    'buscar' => trim($_GET['buscar'] ?? ''),
    'almacen_id' => isset($_GET['almacen_id']) ? (int)$_GET['almacen_id'] : 0,
    'tipo' => $tipo,
    'pagina' => $page,
    'por_pagina' => $perPage,
];

if (!$esAdmin) {
    $filtros['almacen_id'] = $almacenIdSesion;
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'asignar_ubicacion') {
        $result = $controller->actualizarUbicacion($_POST);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'danger';
    }
}

$almacenes = $controller->almacenes();
$resumen = $controller->resumen($filtros);
$resultado = $controller->listar($filtros);

$productos = $resultado['items'] ?? [];
$totalRegistros = (int)($resultado['total'] ?? 0);
$totalPages = (int)($resultado['total_paginas'] ?? 1);
$page = (int)($resultado['pagina'] ?? 1);
$perPage = (int)($resultado['por_pagina'] ?? 25);

$queryExport = http_build_query($filtros);

function activeTab(string $actual, string $tab): string
{
    return $actual === $tab ? 'active' : '';
}

function urlTab(string $tipo, array $filtros): string
{
    $params = $filtros;
    $params['tipo'] = $tipo;
    $params['pagina'] = 1;

    return 'agotados.php?' . http_build_query($params);
}

$tituloTabla = match ($tipo) {
    'sin_existencia' => 'Productos sin existencia',
    'ambas' => 'Productos sin ubicación, sin existencia o sin almacén',
    default => 'Productos sin ubicación',
};

$queryPaginacion = $filtros;
unset($queryPaginacion['pagina'], $queryPaginacion['por_pagina']);

$moduleCss = 'agotados';
include __DIR__ . '/../app/views/layouts/header.php';
?>

<div class="agotados-page">

    <div class="module-header">
        <div>
            <h2>Agotados</h2>
            <p>
                Control de productos sin ubicación, sin existencia y sin almacén.
                <?php if (!$esAdmin && $almacenSesionNombre !== ''): ?>
                    <strong>Almacén actual: <?= e($almacenSesionNombre) ?></strong>
                <?php endif; ?>
            </p>
        </div>

        <div class="contador-agotados">
            <span>Inventario total</span>
            <strong><?= number_format((int)$resumen['inventario_total']) ?></strong>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= e($messageType) ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <div class="tabs-agotados">
        <a href="<?= e(urlTab('sin_ubicacion', $filtros)) ?>" class="<?= e(activeTab($tipo, 'sin_ubicacion')) ?>">
            <span>Sin ubicación</span>
            <strong><?= number_format((int)$resumen['sin_ubicacion']) ?></strong>
        </a>

        <a href="<?= e(urlTab('sin_existencia', $filtros)) ?>" class="<?= e(activeTab($tipo, 'sin_existencia')) ?>">
            <span>Sin existencia</span>
            <strong><?= number_format((int)$resumen['sin_existencia']) ?></strong>
        </a>

        <a href="<?= e(urlTab('ambas', $filtros)) ?>" class="<?= e(activeTab($tipo, 'ambas')) ?>">
            <span>Sin ubicación / sin existencia / sin almacén</span>
            <strong><?= number_format((int)$resumen['ambas']) ?></strong>
        </a>
    </div>

    <div class="agotados-filter-card">
        <form method="GET" action="agotados.php" class="agotados-filter-form">

            <input type="hidden" name="tipo" value="<?= e($tipo) ?>">

            <div class="field-search">
                <label>Buscar producto</label>
                <input
                    type="text"
                    name="buscar"
                    value="<?= e($filtros['buscar']) ?>"
                    placeholder="Código, barras, descripción, proveedor, categoría..."
                >
            </div>

            <?php if ($esAdmin): ?>
                <div>
                    <label>Almacén</label>
                    <select name="almacen_id">
                        <option value="0">Todos los almacenes</option>

                        <?php foreach ($almacenes as $almacen): ?>
                            <option
                                value="<?= (int)$almacen['id'] ?>"
                                <?= (int)$filtros['almacen_id'] === (int)$almacen['id'] ? 'selected' : '' ?>
                            >
                                <?= e($almacen['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="actions">
                <button type="submit">Filtrar</button>

                <a href="agotados.php?tipo=<?= e($tipo) ?>">
                    Limpiar
                </a>

                <a
                    href="exportar_agotados_excel.php?<?= e($queryExport) ?>"
                    class="btn-excel"
                >
                    Exportar Excel
                </a>
            </div>

        </form>
    </div>

    <div class="agotados-table-card">
        <div class="table-topbar">
            <h3><?= e($tituloTabla) ?></h3>
            <span><?= number_format($totalRegistros) ?> registros encontrados</span>
        </div>

        <div class="table-responsive">
            <table class="agotados-table">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Código barras</th>
                        <th>Descripción</th>
                        <th>Categoría</th>
                        <th>Proveedor</th>
                        <th>Laboratorio</th>
                        <th>Almacén</th>
                        <th>Ubicación</th>
                        <th>Existencia</th>
                        <th>Motivo</th>
                        <th>Acción</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!empty($productos)): ?>
                        <?php foreach ($productos as $producto): ?>
                            <?php
                                $existencia = (int)($producto['existencia'] ?? 0);
                                $sucursal = trim($producto['sucursal'] ?? '');
                                $motivo = $producto['motivo'] ?? 'PENDIENTE';
                            ?>

                            <tr>
                                <td><?= e($producto['codigo'] ?? '') ?></td>
                                <td><?= e($producto['codigo_barras'] ?? '') ?></td>
                                <td class="descripcion"><?= e($producto['descripcion'] ?? '') ?></td>
                                <td><?= e($producto['categoria'] ?? 'Sin categoría') ?></td>
                                <td><?= e($producto['proveedor'] ?? 'Sin proveedor') ?></td>
                                <td><?= e($producto['laboratorio'] ?? '') ?></td>
                                <td><?= e($sucursal !== '' ? $sucursal : 'SIN ALMACEN') ?></td>
                                <td><?= e($producto['ubicacion'] ?? 'SIN UBICACION') ?></td>
                                <td class="text-right"><?= number_format($existencia) ?></td>
                                <td>
                                    <span class="badge-agotado">
                                        <?= e($motivo) ?>
                                    </span>
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        class="btn-asignar"
                                        onclick="abrirModalAsignarUbicacion(
                                            '<?= (int)$producto['id'] ?>',
                                            '<?= e($producto['descripcion'] ?? '') ?>',
                                            '<?= e($sucursal) ?>',
                                            '<?= (int)$existencia ?>'
                                        )"
                                    >
                                        Asignar ubicación
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" class="empty-table">
                                No se encontraron registros en esta pestaña.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination-wrapper">
                <div class="pagination-info">
                    Página <?= $page ?> de <?= $totalPages ?> |
                    Total: <?= number_format($totalRegistros) ?> registros
                </div>

                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="agotados.php?page=1&<?= e(http_build_query($queryPaginacion)) ?>">Primera</a>
                        <a href="agotados.php?page=<?= $page - 1 ?>&<?= e(http_build_query($queryPaginacion)) ?>">Anterior</a>
                    <?php else: ?>
                        <span class="disabled">Primera</span>
                        <span class="disabled">Anterior</span>
                    <?php endif; ?>

                    <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);

                        for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <?php if ($i === $page): ?>
                            <span class="active"><?= $i ?></span>
                        <?php else: ?>
                            <a href="agotados.php?page=<?= $i ?>&<?= e(http_build_query($queryPaginacion)) ?>">
                                <?= $i ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="agotados.php?page=<?= $page + 1 ?>&<?= e(http_build_query($queryPaginacion)) ?>">Siguiente</a>
                        <a href="agotados.php?page=<?= $totalPages ?>&<?= e(http_build_query($queryPaginacion)) ?>">Última</a>
                    <?php else: ?>
                        <span class="disabled">Siguiente</span>
                        <span class="disabled">Última</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>

<div id="modalAsignarUbicacion" class="modal-agotado">
    <div class="modal-agotado-card">
        <div class="modal-agotado-header">
            <div>
                <h3 id="tituloAsignarUbicacion">Asignar ubicación</h3>
                <p>Al asignar ubicación y existencia, el producto saldrá automáticamente de Agotados.</p>
            </div>

            <button type="button" onclick="cerrarModalAsignarUbicacion()">
                &times;
            </button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="asignar_ubicacion">
            <input type="hidden" name="producto_id" id="agotadoProductoId">
            <input type="hidden" name="sucursal" id="agotadoSucursal">

            <div class="form-group">
                <label>Ubicación nueva</label>
                <input
                    type="text"
                    name="ubicacion_nueva"
                    id="agotadoUbicacionNueva"
                    list="listaUbicaciones"
                    required
                    autocomplete="off"
                    placeholder="Ejemplo: R1N1Z01"
                >
            </div>

            <div class="form-group">
                <label>Existencia</label>
                <input
                    type="number"
                    name="existencia"
                    id="agotadoExistencia"
                    min="1"
                    required
                >
            </div>

            <button type="submit" class="btn-guardar-ubicacion">
                Guardar ubicación
            </button>
        </form>
    </div>
</div>

<datalist id="listaUbicaciones"></datalist>

<script>
function abrirModalAsignarUbicacion(productoId, descripcion, sucursal, existencia) {
    document.getElementById('modalAsignarUbicacion').style.display = 'flex';
    document.getElementById('agotadoProductoId').value = productoId;
    document.getElementById('agotadoSucursal').value = sucursal;
    document.getElementById('agotadoExistencia').value = existencia > 0 ? existencia : 1;
    document.getElementById('tituloAsignarUbicacion').innerText = descripcion;
}

function cerrarModalAsignarUbicacion() {
    document.getElementById('modalAsignarUbicacion').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function () {
    const lista = document.getElementById('listaUbicaciones');

    if (!lista) return;

    const ubicaciones = [];

    function add(rack, nivel, zona) {
        const z = String(zona).padStart(2, '0');
        ubicaciones.push(`R${rack}N${nivel}Z${z}`);
    }

    for (let n = 1; n <= 3; n++) for (let z = 1; z <= 22; z++) add(1, n, z);
    for (let n = 1; n <= 3; n++) for (let z = 1; z <= 20; z++) add(2, n, z);
    for (let n = 1; n <= 3; n++) for (let z = 1; z <= 20; z++) add(3, n, z);

    for (let n = 1; n <= 2; n++) for (let z = 1; z <= 16; z++) add(4, n, z);
    for (let z = 10; z <= 16; z++) add(4, 3, z);

    for (let n = 1; n <= 2; n++) for (let z = 1; z <= 15; z++) add(5, n, z);
    for (let z = 10; z <= 15; z++) add(5, 3, z);

    for (let n = 1; n <= 3; n++) for (let z = 1; z <= 22; z++) add(6, n, z);

    ubicaciones.push('R7N1Z01 - PASILLO 3');
    ubicaciones.push('R8N1Z01 - PASILLO 2');
    ubicaciones.push('R9N1Z01 - PASILLO 1');
    ubicaciones.push('BODEGA PEDYALITE');

    lista.innerHTML = '';

    ubicaciones.forEach(u => {
        const option = document.createElement('option');
        option.value = u;
        lista.appendChild(option);
    });
});
</script>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>