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
$documentosMasUsados = method_exists($controller, 'getDocumentosMasUsados')
    ? $controller->getDocumentosMasUsados()
    : [];

$moduleCss = 'dashboard';

include __DIR__ . '/../app/views/layouts/header.php';

$totalProductos = (int)($indicadores['total_productos'] ?? 0);
$stockCorrecto = (int)($indicadores['stock_correcto'] ?? 0);
$enStock = (int)($indicadores['en_stock'] ?? 0);
$bajoStock = (int)($indicadores['bajo_stock'] ?? 0);
$sinExistencia = (int)($indicadores['sin_existencia'] ?? $indicadores['agotados'] ?? 0);
$sinAlmacen = (int)($indicadores['sin_almacen'] ?? 0);
$entradasHoy = (int)($indicadores['entradas_hoy'] ?? 0);
$salidasHoy = (int)($indicadores['salidas_hoy'] ?? 0);

$porcentajeStock = $totalProductos > 0 ? round(($stockCorrecto / $totalProductos) * 100, 1) : 0;
$porcentajeSinExistencia = $totalProductos > 0 ? round(($sinExistencia / $totalProductos) * 100, 1) : 0;
$porcentajeSinAlmacen = $totalProductos > 0 ? round(($sinAlmacen / $totalProductos) * 100, 1) : 0;

$ubicacionLabels = array_column($ubicaciones, 'ubicacion');
$ubicacionData = array_map('intval', array_column($ubicaciones, 'total'));

$documentoLabels = array_column($documentosMasUsados, 'documento');
$documentoData = array_map('intval', array_column($documentosMasUsados, 'total'));

?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="power-dashboard">

    <div class="dash-sidebar">
        <div class="side-card pink">
            <span>Productos</span>
            <strong><?= number_format($totalProductos) ?></strong>
            <small>Registrados</small>
        </div>

        <div class="side-card blue">
            <span>Stock correcto</span>
            <strong><?= number_format($stockCorrecto) ?></strong>
            <small>Con existencia y ubicación</small>
        </div>

        <div class="side-card yellow">
            <span>Bajo stock</span>
            <strong><?= number_format($bajoStock) ?></strong>
            <small>1 a 120 piezas</small>
        </div>

        <div class="side-card red">
            <span>Sin existencia</span>
            <strong><?= number_format($sinExistencia) ?></strong>
            <small>Con almacén asignado</small>
        </div>

        <div class="side-card gray">
            <span>Sin almacén</span>
            <strong><?= number_format($sinAlmacen) ?></strong>
            <small>Sin sucursal asignada</small>
        </div>
    </div>

    <div class="dash-main">

        <div class="dash-title-card">
            <div>
                <h2>Dashboard de Inventario</h2>
                <p>Análisis general de productos, ubicaciones, movimientos y documentos de salida.</p>
            </div>

            <div class="date-pill">
                <span>Fecha</span>
                <strong><?= date('d/m/Y') ?></strong>
            </div>
        </div>

        <div class="metric-row">
            <div class="metric-card">
                <span>Stock correcto</span>
                <strong><?= $porcentajeStock ?>%</strong>
                <small><?= number_format($stockCorrecto) ?> productos</small>
            </div>

            <div class="metric-card">
                <span>Sin existencia</span>
                <strong><?= $porcentajeSinExistencia ?>%</strong>
                <small><?= number_format($sinExistencia) ?> productos</small>
            </div>

            <div class="metric-card">
                <span>Sin almacén</span>
                <strong><?= $porcentajeSinAlmacen ?>%</strong>
                <small><?= number_format($sinAlmacen) ?> productos</small>
            </div>

            <div class="metric-card">
                <span>Entradas hoy</span>
                <strong><?= number_format($entradasHoy) ?></strong>
                <small>Movimientos</small>
            </div>

            <div class="metric-card">
                <span>Salidas hoy</span>
                <strong><?= number_format($salidasHoy) ?></strong>
                <small>Movimientos</small>
            </div>
        </div>

        <div class="charts-compact-grid">

            <div class="chart-window">
                <div class="chart-head">
                    <h3>Estado de inventario</h3>
                    <span>Stock / Sin existencia / Sin almacén</span>
                </div>
                <div class="chart-box">
                    <canvas id="stockChart"></canvas>
                </div>
            </div>

            <div class="chart-window">
                <div class="chart-head">
                    <h3>Movimientos del día</h3>
                    <span>Entradas vs salidas</span>
                </div>
                <div class="chart-box">
                    <canvas id="movimientosChart"></canvas>
                </div>
            </div>

            <div class="chart-window">
                <div class="chart-head">
                    <h3>Productos por ubicación</h3>
                    <span>Piezas por ubicación</span>
                </div>
                <div class="chart-box">
                    <canvas id="ubicacionesChart"></canvas>
                </div>
            </div>

            <div class="chart-window">
                <div class="chart-head">
                    <h3>Documentos más usados</h3>
                    <span>Ticket / Resurtido / Otros</span>
                </div>
                <div class="chart-box">
                    <canvas id="documentosChart"></canvas>
                </div>
            </div>

        </div>

        <div class="dashboard-panels compact-panels">
            <div class="panel-card">
                <div class="panel-header">
                    <div>
                        <h3>Productos bajos en stock</h3>
                        <p>Productos con existencia de 1 a 120 piezas.</p>
                    </div>

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
                                <th>Estado</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (!empty($productosCriticos)): ?>
                                <?php foreach ($productosCriticos as $item): ?>
                                    <?php $estado = $controller->getEstadoProducto($item); ?>

                                    <tr>
                                        <td><?= e($item['codigo'] ?? '') ?></td>
                                        <td><?= e($item['descripcion'] ?? '') ?></td>
                                        <td><?= number_format((int)($item['existencia_actual'] ?? 0)) ?></td>
                                        <td><?= number_format((int)($item['stock_minimo'] ?? 120)) ?></td>
                                        <td><?= e($item['ubicacion'] ?? 'SIN UBICACIÓN') ?></td>
                                        <td>
                                            <span class="badge-status <?= e($estado['clase']) ?>">
                                                <?= e($estado['texto']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center">No hay productos bajos en stock.</td>
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
                        <p>Últimas entradas y salidas registradas.</p>
                    </div>

                    <a href="reportes.php" class="panel-link">Ver reportes</a>
                </div>

                <div class="movimientos-list">
                    <?php if (!empty($movimientos)): ?>
                        <?php foreach ($movimientos as $mov): ?>
                            <div class="mov-item">
                                <div class="mov-top">
                                    <span class="mov-folio"><?= e($mov['folio'] ?? '') ?></span>

                                    <span class="mov-type <?= strtolower(e($mov['tipo_movimiento'] ?? '')) ?>">
                                        <?= e($mov['tipo_movimiento'] ?? '') ?>
                                    </span>
                                </div>

                                <div class="mov-meta">
                                    <span>Almacén: <?= e($mov['almacen'] ?? '') ?></span>
                                    <span>Usuario: <?= e($mov['usuario'] ?? '') ?></span>
                                </div>

                                <span class="mov-date">
                                    <?= !empty($mov['fecha']) ? date('d/m/Y H:i', strtotime($mov['fecha'])) : '' ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="empty-panel">No hay movimientos recientes.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
const chartTextColor = '#374151';
const chartGridColor = '#e5e7eb';

function crearGraficaBarras(id, labels, data, colores) {
    const canvas = document.getElementById(id);
    if (!canvas) return;

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data,
                backgroundColor: colores,
                borderRadius: 8,
                maxBarThickness: 46
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0, color: chartTextColor, font: { size: 10, weight: 'bold' } },
                    grid: { color: chartGridColor }
                },
                x: {
                    ticks: { color: chartTextColor, font: { size: 10, weight: 'bold' } },
                    grid: { display: false }
                }
            }
        }
    });
}

