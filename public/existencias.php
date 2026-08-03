<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/ExistenciaController.php';

requireLogin();

$controller = new ExistenciaController();
$user = currentUser();

$rolUsuario = strtoupper(trim($user['rol'] ?? ''));
$esAdmin = in_array($rolUsuario, ['ADMINISTRADOR', 'ADMIN'], true);

$filtros = [
    'buscar' => trim($_GET['buscar'] ?? ''),
    'almacen_id' => isset($_GET['almacen_id']) ? (int)$_GET['almacen_id'] : 0,
    'estado_stock' => trim($_GET['estado_stock'] ?? ''),
    'rack' => trim($_GET['rack'] ?? ''),
    'categoria_id' => trim($_GET['categoria_id'] ?? ''),
    'proveedor_id' => trim($_GET['proveedor_id'] ?? ''),
    'orden' => trim($_GET['orden'] ?? 'descripcion'),
];

$almacenes = $controller->almacenes();
$categorias = $controller->categorias();
$proveedores = $controller->proveedores();

$productos = $controller->index($filtros);
$resumen = $controller->resumen($productos);
$valorInventario = $esAdmin
    ? $controller->valorInventario(
        (int) $filtros['almacen_id']
    )
    : null;

$moduleCss = 'existencias';

include __DIR__ . '/../app/views/layouts/header.php';

?>

