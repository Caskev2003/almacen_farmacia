<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/DashboardController.php';

requireLogin();

$dashboardController = new DashboardController();

$indicadores = $dashboardController->getIndicadores();
$productosCriticos = $dashboardController->getProductosCriticos();
$movimientosRecientes = $dashboardController->getMovimientosRecientes();

/*
|--------------------------------------------------------------------------
| Datos para gráficas
|--------------------------------------------------------------------------
| Estos métodos deben existir en DashboardController.
| Si aún no los tienes, te los puedo dar después completos.
*/
$productosPorUbicacion = method_exists($dashboardController, 'getProductosPorUbicacion')
    ? $dashboardController->getProductosPorUbicacion()
    : [];

$stockPorEstado = [
    'En stock' => (int)($indicadores['en_stock'] ?? 0),
    'Bajo stock' => (int)($indicadores['bajo_stock'] ?? 0),
    'Agotados' => (int)($indicadores['agotados'] ?? 0),
];

$movimientosHoy = [
    'Entradas hoy' => (int)($indicadores['entradas_hoy'] ?? 0),
    'Salidas hoy' => (int)($indicadores['salidas_hoy'] ?? 0),
];

$totalProductos = (int)($indicadores['total_productos'] ?? 0);
$enStock = (int)($indicadores['en_stock'] ?? 0);
$bajoStock = (int)($indicadores['bajo_stock'] ?? 0);
$agotados = (int)($indicadores['agotados'] ?? 0);
$entradasHoy = (int)($indicadores['entradas_hoy'] ?? 0);
$salidasHoy = (int)($indicadores['salidas_hoy'] ?? 0);

$porcentajeStock = $totalProductos > 0 ? round(($enStock / $totalProductos) * 100, 1) : 0;
$porcentajeBajo = $totalProductos > 0 ? round(($bajoStock / $totalProductos) * 100, 1) : 0;
$porcentajeAgotado = $totalProductos > 0 ? round(($agotados / $totalProductos) * 100, 1) : 0;

$moduleCss = 'dashboard';
include __DIR__ . '/../app/views/layouts/header.php';
?>

<div class="module-header dashboard-header">
    <div>
        <h2>Dashboard de Inventario</h2>
        <p>Vista general del almacén: existencias, productos críticos, ubicaciones y movimientos recientes.</p>
    </div>

    <div class="dashboard-date">
        <span>Fecha de consulta</span>
        <strong><?= date('d/m/Y') ?></strong>
    </div>
</div>

<div class="dashboard-highlight">
    <div class="highlight-card">
        <div>
            <span class="highlight-title">Resumen general del inventario</span>
            <strong><?= number_format($totalProductos) ?> productos registrados</strong>
            <p>
                Actualmente <?= number_format($enStock) ?> productos tienen existencia disponible,
                <?= number_format($bajoStock) ?> están en bajo stock y
                <?= number_format($agotados) ?> se encuentran agotados.
            </p>
        </div>

        <div class="highlight-metrics">
            <div>
                <span><?= $porcentajeStock ?>%</span>
                <small>Con stock</small>
            </div>
            <div>
                <span><?= $porcentajeBajo ?>%</span>
                <small>Bajo stock</small>
            </div>
            <div>
                <span><?= $porcentajeAgotado ?>%</span>
                <small>Agotados</small>
            </div>
        </div>
    </div>
</div>

<div class="dashboard-kpis">
    <div class="kpi-card primary">
        <div class="kpi-icon">📦</div>
        <div>
            <span class="kpi-label">Productos activos</span>
            <strong><?= number_format($totalProductos) ?></strong>
            <small>Total registrado en catálogo</small>
        </div>
    </div>

    <div class="kpi-card success">
        <div class="kpi-icon">✅</div>
        <div>
            <span class="kpi-label">En stock</span>
            <strong><?= number_format($enStock) ?></strong>
            <small><?= $porcentajeStock ?>% del inventario disponible</small>
        </div>
    </div>

    <div class="kpi-card warning">
        <div class="kpi-icon">⚠️</div>
        <div>
            <span class="kpi-label">Bajo stock</span>
            <strong><?= number_format($bajoStock) ?></strong>
            <small>Requieren revisión o reposición</small>
        </div>
    </div>

    <div class="kpi-card danger">
        <div class="kpi-icon">⛔</div>
        <div>
            <span class="kpi-label">Agotados</span>
            <strong><?= number_format($agotados) ?></strong>
            <small>Sin existencia disponible</small>
        </div>
    </div>

    <div class="kpi-card info">
        <div class="kpi-icon">⬆️</div>
        <div>
            <span class="kpi-label">Entradas hoy</span>
            <strong><?= number_format($entradasHoy) ?></strong>
            <small>Ingresos registrados hoy</small>
        </div>
    </div>

    <div class="kpi-card neutral">
        <div class="kpi-icon">⬇️</div>
        <div>
            <span class="kpi-label">Salidas hoy</span>
            <strong><?= number_format($salidasHoy) ?></strong>
            <small>Salidas registradas hoy</small>
        </div>
    </div>
</div>

<div class="dashboard-charts">
    <div class="panel-card chart-card">
        <div class="panel-header">
            <div>
                <h3>Estado de existencias</h3>
                <p>Distribución entre productos con stock, bajo stock y agotados.</p>
            </div>
        </div>

        <div class="chart-box">
            <canvas id="stockChart"></canvas>
        </div>
    </div>

    <div class="panel-card chart-card">
        <div class="panel-header">
            <div>
                <h3>Productos por ubicación</h3>
                <p>Gráfica de pastel basada en las ubicaciones registradas.</p>
            </div>
        </div>

        <div class="chart-box">
            <canvas id="ubicacionChart"></canvas>
        </div>
    </div>

    <div class="panel-card chart-card">
        <div class="panel-header">
            <div>
                <h3>Movimientos del día</h3>
                <p>Comparativo de entradas y salidas registradas hoy.</p>
            </div>
        </div>

        <div class="chart-box">
            <canvas id="movimientosChart"></canvas>
        </div>
    </div>
