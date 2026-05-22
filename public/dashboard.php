<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/DashboardController.php';

requireLogin();

date_default_timezone_set('America/Mexico_City');

$controller = new DashboardController();

$indicadores = $controller->getIndicadores();
$productosCriticos = $controller->getProductosCriticos();
$movimientos = $controller->getMovimientosRecientes();
$ubicaciones = $controller->getProductosPorUbicacion();

$moduleCss = 'dashboard';

include __DIR__ . '/../app/views/layouts/header.php';

$totalProductos = (int)$indicadores['total_productos'];
$enStock = (int)$indicadores['en_stock'];
$bajoStock = (int)$indicadores['bajo_stock'];
$agotados = (int)$indicadores['agotados'];

$porcentajeStock = $totalProductos > 0
    ? round(($enStock / $totalProductos) * 100)
    : 0;

$porcentajeBajo = $totalProductos > 0
    ? round(($bajoStock / $totalProductos) * 100)
    : 0;

$porcentajeAgotado = $totalProductos > 0
    ? round(($agotados / $totalProductos) * 100)
    : 0;

?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="dashboard-highlight">

    <div class="highlight-card">

        <div>
            <span class="highlight-title">
                RESUMEN GENERAL DEL INVENTARIO
            </span>

            <strong>
                <?= number_format($totalProductos) ?> productos registrados
            </strong>

            <p>
                Actualmente
                <?= number_format($enStock) ?> productos tienen existencia disponible,
                <?= number_format($bajoStock) ?> están en bajo stock y
                <?= number_format($agotados) ?> se encuentran agotados.
            </p>
        </div>

        <div class="highlight-stats">

            <div class="mini-stat">
                <strong><?= $porcentajeStock ?>%</strong>
                <span>Con stock</span>
            </div>

            <div class="mini-stat">
                <strong><?= $porcentajeBajo ?>%</strong>
                <span>Bajo stock</span>
            </div>

            <div class="mini-stat">
                <strong><?= $porcentajeAgotado ?>%</strong>
                <span>Agotados</span>
            </div>

        </div>

    </div>

</div>

<div class="dashboard-kpis">

    <div class="kpi-card primary">
        <span class="kpi-label">PRODUCTOS ACTIVOS</span>
        <strong><?= number_format($totalProductos) ?></strong>
        <small>Total registrado en catálogo</small>
    </div>

    <div class="kpi-card success">
        <span class="kpi-label">EN STOCK</span>
        <strong><?= number_format($enStock) ?></strong>
        <small><?= $porcentajeStock ?>% del inventario disponible</small>
    </div>

    <div class="kpi-card warning">
        <span class="kpi-label">BAJO STOCK</span>
        <strong><?= number_format($bajoStock) ?></strong>
        <small>Productos con 1 a 120 piezas</small>
    </div>

    <div class="kpi-card danger">
        <span class="kpi-label">AGOTADOS</span>
        <strong><?= number_format($agotados) ?></strong>
        <small>Sin existencia disponible</small>
    </div>

    <div class="kpi-card info">
        <span class="kpi-label">ENTRADAS HOY</span>
        <strong><?= number_format($indicadores['entradas_hoy']) ?></strong>
        <small>Ingresos registrados hoy</small>
    </div>

    <div class="kpi-card dark">
        <span class="kpi-label">SALIDAS HOY</span>
        <strong><?= number_format($indicadores['salidas_hoy']) ?></strong>
        <small>Salidas registradas hoy</small>
    </div>

</div>

<div class="dashboard-panels">

    <div class="panel-card">

        <div class="panel-header">
            <div>
                <h3>Estado de existencias</h3>
                <p>
                    Distribución entre productos con stock,
                    bajo stock y agotados.
                </p>
            </div>
        </div>

        <div style="height: 320px;">
            <canvas id="stockChart"></canvas>
        </div>

    </div>

    <div class="panel-card">

        <div class="panel-header">
            <div>
                <h3>Productos por ubicación</h3>
                <p>
                    Gráfica de pastel basada en piezas existentes por ubicación.
                </p>
            </div>
        </div>

        <div style="height: 320px;">
            <canvas id="ubicacionesChart"></canvas>
        </div>

    </div>

    <div class="panel-card">

        <div class="panel-header">
            <div>
                <h3>Movimientos del día</h3>
                <p>
                    Comparativo de entradas y salidas registradas hoy.
                </p>
            </div>
        </div>

        <div style="height: 320px;">
            <canvas id="movimientosChart"></canvas>
        </div>

    </div>

