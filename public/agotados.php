<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/AgotadoController.php';

requireLogin();

$controller = new AgotadoController();

$user = currentUser();
$rol = strtoupper(trim($user['rol'] ?? ''));
$esAdmin = in_array($rol, ['ADMINISTRADOR', 'ADMIN'], true);

$filtros = [
    'buscar' => trim($_GET['buscar'] ?? ''),
    'almacen_id' => isset($_GET['almacen_id']) ? (int)$_GET['almacen_id'] : 0,
];

$almacenes = $controller->almacenes();
$productos = $controller->listar($filtros);

$moduleCss = 'agotados';
include __DIR__ . '/../app/views/layouts/header.php';
?>

<div class="agotados-page">

    <div class="module-header">
        <div>
            <h2>Agotados</h2>
            <p>Productos sin ubicación o sin existencia registrados en el almacén.</p>
        </div>

        <div class="contador-agotados">
            <span>Total registros</span>
            <strong><?= number_format(count($productos)) ?></strong>
        </div>
    </div>

    <div class="agotados-filter-card">
        <form method="GET" action="agotados.php" class="agotados-filter-form">
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
                        <option value="0">Todos</option>
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
    <a href="agotados.php">Limpiar</a>

    <a 
        href="exportar_agotados_excel.php?<?= http_build_query($filtros) ?>" 
        class="btn-excel"
    >
        Exportar Excel
    </a>
</div>
        </form>
    </div>

    <div class="agotados-table-card">
        <div class="table-topbar">
            <h3>Listado de productos agotados</h3>
            <span><?= number_format(count($productos)) ?> registros encontrados</span>
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
                    </tr>
                </thead>

                <tbody>
                    <?php if (!empty($productos)): ?>
                        <?php foreach ($productos as $producto): ?>
                            <?php
                                $existencia = (int)($producto['existencia'] ?? 0);
                                $ubicacion = trim($producto['ubicacion'] ?? '');

                                if ($existencia <= 0 && ($ubicacion === '' || strtoupper($ubicacion) === 'SIN UBICACION')) {
                                    $motivo = 'SIN UBICACIÓN Y SIN EXISTENCIA';
                                } elseif ($existencia <= 0) {
                                    $motivo = 'SIN EXISTENCIA';
                                } else {
                                    $motivo = 'SIN UBICACIÓN';
                                }
                            ?>

                            <tr>
                                <td><?= e($producto['codigo'] ?? '') ?></td>
                                <td><?= e($producto['codigo_barras'] ?? '') ?></td>
                                <td class="descripcion"><?= e($producto['descripcion'] ?? '') ?></td>
                                <td><?= e($producto['categoria'] ?? 'Sin categoría') ?></td>
                                <td><?= e($producto['proveedor'] ?? 'Sin proveedor') ?></td>
                                <td><?= e($producto['laboratorio'] ?? '') ?></td>
                                <td><?= e($producto['sucursal'] ?? 'SIN ALMACEN') ?></td>
                                <td><?= e($producto['ubicacion'] ?? 'SIN UBICACION') ?></td>
                                <td class="text-right"><?= number_format($existencia) ?></td>
                                <td>
                                    <span class="badge-agotado"><?= e($motivo) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="empty-table">
                                No se encontraron productos agotados.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>