</div>

<div class="dashboard-panels">
    <div class="panel-card panel-large">
        <div class="panel-header">
            <div>
                <h3>Productos críticos</h3>
                <p>Productos agotados o con existencia igual o menor al mínimo.</p>
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
                    <?php if (count($productosCriticos) > 0): ?>
                        <?php foreach ($productosCriticos as $item): ?>
                            <?php $estado = $dashboardController->getEstadoProducto($item); ?>
                            <tr>
                                <td>
                                    <strong><?= e($item['codigo'] ?? '') ?></strong>
                                </td>
                                <td><?= e($item['descripcion'] ?? '') ?></td>
                                <td class="text-center">
                                    <strong><?= number_format((int)($item['existencia_actual'] ?? 0)) ?></strong>
                                </td>
                                <td class="text-center">
                                    <?= number_format((int)($item['stock_minimo'] ?? 0)) ?>
                                </td>
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
                            <td colspan="6" class="empty-table">No hay productos críticos en este momento.</td>
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
            <?php if (count($movimientosRecientes) > 0): ?>
                <?php foreach ($movimientosRecientes as $mov): ?>
                    <div class="mov-item">
                        <div class="mov-top">
                            <span class="mov-folio"><?= e($mov['folio'] ?? '') ?></span>
                            <span class="mov-type <?= ($mov['tipo_movimiento'] ?? '') === 'ENTRADA' ? 'entrada' : 'salida' ?>">
                                <?= e($mov['tipo_movimiento'] ?? '') ?>
                            </span>
                        </div>

                        <div class="mov-meta">
                            <span><strong>Almacén:</strong> <?= e($mov['almacen'] ?? '') ?></span>
                            <span><strong>Usuario:</strong> <?= e($mov['usuario'] ?? '') ?></span>
                        </div>

                        <div class="mov-date">
                            <?= !empty($mov['fecha']) ? e(date('d/m/Y H:i', strtotime($mov['fecha']))) : '' ?>
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
        <div class="action-icon">📋</div>
        <h4>Productos</h4>
        <p>Alta, edición y consulta del catálogo general.</p>
    </a>

    <a href="entradas.php" class="action-card">
        <div class="action-icon">📥</div>
        <h4>Entradas</h4>
        <p>Registrar ingresos de productos al inventario.</p>
    </a>

    <a href="salidas.php" class="action-card">
        <div class="action-icon">📤</div>
        <h4>Salidas</h4>
        <p>Registrar salidas y descontar existencias.</p>
    </a>

    <a href="existencias.php" class="action-card">
        <div class="action-icon">🏷️</div>
        <h4>Existencias</h4>
        <p>Consultar stock disponible por producto y ubicación.</p>
    </a>

    <a href="kardex.php" class="action-card">
        <div class="action-icon">📊</div>
        <h4>Kardex</h4>
        <p>Revisar el historial detallado por producto.</p>
    </a>

    <a href="reportes.php" class="action-card">
        <div class="action-icon">🖨️</div>
        <h4>Reportes</h4>
        <p>Impresión, seguimiento y consultas generales.</p>
    </a>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
const stockLabels = <?= json_encode(array_keys($stockPorEstado), JSON_UNESCAPED_UNICODE) ?>;
const stockData = <?= json_encode(array_values($stockPorEstado), JSON_UNESCAPED_UNICODE) ?>;

const movimientosLabels = <?= json_encode(array_keys($movimientosHoy), JSON_UNESCAPED_UNICODE) ?>;
const movimientosData = <?= json_encode(array_values($movimientosHoy), JSON_UNESCAPED_UNICODE) ?>;

const ubicacionLabels = <?= json_encode(array_column($productosPorUbicacion, 'ubicacion'), JSON_UNESCAPED_UNICODE) ?>;
const ubicacionData = <?= json_encode(array_map('intval', array_column($productosPorUbicacion, 'total')), JSON_UNESCAPED_UNICODE) ?>;

const chartColors = [
    '#0f4c81',
    '#15803d',
    '#b45309',
    '#b91c1c',
    '#0369a1',
    '#7c3aed',
    '#0f766e',
    '#ea580c',
    '#4b5563',
    '#be185d'
];

function crearGraficaDona(id, labels, data) {
    const canvas = document.getElementById(id);

    if (!canvas) return;

    new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: labels.length ? labels : ['Sin datos'],
            datasets: [{
                data: data.length ? data : [1],
                backgroundColor: labels.length ? chartColors : ['#e5e7eb'],
                borderColor: '#ffffff',
                borderWidth: 3,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        padding: 16,
                        font: {
                            size: 12,
                            weight: 'bold'
                        }
                    }
                }
            }
        }
    });
}

function crearGraficaBarras(id, labels, data) {
    const canvas = document.getElementById(id);

    if (!canvas) return;

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Movimientos',
                data: data,
                backgroundColor: ['#15803d', '#b91c1c'],
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

crearGraficaBarras('stockChart', stockLabels, stockData);
crearGraficaDona('ubicacionChart', ubicacionLabels, ubicacionData);
crearGraficaBarras('movimientosChart', movimientosLabels, movimientosData);
</script>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>