function crearGraficaDona(id, labels, data) {
    const canvas = document.getElementById(id);
    if (!canvas) return;

    const tieneDatos = labels.length > 0 && data.length > 0;

    new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: tieneDatos ? labels : ['Sin datos'],
            datasets: [{
                data: tieneDatos ? data : [1],
                backgroundColor: tieneDatos ? [
                    '#0f4c81', '#ef477a', '#38bdf8', '#22c55e',
                    '#f97316', '#facc15', '#8b5cf6', '#14b8a6',
                    '#64748b', '#ec4899'
                ] : ['#e5e7eb'],
                borderColor: '#ffffff',
                borderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '58%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 10,
                        padding: 8,
                        color: chartTextColor,
                        font: { size: 10, weight: 'bold' }
                    }
                }
            }
        }
    });
}

function crearGraficaPuntosConFlecha(id, entradas, salidas) {
    const canvas = document.getElementById(id);
    if (!canvas) return;

    new Chart(canvas, {
        type: 'line',
        data: {
            labels: ['Entradas', 'Salidas'],
            datasets: [{
                data: [entradas, salidas],
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37,99,235,0.10)',
                fill: true,
                tension: 0.35,
                pointRadius: 7,
                pointBackgroundColor: ['#16a34a', '#dc2626'],
                pointBorderColor: '#ffffff',
                pointBorderWidth: 3,
                borderWidth: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    suggestedMax: Math.max(entradas, salidas, 1) + 1,
                    ticks: { precision: 0, color: chartTextColor, font: { size: 10, weight: 'bold' } },
                    grid: { color: chartGridColor }
                },
                x: {
                    ticks: { color: chartTextColor, font: { size: 10, weight: 'bold' } },
                    grid: { display: false }
                }
            }
        }
    });
}

crearGraficaBarras(
    'stockChart',
    ['Stock correcto', 'Sin existencia', 'Sin almacén'],
    [<?= $stockCorrecto ?>, <?= $sinExistencia ?>, <?= $sinAlmacen ?>],
    ['#15803d', '#dc2626', '#64748b']
);

crearGraficaPuntosConFlecha(
    'movimientosChart',
    <?= $entradasHoy ?>,
    <?= $salidasHoy ?>
);

crearGraficaDona(
    'ubicacionesChart',
    <?= json_encode($ubicacionLabels, JSON_UNESCAPED_UNICODE) ?>,
    <?= json_encode($ubicacionData, JSON_UNESCAPED_UNICODE) ?>
);

crearGraficaBarras(
    'documentosChart',
    <?= json_encode($documentoLabels, JSON_UNESCAPED_UNICODE) ?>,
    <?= json_encode($documentoData, JSON_UNESCAPED_UNICODE) ?>,
    ['#ef477a', '#0f4c81', '#f97316', '#8b5cf6', '#14b8a6']
);
</script>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>