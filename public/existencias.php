<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/ExistenciaController.php';

requireLogin();

$controller = new ExistenciaController();

$buscar = trim($_GET['buscar'] ?? '');
$almacenId = isset($_GET['almacen_id']) ? (int)$_GET['almacen_id'] : 0;
$estadoStock = trim($_GET['estado_stock'] ?? '');

$almacenes = $controller->almacenes();
$productos = $controller->index($buscar, $almacenId, $estadoStock);
$resumen = $controller->resumen($productos);

$moduleCss = 'existencias';
include __DIR__ . '/../app/views/layouts/header.php';
?>

<div class="module-header">
    <div>
        <h2>Existencias</h2>
        <p>Consulta el inventario actual, productos bajos y productos sin existencia.</p>
    </div>
</div>

<div class="existencias-resumen-grid">
    <div class="existencia-card">
        <span>Total productos</span>
        <strong><?= number_format((int)$resumen['total_productos']) ?></strong>
    </div>

    <div class="existencia-card">
        <span>Total unidades</span>
        <strong><?= number_format((int)$resumen['total_unidades']) ?></strong>
    </div>

    <div class="existencia-card warning">
        <span>Stock bajo</span>
        <strong><?= number_format((int)$resumen['stock_bajo']) ?></strong>
    </div>

    <div class="existencia-card danger">
        <span>Sin existencia</span>
        <strong><?= number_format((int)$resumen['sin_existencia']) ?></strong>
    </div>

   
</div>

<div class="existencias-filter-card">
    <form method="GET" action="existencias.php" class="existencias-filter-form">
        <div class="existencia-field search-field">
            <label>Buscar producto</label>
            <input 
                type="text" 
                name="buscar" 
                value="<?= e($buscar) ?>" 
                placeholder="Código, código de barras, descripción, laboratorio..."
            >
        </div>

        <div class="existencia-field">
            <label>Almacén</label>
            <select name="almacen_id">
                <option value="0">Todos los almacenes</option>
                <?php foreach ($almacenes as $almacen): ?>
                    <option 
                        value="<?= (int)$almacen['id'] ?>"
                        <?= $almacenId === (int)$almacen['id'] ? 'selected' : '' ?>
                    >
                        <?= e($almacen['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="existencia-field">
            <label>Estado de stock</label>
            <select name="estado_stock">
                <option value="">Todos</option>
                <option value="normal" <?= $estadoStock === 'normal' ? 'selected' : '' ?>>Stock normal</option>
                <option value="bajo" <?= $estadoStock === 'bajo' ? 'selected' : '' ?>>Stock bajo</option>
                <option value="sin_existencia" <?= $estadoStock === 'sin_existencia' ? 'selected' : '' ?>>Sin existencia</option>
            </select>
        </div>

        <div class="existencias-actions">
            <button type="submit" class="btn-primary-action">Filtrar</button>
            <a href="existencias.php" class="btn-secondary-action">Limpiar</a>
        </div>
    </form>
</div>

<div class="erp-table-card">
    <div class="table-topbar">
        <h3>Inventario actual</h3>
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
                    <th>Ubicación</th>
                    <th>Stock mín.</th>
                    <th>Stock máx.</th>
                    <th>Existencia</th>
                    <th>Estado</th>
                    <th>Valor</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!empty($productos)): ?>
                    <?php foreach ($productos as $producto): ?>
                        <?php
                            $existencia = (int)($producto['existencia_consultada'] ?? 0);
                            $stockMinimo = (int)($producto['stock_minimo'] ?? 0);
                            $stockMaximo = (int)($producto['stock_maximo'] ?? 0);
                            $precioCompra = (float)($producto['precio_compra'] ?? 0);
                            $valor = $existencia * $precioCompra;

                            $estadoTexto = 'Stock normal';
                            $estadoClase = 'estado-normal';

                            if ($existencia <= 0) {
                                $estadoTexto = 'Sin existencia';
                                $estadoClase = 'estado-sin';
                            } elseif ($existencia <= $stockMinimo) {
                                $estadoTexto = 'Stock bajo';
                                $estadoClase = 'estado-bajo';
                            }
                        ?>

                        <tr>
                            <td><?= e($producto['codigo'] ?? '') ?></td>
                            <td><?= e($producto['codigo_barras'] ?? '') ?></td>
                            <td class="descripcion-cell"><?= e($producto['descripcion'] ?? '') ?></td>
                            <td><?= e($producto['categoria'] ?? 'Sin categoría') ?></td>
                            <td><?= e($producto['proveedor'] ?? 'Sin proveedor') ?></td>
                            <td><?= e($producto['unidad_medida'] ?? '') ?></td>
                            <td><?= e($producto['ubicacion'] ?? '') ?></td>
                            <td class="text-right"><?= number_format($stockMinimo) ?></td>
                            <td class="text-right"><?= number_format($stockMaximo) ?></td>
                            <td class="text-right existencia-number"><?= number_format($existencia) ?></td>
                            <td>
                                <span class="stock-badge <?= e($estadoClase) ?>">
                                    <?= e($estadoTexto) ?>
                                </span>
                            </td>
                            <td class="text-right">$<?= number_format($valor, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="12" class="empty-table">
                            No se encontraron existencias con los filtros seleccionados.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>