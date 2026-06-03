<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/DashboardController.php';

requireLogin();

date_default_timezone_set('America/Mexico_City');

$controller = new DashboardController();

$periodo = $_GET['periodo'] ?? 'hoy';

$indicadores = $controller->getIndicadores($periodo);

$comparativos = $controller->getComparativoIndicadores();

$alertas = $controller->getAlertasInteligentes();

$metricasInteligentes = $controller->getMetricasInteligentes();

$topProductosVendidos = $controller->getTopProductosVendidos(10);

$productosCriticos = $controller->getProductosCriticos(12);

$movimientos = $controller->getMovimientosRecientes(10);

$ubicaciones = $controller->getTopUbicaciones(10);

$documentosMasUsados = $controller->getDocumentosMasUsados(5);

$moduleCss = 'dashboard';

include __DIR__ . '/../app/views/layouts/header.php';

$totalProductos = (int)($indicadores['total_productos'] ?? 0);

$stockCorrecto = (int)($indicadores['stock_correcto'] ?? 0);

$enStock = (int)($indicadores['en_stock'] ?? 0);

$bajoStock = (int)($indicadores['bajo_stock'] ?? 0);

$sinExistencia = (int)($indicadores['sin_existencia'] ?? 0);

$sinAlmacen = (int)($indicadores['sin_almacen'] ?? 0);

$entradasHoy = (int)($indicadores['entradas_periodo'] ?? 0);

$salidasHoy = (int)($indicadores['salidas_periodo'] ?? 0);

$periodoTexto = $indicadores['periodo_texto'] ?? 'Hoy';

$porcentajeStock = $totalProductos > 0
    ? round(($stockCorrecto / $totalProductos) * 100, 1)
    : 0;

$porcentajeSinExistencia = $totalProductos > 0
    ? round(($sinExistencia / $totalProductos) * 100, 1)
    : 0;

$porcentajeSinAlmacen = $totalProductos > 0
    ? round(($sinAlmacen / $totalProductos) * 100, 1)
    : 0;

$ubicacionLabels = array_column($ubicaciones, 'ubicacion');

$ubicacionData = array_map(
    'intval',
    array_column($ubicaciones, 'total')
);

$documentoLabels = array_column(
    $documentosMasUsados,
    'documento'
);

$documentoData = array_map(
    'intval',
    array_column($documentosMasUsados, 'total')
);

?>

<script src="assets/js/chart.umd.min.js"></script>

<div class="power-dashboard">

    <div class="dash-sidebar">

        <div class="side-card pink hover-card">
            <span>Total productos</span>
            <strong><?= number_format($totalProductos) ?></strong>
            <small>Registrados</small>
        </div>

        <div class="side-card blue hover-card">
            <span>Stock correcto</span>
            <strong><?= number_format($stockCorrecto) ?></strong>
            <small><?= $porcentajeStock ?>%</small>
        </div>

        <div class="side-card yellow hover-card">
            <span>Bajo stock</span>
            <strong><?= number_format($bajoStock) ?></strong>
            <small>Productos críticos</small>
        </div>

        <div class="side-card red hover-card">
            <span>Sin existencia</span>
            <strong><?= number_format($sinExistencia) ?></strong>
            <small><?= $porcentajeSinExistencia ?>%</small>
        </div>

        <div class="side-card gray hover-card">
            <span>Sin almacén</span>
            <strong><?= number_format($sinAlmacen) ?></strong>
            <small><?= $porcentajeSinAlmacen ?>%</small>
        </div>

    </div>

    <div class="dash-main">

        <div class="dash-title-card">

            <div>
                <h2>Dashboard Inteligente</h2>

                <p>
                    Análisis avanzado de inventario, movimientos,
                    stock crítico y comportamiento operativo.
                </p>
            </div>

            <div class="dashboard-actions">

                <form method="GET">

                    <select
                        name="periodo"
                        class="dashboard-filter"
                        onchange="this.form.submit()"
                    >
                        <option value="hoy" <?= $periodo === 'hoy' ? 'selected' : '' ?>>
                            Hoy
                        </option>

                        <option value="semana" <?= $periodo === 'semana' ? 'selected' : '' ?>>
                            Esta semana
                        </option>

                        <option value="mes" <?= $periodo === 'mes' ? 'selected' : '' ?>>
                            Este mes
                        </option>

                        <option value="anio" <?= $periodo === 'anio' ? 'selected' : '' ?>>
                            Este año
                        </option>

                    </select>

                </form>

                <div class="date-pill">
                    <span>Periodo</span>
                    <strong><?= e($periodoTexto) ?></strong>
                </div>

            </div>

        </div>

        <?php if (!empty($alertas)): ?>

            <div class="alert-grid">

                <?php foreach ($alertas as $alerta): ?>

                    <a
                        href="<?= e($alerta['link']) ?>"
                        class="alert-card <?= e($alerta['tipo']) ?>"
                    >
                        <div class="alert-icon">
                            <?= e($alerta['icono']) ?>
                        </div>

                        <div class="alert-content">
                            <strong><?= e($alerta['titulo']) ?></strong>

                            <span><?= e($alerta['texto']) ?></span>
                        </div>
                    </a>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <div class="metric-row">

            <div class="metric-card hover-card">

                <span>Entradas</span>

                <strong><?= number_format($entradasHoy) ?></strong>

                <small>

                    <?php
                    $entradasAyer = (int)($comparativos['entradas']['ayer'] ?? 0);

                    if ($entradasHoy > $entradasAyer):
                    ?>
                        ↑ Más que ayer
                    <?php else: ?>
                        ↓ Igual o menor
                    <?php endif; ?>

                </small>

            </div>

            <div class="metric-card hover-card">

                <span>Salidas</span>

                <strong><?= number_format($salidasHoy) ?></strong>

                <small>

                    <?php
                    $salidasAyer = (int)($comparativos['salidas']['ayer'] ?? 0);

                    if ($salidasHoy > $salidasAyer):
                    ?>
                        ↑ Más que ayer
                    <?php else: ?>
                        ↓ Igual o menor
                    <?php endif; ?>

                </small>

            </div>

           <?php foreach ($metricasInteligentes as $metrica): ?>

    <div class="metric-card intelligent hover-card">

        <span><?= e($metrica['titulo']) ?></span>

        <strong class="metric-smart-text">
            <?= e($metrica['principal']) ?>
        </strong>

        <small><?= e($metrica['detalle']) ?></small>

    </div>