<div class="existencias-page">

    <div class="module-header existencias-header">
        <div>
            <h2>Existencias</h2>
            <p>
                Consulta general de inventario por almacén, rack,
                categoría, proveedor y estado de stock.
            </p>
        </div>
    </div>

    <div class="existencias-resumen-grid">

        <div class="existencia-card total">
            <span>Total productos</span>
            <strong><?= number_format((int)$resumen['totalProductos']) ?></strong>
        </div>

        <div class="existencia-card unidades">
            <span>Total unidades</span>
            <strong><?= number_format((int)$resumen['totalUnidades']) ?></strong>
        </div>

        <div class="existencia-card normal">
            <span>Stock normal</span>
            <strong><?= number_format((int)$resumen['stockNormal']) ?></strong>
        </div>

        <div class="existencia-card warning">
            <span>Stock bajo</span>
            <strong><?= number_format((int)$resumen['stockBajo']) ?></strong>
        </div>

        <div class="existencia-card danger">
            <span>Sin existencia</span>
            <strong><?= number_format((int)$resumen['sinExistencia']) ?></strong>
        </div>

        <div class="existencia-card dark">
            <span>Sin almacén</span>
            <strong><?= number_format((int)$resumen['sinAlmacen']) ?></strong>
        </div>

        <?php if ($esAdmin): ?>

            <div class="existencia-card valor">
                <span>
                    <?= (int) $filtros['almacen_id'] > 0
                        ? 'Valor total del almacén'
                        : 'Valor total de todos los almacenes' ?>
                </span>
                <strong>
                    $<?= number_format((float) $valorInventario, 2) ?>
                </strong>
            </div>

        <?php endif; ?>

    </div>

    <div class="existencias-filter-card">

        <form method="GET" action="existencias.php" class="existencias-filter-form">

            <div class="existencia-field search-field">
                <label>Buscar general</label>

                <input
                    type="text"
                    name="buscar"
                    value="<?= e($filtros['buscar']) ?>"
                    placeholder="Código, barras, descripción, proveedor, categoría, laboratorio o ubicación..."
                >
            </div>

            <?php if ($esAdmin): ?>

                <div class="existencia-field">
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

            <div class="existencia-field">
                <label>Rack</label>

                <select name="rack">

                    <option value="">Todos</option>

                    <?php for ($i = 1; $i <= 9; $i++): ?>

                        <?php $rack = 'R' . $i; ?>

                        <option
                            value="<?= $rack ?>"
                            <?= $filtros['rack'] === $rack ? 'selected' : '' ?>
                        >
                            <?= $rack ?>
                        </option>

                    <?php endfor; ?>

                </select>
            </div>

            <div class="existencia-field">
                <label>Estado</label>

                <select name="estado_stock">

                    <option value="">Todos</option>

                    <option
                        value="stock"
                        <?= $filtros['estado_stock'] === 'stock' ? 'selected' : '' ?>
                    >
                        Con stock
                    </option>

                    <option
                        value="normal"
                        <?= $filtros['estado_stock'] === 'normal' ? 'selected' : '' ?>
                    >
                        Stock normal
                    </option>

                    <option
                        value="bajo"
                        <?= $filtros['estado_stock'] === 'bajo' ? 'selected' : '' ?>
                    >
                        Stock bajo
                    </option>

                    <option
                        value="sin_existencia"
                        <?= $filtros['estado_stock'] === 'sin_existencia' ? 'selected' : '' ?>
                    >
                        Sin existencia
                    </option>

                    <option
                        value="sin_almacen"
                        <?= $filtros['estado_stock'] === 'sin_almacen' ? 'selected' : '' ?>
                    >
                        Sin almacén
                    </option>

                </select>
            </div>

            <div class="existencia-field">
                <label>Categoría</label>

                <select name="categoria_id">

                    <option value="">Todas</option>

                    <?php foreach ($categorias as $categoria): ?>

                        <option
                            value="<?= (int)$categoria['id'] ?>"
                            <?= (string)$filtros['categoria_id'] === (string)$categoria['id'] ? 'selected' : '' ?>
                        >
                            <?= e($categoria['nombre']) ?>
                        </option>

                    <?php endforeach; ?>

                </select>
            </div>

            <div class="existencia-field">
                <label>Proveedor</label>

                <select name="proveedor_id">

                    <option value="">Todos</option>

                    <?php foreach ($proveedores as $proveedor): ?>

                        <option
                            value="<?= (int)$proveedor['id'] ?>"
                            <?= (string)$filtros['proveedor_id'] === (string)$proveedor['id'] ? 'selected' : '' ?>
                        >
                            <?= e($proveedor['nombre']) ?>
                        </option>

                    <?php endforeach; ?>

                </select>
            </div>

            <div class="existencia-field">
                <label>Ordenar</label>

                <select name="orden">

                    <option
                        value="descripcion"
                        <?= $filtros['orden'] === 'descripcion' ? 'selected' : '' ?>
                    >
                        Descripción
                    </option>

                    <option
                        value="codigo"
                        <?= $filtros['orden'] === 'codigo' ? 'selected' : '' ?>
                    >
                        Código
                    </option>

                    <option
                        value="ubicacion"
                        <?= $filtros['orden'] === 'ubicacion' ? 'selected' : '' ?>
                    >
                        Ubicación
                    </option>

                    <option
                        value="existencia_mayor"
                        <?= $filtros['orden'] === 'existencia_mayor' ? 'selected' : '' ?>
                    >
                        Mayor existencia
                    </option>

                    <option
                        value="existencia_menor"
                        <?= $filtros['orden'] === 'existencia_menor' ? 'selected' : '' ?>
                    >
                        Menor existencia
                    </option>

                </select>
            </div>

            <div class="existencias-actions">
                <button type="submit" class="btn-primary-action">
                    Filtrar
                </button>

                <a href="existencias.php" class="btn-secondary-action">
                    Limpiar
                </a>
            </div>

        </form>

    </div>

    <div class="erp-table-card existencias-table-card">

        <div class="table-topbar">
            <div>
                <h3>Inventario actual</h3>

                <p>
                    <?= number_format(count($productos)) ?>
                    productos encontrados
                </p>
            </div>
        </div>

        <div class="table-responsive">

            <table class="erp-table tabla-existencias">

                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Código barras</th>
                        <th>Descripción</th>
                        <th>Categoría</th>
                        <th>Proveedor</th>
                        <th>Unidad</th>
                        <th>Almacén</th>
                        <th>Ubicación</th>
                        <th>Existencia</th>
                        <th>Estado</th>

                        <?php if ($esAdmin): ?>
                            <th>Precio</th>
                            <th>Valor</th>
                        <?php endif; ?>
                    </tr>
                </thead>

                <tbody>

                    <?php if (!empty($productos)): ?>

                        <?php foreach ($productos as $producto): ?>

                            <?php

                                $existencia = (int)(
                                    $producto['existencia_con_ubicacion']
                                    ?? $producto['existencia']
                                    ?? 0
                                );

                                $precio = $esAdmin
                                    ? (float)($producto['precio_compra'] ?? 0)
                                    : 0.0;

                                $valor = $esAdmin
                                    ? $existencia * $precio
                                    : 0.0;

                                $estadoTexto = strtoupper(
                                    trim((string)($producto['estado_stock'] ?? 'STOCK NORMAL'))
                                );

                                $estadoClase = 'estado-normal';

                                if ($estadoTexto === 'SIN EXISTENCIA') {
                                    $estadoClase = 'estado-sin';

                                } elseif ($estadoTexto === 'SIN ALMACEN') {
                                    $estadoClase = 'estado-dark';

                                } elseif ($estadoTexto === 'STOCK BAJO') {
                                    $estadoClase = 'estado-bajo';
                                }

                            ?>

                            <tr>

                                <td><?= e($producto['codigo'] ?? '') ?></td>

                                <td><?= e($producto['codigo_barras'] ?? '') ?></td>

                                <td class="descripcion-cell">
                                    <?= e($producto['descripcion'] ?? '') ?>
                                </td>

                                <td>
                                    <?= e($producto['categoria'] ?? 'Sin categoría') ?>
                                </td>

                                <td>
                                    <?= e($producto['proveedor'] ?? 'Sin proveedor') ?>
                                </td>

                                <td><?= e($producto['unidad_medida'] ?? '') ?></td>

                                <td>
                                    <?= e($producto['sucursal'] ?? 'SIN ALMACEN') ?>
                                </td>

                                <td>
                                    <?= e($producto['ubicacion'] ?? 'SIN UBICACION') ?>
                                </td>

                                <td class="text-right existencia-number">
                                    <?= number_format($existencia) ?>
                                </td>

                                <td>
                                    <span class="stock-badge <?= e($estadoClase) ?>">
                                        <?= e($estadoTexto) ?>
                                    </span>
                                </td>

                                <?php if ($esAdmin): ?>

                                    <td class="text-right">
                                        $<?= number_format($precio, 2) ?>
                                    </td>

                                    <td class="text-right">
                                        $<?= number_format($valor, 2) ?>
                                    </td>

                                <?php endif; ?>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="<?= $esAdmin ? 12 : 10 ?>" class="empty-table">
                                No se encontraron productos con los filtros seleccionados.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>