</div>

<div class="dashboard-panels">

    <div class="panel-card">

        <div class="panel-header">

            <div>
                <h3>Productos bajos en stock</h3>
                <p>
                    Productos con existencia de 1 a 120 piezas.
                </p>
            </div>

            <a href="existencias.php" class="panel-link">
                Ver existencias
            </a>

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
                        <th>Estado</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!empty($productosCriticos)): ?>

                    <?php foreach ($productosCriticos as $item):

                        $estado = $controller->getEstadoProducto($item);

                    ?>

                    <tr>

                        <td><?= e($item['codigo']) ?></td>

                        <td><?= e($item['descripcion']) ?></td>

                        <td>
                            <?= number_format($item['existencia_actual']) ?>
                        </td>

                        <td>
                            <?= number_format($item['stock_minimo']) ?>
                        </td>

                        <td><?= e($item['ubicacion']) ?></td>

                        <td>
                            <span class="badge-status <?= $estado['clase'] ?>">
                                <?= $estado['texto'] ?>
                            </span>
                        </td>

                    </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="6" class="text-center">
                            No hay productos bajos en stock.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <div class="panel-card">

        <div class="panel-header">

            <div>
                <h3>Movimientos recientes</h3>
                <p>
                    Últimas entradas y salidas registradas.
                </p>
            </div>

            <a href="reportes.php" class="panel-link">
                Ver reportes
            </a>

        </div>

        <div class="movimientos-list">

            <?php if (!empty($movimientos)): ?>

                <?php foreach ($movimientos as $mov): ?>

                    <div class="mov-item">

                        <div class="mov-top">

                            <span class="mov-folio">
                                <?= e($mov['folio']) ?>
                            </span>

                            <span class="mov-type <?= strtolower($mov['tipo_movimiento']) ?>">
                                <?= e($mov['tipo_movimiento']) ?>
                            </span>

                        </div>

                        <div class="mov-meta">

                            <span>
                                Almacén:
                                <?= e($mov['almacen']) ?>
                            </span>

                            <span>
                                Usuario:
                                <?= e($mov['usuario']) ?>
                            </span>

                        </div>

                        <span class="mov-date">
                            <?= date('d/m/Y H:i', strtotime($mov['fecha'])) ?>
                        </span>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <p class="empty-panel">
                    No hay movimientos recientes.
                </p>

            <?php endif; ?>

        </div>

    </div>

</div>

<script>

function crearGraficaBarras(id, labels, data) {

    const canvas = document.getElementById(id);

    if (!canvas) return;

    let colores = ['#15803d', '#facc15', '#dc2626'];

    if (id === 'movimientosChart') {
        colores = ['#15803d', '#dc2626'];
    }

    new Chart(canvas, {

        type: 'bar',

        data: {
            labels: labels,

            datasets: [{
                label: 'Total',
                data: data,
                backgroundColor: colores,
                borderRadius: 10,
                maxBarThickness: 70
            }]
        },

        options: {

            responsive: true,
            maintainAspectRatio: false,

            scales: {

                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    },
                    grid: {
                        color: '#eef2f7'
                    }
                },

                x: {
                    grid: {
                        display: false
                    }
                }

            },

            plugins: {
                legend: {
                    display: false
                }
            }

        }

    });

}

function crearGraficaDona(id, labels, data) {

    const canvas = document.getElementById(id);

    if (!canvas) return;

    new Chart(canvas, {

        type: 'doughnut',

        data: {

            labels: labels,

            datasets: [{
                data: data,
                borderWidth: 2
            }]
        },

        options: {

            responsive: true,
            maintainAspectRatio: false,

            plugins: {

                legend: {
                    position: 'bottom'
                }

            },

            cutout: '58%'

        }

    });

}

crearGraficaBarras(
    'stockChart',
    ['En stock', 'Bajo stock', 'Agotados'],
    [
        <?= $enStock ?>,
        <?= $bajoStock ?>,
        <?= $agotados ?>
    ]
);

crearGraficaBarras(
    'movimientosChart',
    ['Entradas hoy', 'Salidas hoy'],
    [
        <?= (int)$indicadores['entradas_hoy'] ?>,
        <?= (int)$indicadores['salidas_hoy'] ?>
    ]
);

crearGraficaDona(
    'ubicacionesChart',
    <?= json_encode(array_column($ubicaciones, 'ubicacion')) ?>,
    <?= json_encode(array_map('intval', array_column($ubicaciones, 'total'))) ?>
);

</script>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>