<?php endforeach; ?>

        </div>

        <div class="charts-compact-grid">

            <div class="chart-window">

                <div class="chart-head">
                    <h3>Estado de inventario</h3>
                    <span>Productos generales</span>
                </div>

                <div class="chart-box">
                    <canvas id="stockChart"></canvas>
                </div>

            </div>

            <div class="chart-window">

                <div class="chart-head">
                    <h3>Movimientos</h3>
                    <span>Entradas vs salidas</span>
                </div>

                <div class="chart-box">
                    <canvas id="movimientosChart"></canvas>
                </div>

            </div>

            <div class="chart-window">

                <div class="chart-head">
                    <h3>Top ubicaciones</h3>
                    <span>Ubicaciones con más piezas</span>
                </div>

                <div class="chart-box">
                    <canvas id="ubicacionesChart"></canvas>
                </div>

            </div>

            <div class="chart-window">

                <div class="chart-head">
                    <h3>Documentos</h3>
                    <span>Tipos más utilizados</span>
                </div>

                <div class="chart-box">
                    <canvas id="documentosChart"></canvas>
                </div>

            </div>

        </div>
<div class="panel-card top-products-panel">

    <div class="panel-header">

        <div>
            <h3>Top 10 productos más vendidos</h3>

            <p>
                Productos con más salidas registradas.
            </p>
        </div>

    </div>

    <div class="top-products-list">

        <?php if (!empty($topProductosVendidos)): ?>

            <?php foreach ($topProductosVendidos as $index => $producto): ?>

                <div class="top-product-item">

                    <div class="top-product-number">
                        <?= $index + 1 ?>
                    </div>

                    <div class="top-product-info">

                        <strong>
                            <?= e($producto['descripcion'] ?? '') ?>
                        </strong>

                        <span>
                            <?= e($producto['codigo'] ?? '') ?>
                        </span>

                    </div>

                    <div class="top-product-total">

                        <?= number_format((int)($producto['total'] ?? 0)) ?>

                        <small>piezas</small>

                    </div>

                </div>

            <?php endforeach; ?>

        <?php else: ?>

            <p class="empty-panel">
                No hay productos vendidos.
            </p>

        <?php endif; ?>

    </div>

