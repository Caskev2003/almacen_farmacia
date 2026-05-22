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
$enStock = (int)($indicadores['en_stock'] ?? 0);
$bajoStock = (int)($indicadores['bajo_stock'] ?? 0);
$agotados = (int)($indicadores['agotados'] ?? 0);
$entradasHoy = (int)($indicadores['entradas_hoy'] ?? 0);
$salidasHoy = (int)($indicadores['salidas_hoy'] ?? 0);

$porcentajeStock = $totalProductos > 0 ? round(($enStock / $totalProductos) * 100, 1) : 0;
$porcentajeBajo = $totalProductos > 0 ? round(($bajoStock / $totalProductos) * 100, 1) : 0;
$porcentajeAgotado = $totalProductos > 0 ? round(($agotados / $totalProductos) * 100, 1) : 0;

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
            <span>Stock</span>
            <strong><?= number_format($enStock) ?></strong>
            <small>Disponibles</small>
        </div>

        <div class="side-card yellow">
            <span>Bajo stock</span>
            <strong><?= number_format($bajoStock) ?></strong>
            <small>1 a 120 piezas</small>
        </div>

        <div class="side-card red">
            <span>Agotados</span>
            <strong><?= number_format($agotados) ?></strong>
            <small>Sin existencia</small>
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
                <span>Con stock</span>
                <strong><?= $porcentajeStock ?>%</strong>
                <small><?= number_format($enStock) ?> productos</small>
            </div>

            <div class="metric-card">
                <span>Bajo stock</span>
                <strong><?= $porcentajeBajo ?>%</strong>
                <small><?= number_format($bajoStock) ?> productos</small>
            </div>

            <div class="metric-card">
                <span>Agotados</span>
                <strong><?= $porcentajeAgotado ?>%</strong>
                <small><?= number_format($agotados) ?> productos</small>
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
                    <h3>Estado de existencias</h3>
                    <span>Stock / Bajo / Agotado</span>
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
        },
        plugins: [{
            id: 'arrowLine',
            afterDatasetsDraw(chart) {
                const ctx = chart.ctx;
                const meta = chart.getDatasetMeta(0);
                if (!meta || meta.data.length < 2) return;

                const p0 = meta.data[0];
                const p1 = meta.data[1];
                const angle = Math.atan2(p1.y - p0.y, p1.x - p0.x);

                ctx.save();
                ctx.beginPath();
                ctx.moveTo(p0.x, p0.y);
                ctx.lineTo(p1.x, p1.y);
                ctx.strokeStyle = '#2563eb';
                ctx.lineWidth = 4;
                ctx.stroke();

                const headlen = 12;
                ctx.beginPath();
                ctx.moveTo(p1.x, p1.y);
                ctx.lineTo(p1.x - headlen * Math.cos(angle - Math.PI / 6), p1.y - headlen * Math.sin(angle - Math.PI / 6));
                ctx.lineTo(p1.x - headlen * Math.cos(angle + Math.PI / 6), p1.y - headlen * Math.sin(angle + Math.PI / 6));
                ctx.lineTo(p1.x, p1.y);
                ctx.fillStyle = '#2563eb';
                ctx.fill();
                ctx.restore();
            }
        }]
    });
}

crearGraficaBarras(
    'stockChart',
    ['En stock', 'Bajo stock', 'Agotados'],
    [<?= $enStock ?>, <?= $bajoStock ?>, <?= $agotados ?>],
    ['#15803d', '#facc15', '#dc2626']
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