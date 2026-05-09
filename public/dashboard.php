<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/DashboardController.php';

requireLogin();

$dashboardController = new DashboardController();

$indicadores = $dashboardController->getIndicadores();
$productosCriticos = $dashboardController->getProductosCriticos();
$movimientosRecientes = $dashboardController->getMovimientosRecientes();

$moduleCss = 'dashboard';
include __DIR__ . '/../app/views/layouts/header.php';
?>

<div class="module-header">
    <div>
        <h2>Dashboard de Inventario</h2>
        <p>Vista rápida para supervisar existencias, riesgos y movimientos del almacén.</p>
    </div>
</div>

<div class="dashboard-kpis">
    <div class="kpi-card primary">
        <span class="kpi-label">Productos activos</span>
        <strong><?= (int)$indicadores['total_productos'] ?></strong>
    </div>

    <div class="kpi-card success">
        <span class="kpi-label">En stock</span>
        <strong><?= (int)$indicadores['en_stock'] ?></strong>
    </div>

    <div class="kpi-card warning">
        <span class="kpi-label">Bajo stock</span>
        <strong><?= (int)$indicadores['bajo_stock'] ?></strong>
    </div>

    <div class="kpi-card danger">
        <span class="kpi-label">Agotados</span>
        <strong><?= (int)$indicadores['agotados'] ?></strong>
    </div>

    <div class="kpi-card dark">
        <span class="kpi-label">Caducados</span>
        <strong><?= (int)$indicadores['caducados'] ?></strong>
    </div>

    <div class="kpi-card info">
        <span class="kpi-label">Por caducar</span>
        <strong><?= (int)$indicadores['por_caducar'] ?></strong>
    </div>

    <div class="kpi-card neutral">
        <span class="kpi-label">Entradas hoy</span>
        <strong><?= (int)$indicadores['entradas_hoy'] ?></strong>
    </div>

    <div class="kpi-card neutral">
        <span class="kpi-label">Salidas hoy</span>
        <strong><?= (int)$indicadores['salidas_hoy'] ?></strong>
    </div>
</div>


<div class="dashboard-panels">
    <div class="panel-card panel-large">
        <div class="panel-header">
            <h3>Productos críticos</h3>
            <a href="existencias.php" class="panel-link">Ver existencias</a>
        </div>

        <div class="table-responsive">
            <table class="erp-table dashboard-table">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th>Existencia</th>
                        <th>Mínimo</th>
                        <th>Ubicación</th>
                        <th>Caducidad</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($productosCriticos) > 0): ?>
                        <?php foreach ($productosCriticos as $item): ?>
                            <?php $estado = $dashboardController->getEstadoProducto($item); ?>
                            <tr>
                                <td><?= e($item['codigo']) ?></td>
                                <td><?= e($item['descripcion']) ?></td>
                                <td class="text-center"><strong><?= (int)$item['existencia_actual'] ?></strong></td>
                                <td class="text-center"><?= (int)$item['stock_minimo'] ?></td>
                                <td><?= e($item['ubicacion']) ?></td>
                                <td>
                                    <?= $item['proxima_caducidad'] ? e(date('d/m/Y', strtotime($item['proxima_caducidad']))) : '' ?>
                                </td>
                                <td>
                                    <span class="badge-status <?= e($estado['clase']) ?>">
                                        <?= e($estado['texto']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="empty-table">No hay productos críticos en este momento.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel-card">
        <div class="panel-header">
            <h3>Movimientos recientes</h3>
            <a href="reportes.php" class="panel-link">Ver reportes</a>
        </div>

        <div class="movimientos-list">
            <?php if (count($movimientosRecientes) > 0): ?>
                <?php foreach ($movimientosRecientes as $mov): ?>
                    <div class="mov-item">
                        <div class="mov-top">
                            <span class="mov-folio"><?= e($mov['folio']) ?></span>
                            <span class="mov-type <?= $mov['tipo_movimiento'] === 'ENTRADA' ? 'entrada' : 'salida' ?>">
                                <?= e($mov['tipo_movimiento']) ?>
                            </span>
                        </div>
                        <div class="mov-meta">
                            <span><?= e($mov['almacen'] ?? '') ?></span>
                            <span><?= e($mov['usuario'] ?? '') ?></span>
                        </div>
                        <div class="mov-date">
                            <?= e(date('d/m/Y H:i', strtotime($mov['fecha']))) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="empty-panel">No hay movimientos recientes.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="dashboard-actions">
    <a href="productos.php" class="action-card">
        <h4>Productos</h4>
        <p>Alta, edición y consulta del catálogo.</p>
    </a>

    <a href="entradas.php" class="action-card">
        <h4>Entradas</h4>
        <p>Registrar ingresos al inventario.</p>
    </a>

    <a href="salidas.php" class="action-card">
        <h4>Salidas</h4>
        <p>Registrar movimientos de salida.</p>
    </a>

    <a href="existencias.php" class="action-card">
        <h4>Existencias</h4>
        <p>Consultar stock y vencimientos.</p>
    </a>

    <a href="kardex.php" class="action-card">
        <h4>Kardex</h4>
        <p>Ver historial por producto.</p>
    </a>

    <a href="reportes.php" class="action-card">
        <h4>Reportes</h4>
        <p>Impresión y seguimiento general.</p>
    </a>
</div>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>