</div>
        <div class="dashboard-panels">

            <div class="panel-card">

                <div class="panel-header">

                    <div>

                        <h3>Productos críticos</h3>

                        <p>
                            Productos con existencia baja.
                        </p>

                    </div>

                    <a
                        href="existencias.php"
                        class="panel-link"
                    >
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
                                <th>Ubicación</th>
                                <th>Semáforo</th>
                                <th>Estado</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php if (!empty($productosCriticos)): ?>

                                <?php foreach ($productosCriticos as $item): ?>

                                    <?php
                                    $estado = $controller->getEstadoProducto($item);

                                    $porcentaje =
                                        $controller->getPorcentajeStockProducto($item);
                                    ?>

                                    <tr class="hover-row">

                                        <td>
                                            <?= e($item['codigo'] ?? '') ?>
                                        </td>

                                        <td>
                                            <?= e($item['descripcion'] ?? '') ?>
                                        </td>

                                        <td>
                                            <?= number_format((int)($item['existencia_actual'] ?? 0)) ?>
                                        </td>

                                        <td>
                                            <?= e($item['ubicacion'] ?? 'SIN UBICACIÓN') ?>
                                        </td>

                                        <td width="180">

                                            <div class="stock-progress">

                                                <div
                                                    class="stock-progress-bar
                                                    <?= e($estado['clase']) ?>"
                                                    style="width: <?= $porcentaje ?>%"
                                                ></div>

                                            </div>

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
                                    <td colspan="6" class="text-center">
                                        No hay productos críticos.
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

                        <h3>Actividad reciente</h3>

                        <p>
                            Últimos movimientos registrados.
                        </p>

                    </div>

                    <a href="reportes.php" class="panel-link">
                        Ver reportes
                    </a>

                </div>

                <div class="movimientos-list">

                    <?php if (!empty($movimientos)): ?>

                        <?php foreach ($movimientos as $mov): ?>

                            <div class="mov-item hover-card">

                                <div class="mov-icon
                                    <?= strtolower(e($mov['tipo_movimiento'] ?? '')) ?>
                                ">

                                    <?php if (($mov['tipo_movimiento'] ?? '') === 'ENTRADA'): ?>
                                        ⬆
                                    <?php else: ?>
                                        ⬇
                                    <?php endif; ?>

                                </div>

                                <div class="mov-content">

                                    <div class="mov-top">

                                        <span class="mov-folio">
                                            <?= e($mov['folio'] ?? '') ?>
                                        </span>

                                        <span class="mov-type
                                            <?= strtolower(e($mov['tipo_movimiento'] ?? '')) ?>
                                        ">
                                            <?= e($mov['tipo_movimiento'] ?? '') ?>
                                        </span>

                                    </div>

                                    <div class="mov-meta">

                                        <span>
                                            <?= e($mov['almacen'] ?? '') ?>
                                        </span>

                                        <span>
                                            <?= e($mov['usuario'] ?? '') ?>
                                        </span>

                                    </div>

                                    <span class="mov-date">

                                        <?= !empty($mov['fecha'])
                                            ? date(
                                                'd/m/Y H:i',
                                                strtotime($mov['fecha'])
                                            )
                                            : ''
                                        ?>

                                    </span>

                                </div>

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

                borderRadius: 10,

                maxBarThickness: 40

            }]
        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            plugins: {

                legend: {
                    display: false
                }

            },

            scales: {

                y: {

                    beginAtZero: true,

                    ticks: {

                        precision: 0,

                        color: chartTextColor

                    },

                    grid: {
                        color: chartGridColor
                    }

                },

                x: {

                    ticks: {
                        color: chartTextColor
                    },

                    grid: {
                        display: false
                    }

                }

            }

        }

    });

}

crearGraficaBarras(
    'stockChart',
    ['Correcto', 'Sin existencia', 'Sin almacén'],
    [
        <?= $stockCorrecto ?>,
        <?= $sinExistencia ?>,
        <?= $sinAlmacen ?>
    ],
    ['#16a34a', '#dc2626', '#64748b']
);

crearGraficaBarras(
    'movimientosChart',
    ['Entradas', 'Salidas'],
    [
        <?= $entradasHoy ?>,
        <?= $salidasHoy ?>
    ],
    ['#2563eb', '#ef4444']
);

crearGraficaBarras(
    'ubicacionesChart',
    <?= json_encode($ubicacionLabels, JSON_UNESCAPED_UNICODE) ?>,
    <?= json_encode($ubicacionData, JSON_UNESCAPED_UNICODE) ?>,
    [
        '#2563eb',
        '#0ea5e9',
        '#14b8a6',
        '#22c55e',
        '#84cc16',
        '#f59e0b',
        '#ef4444',
        '#ec4899',
        '#8b5cf6',
        '#64748b'
    ]
);

crearGraficaBarras(
    'documentosChart',
    <?= json_encode($documentoLabels, JSON_UNESCAPED_UNICODE) ?>,
    <?= json_encode($documentoData, JSON_UNESCAPED_UNICODE) ?>,
    [
        '#ec4899',
        '#2563eb',
        '#14b8a6',
        '#f59e0b',
        '#8b5cf6'
    ]
);

</script>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>