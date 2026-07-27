<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/DashboardController.php';

requireLogin();

date_default_timezone_set('America/Mexico_City');

$periodosPermitidos = [
    'hoy',
    'semana',
    'mes',
    'anio'
];

$periodoSolicitado = strtolower(
    trim((string) ($_GET['periodo'] ?? 'hoy'))
);

$periodo = in_array(
    $periodoSolicitado,
    $periodosPermitidos,
    true
) ? $periodoSolicitado : 'hoy';

$errorDashboard = '';

$indicadores = [];
$comparativos = [];
$alertas = [];
$metricasInteligentes = [];
$topProductosVendidos = [];
$productosCriticos = [];
$movimientos = [];
$ubicaciones = [];
$documentosMasUsados = [];

$controller = null;

try {
    $controller = new DashboardController();

    $indicadores =
        $controller->getIndicadores($periodo);

    $comparativos =
        $controller->getComparativoIndicadores();

    $alertas =
        $controller->getAlertasInteligentes();

    $metricasInteligentes =
        $controller->getMetricasInteligentes();

    $topProductosVendidos =
        $controller->getTopProductosVendidos(10);

    $productosCriticos =
        $controller->getProductosCriticos(12);

    $movimientos =
        $controller->getMovimientosRecientes(10);

    $ubicaciones =
        $controller->getTopUbicaciones(10);

    $documentosMasUsados =
        $controller->getDocumentosMasUsados(5);
} catch (Throwable $e) {
    error_log(
        'Error al cargar el dashboard: '
        . $e->getMessage()
    );

    $errorDashboard =
        'No fue posible cargar todos los datos del dashboard.';
}

$indicadores = is_array($indicadores)
    ? $indicadores
    : [];

$comparativos = is_array($comparativos)
    ? $comparativos
    : [];

$alertas = is_array($alertas)
    ? $alertas
    : [];

$metricasInteligentes =
    is_array($metricasInteligentes)
        ? $metricasInteligentes
        : [];

$topProductosVendidos =
    is_array($topProductosVendidos)
        ? $topProductosVendidos
        : [];

$productosCriticos =
    is_array($productosCriticos)
        ? $productosCriticos
        : [];

$movimientos = is_array($movimientos)
    ? $movimientos
    : [];

$ubicaciones = is_array($ubicaciones)
    ? $ubicaciones
    : [];

$documentosMasUsados =
    is_array($documentosMasUsados)
        ? $documentosMasUsados
        : [];

$moduleCss = 'dashboard';

include __DIR__ . '/../app/views/layouts/header.php';

$totalProductos = (int)($indicadores['total_productos'] ?? 0);

$stockCorrecto = (int)($indicadores['stock_correcto'] ?? 0);

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

$jsonSeguro =
    JSON_UNESCAPED_UNICODE
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
    | JSON_INVALID_UTF8_SUBSTITUTE;

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
                        aria-label="Seleccionar periodo del dashboard"
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

        <?php if ($errorDashboard !== ''): ?>

            <div
                class="alert-card danger"
                role="alert"
            >
                <div class="alert-icon">!</div>

                <div class="alert-content">
                    <strong>Error al cargar información</strong>
                    <span><?= e($errorDashboard) ?></span>
                </div>
            </div>

        <?php endif; ?>

        <?php if (!empty($alertas)): ?>

            <div class="alert-grid">

                <?php foreach ($alertas as $alerta): ?>

                    <a
                        href="<?= e($alerta['link'] ?? '#') ?>"
                        class="alert-card <?= e($alerta['tipo'] ?? 'neutral') ?>"
                    >
                        <div class="alert-icon">
                            <?= e($alerta['icono'] ?? '!') ?>
                        </div>

                        <div class="alert-content">
                            <strong>
                                <?= e($alerta['titulo'] ?? 'Aviso') ?>
                            </strong>

                            <span>
                                <?= e($alerta['texto'] ?? '') ?>
                            </span>
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

                    <span>
                        <?= e($metrica['titulo'] ?? '') ?>
                    </span>

                    <strong class="metric-smart-text">
                        <?= e($metrica['principal'] ?? '') ?>
                    </strong>

                    <small>
                        <?= e($metrica['detalle'] ?? '') ?>
                    </small>

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
                    <canvas
                        id="stockChart"
                        role="img"
                        aria-label="Gráfica del estado del inventario"
                    ></canvas>
                </div>

            </div>

            <div class="chart-window">

                <div class="chart-head">
                    <h3>Movimientos</h3>
                    <span>Entradas vs salidas</span>
                </div>

                <div class="chart-box">
                    <canvas
                        id="movimientosChart"
                        role="img"
                        aria-label="Gráfica de entradas y salidas"
                    ></canvas>
                </div>

            </div>

            <div class="chart-window">

                <div class="chart-head">
                    <h3>Top ubicaciones</h3>
                    <span>Ubicaciones con más piezas</span>
                </div>

                <div class="chart-box">
                    <canvas
                        id="ubicacionesChart"
                        role="img"
                        aria-label="Gráfica de ubicaciones con más piezas"
                    ></canvas>
                </div>

            </div>

            <div class="chart-window">

                <div class="chart-head">
                    <h3>Documentos</h3>
                    <span>Tipos más utilizados</span>
                </div>

                <div class="chart-box">
                    <canvas
                        id="documentosChart"
                        role="img"
                        aria-label="Gráfica de documentos más utilizados"
                    ></canvas>
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

                                <?= number_format(
                                    (int) (
                                        $producto['total']
                                        ?? 0
                                    )
                                ) ?>

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

                                    $porcentaje = max(
                                        0,
                                        min(
                                            100,
                                            (float) $controller
                                                ->getPorcentajeStockProducto(
                                                    $item
                                                )
                                        )
                                    );

                                    $claseEstado = (string) (
                                        $estado['clase']
                                        ?? 'badge-danger'
                                    );

                                    $textoEstado = (string) (
                                        $estado['texto']
                                        ?? 'SIN INFORMACIÓN'
                                    );
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
                                                    <?= e($claseEstado) ?>"
                                                    style="width: <?=
                                                        e((string) $porcentaje)
                                                    ?>%"
                                                ></div>

                                            </div>

                                        </td>

                                        <td>

                                            <span class="badge-status <?= e($claseEstado) ?>">
                                                <?= e($textoEstado) ?>
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

                            <?php
                            $tipoMovimiento = strtoupper(
                                trim(
                                    (string) (
                                        $mov['tipo_movimiento']
                                        ?? ''
                                    )
                                )
                            );

                            $claseMovimiento =
                                $tipoMovimiento === 'ENTRADA'
                                    ? 'entrada'
                                    : (
                                        $tipoMovimiento === 'SALIDA'
                                            ? 'salida'
                                            : ''
                                    );

                            $fechaMovimiento = '';

                            if (!empty($mov['fecha'])) {
                                $timestamp = strtotime(
                                    (string) $mov['fecha']
                                );

                                if ($timestamp !== false) {
                                    $fechaMovimiento = date(
                                        'd/m/Y H:i',
                                        $timestamp
                                    );
                                }
                            }
                            ?>

                            <div class="mov-item hover-card">

                                <div class="mov-icon <?= e($claseMovimiento) ?>">

                                    <?php if ($tipoMovimiento === 'ENTRADA'): ?>
                                        ⬆
                                    <?php elseif ($tipoMovimiento === 'SALIDA'): ?>
                                        ⬇
                                    <?php else: ?>
                                        •
                                    <?php endif; ?>

                                </div>

                                <div class="mov-content">

                                    <div class="mov-top">

                                        <span class="mov-folio">
                                            <?= e($mov['folio'] ?? '') ?>
                                        </span>

                                        <span class="mov-type <?= e($claseMovimiento) ?>">
                                            <?= e(
                                                $tipoMovimiento !== ''
                                                    ? $tipoMovimiento
                                                    : 'MOVIMIENTO'
                                            ) ?>
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
                                        <?= e($fechaMovimiento) ?>
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

(function () {
    'use strict';

    if (typeof Chart === 'undefined') {
        console.error(
            'No fue posible cargar Chart.js.'
        );

        return;
    }

    const chartTextColor = '#374151';
    const chartGridColor = '#e5e7eb';

    const pantallaTablet =
        window.matchMedia('(max-width: 900px)');

    const movimientoReducido =
        window.matchMedia(
            '(prefers-reduced-motion: reduce)'
        );

    function recortarEtiqueta(
        valor,
        limite
    ) {
        const texto = String(valor ?? '');

        return texto.length > limite
            ? texto.slice(0, limite - 1) + '…'
            : texto;
    }

    function crearGraficaBarras(
        id,
        labels,
        data,
        colores
    ) {
        const canvas =
            document.getElementById(id);

        if (!canvas) {
            return;
        }

        const etiquetas = Array.isArray(labels)
            ? labels.map(function (label) {
                return String(label ?? '');
            })
            : [];

        const valores = Array.isArray(data)
            ? data.map(function (valor) {
                const numero = Number(valor);

                return Number.isFinite(numero)
                    ? numero
                    : 0;
            })
            : [];

        const esTablet = pantallaTablet.matches;

        new Chart(canvas, {
            type: 'bar',

            data: {
                labels: etiquetas,

                datasets: [{
                    data: valores,
                    backgroundColor: colores,
                    borderRadius: 10,
                    borderSkipped: false,
                    maxBarThickness:
                        esTablet ? 34 : 40
                }]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 150,

                devicePixelRatio: Math.min(
                    window.devicePixelRatio || 1,
                    2
                ),

                animation: movimientoReducido.matches
                    ? false
                    : {
                        duration: 450
                    },

                interaction: {
                    mode: 'nearest',
                    intersect: false
                },

                plugins: {
                    legend: {
                        display: false
                    },

                    tooltip: {
                        displayColors: true,

                        callbacks: {
                            title: function (elementos) {
                                if (!elementos.length) {
                                    return '';
                                }

                                return etiquetas[
                                    elementos[0].dataIndex
                                ] ?? '';
                            }
                        }
                    }
                },

                scales: {
                    y: {
                        beginAtZero: true,

                        ticks: {
                            precision: 0,
                            color: chartTextColor,
                            font: {
                                size: esTablet ? 12 : 11
                            }
                        },

                        grid: {
                            color: chartGridColor
                        }
                    },

                    x: {
                        ticks: {
                            color: chartTextColor,
                            autoSkip: true,
                            maxTicksLimit:
                                esTablet ? 6 : 10,
                            maxRotation: 0,
                            minRotation: 0,

                            font: {
                                size: esTablet ? 12 : 11
                            },

                            callback: function (
                                valor,
                                indice
                            ) {
                                const etiqueta =
                                    typeof this.getLabelForValue
                                        === 'function'
                                        ? this.getLabelForValue(
                                            valor
                                        )
                                        : etiquetas[indice];

                                return recortarEtiqueta(
                                    etiqueta,
                                    esTablet ? 18 : 28
                                );
                            }
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
        [
            'Correcto',
            'Sin existencia',
            'Sin almacén'
        ],
        [
            <?= $stockCorrecto ?>,
            <?= $sinExistencia ?>,
            <?= $sinAlmacen ?>
        ],
        [
            '#16a34a',
            '#dc2626',
            '#64748b'
        ]
    );

    crearGraficaBarras(
        'movimientosChart',
        [
            'Entradas',
            'Salidas'
        ],
        [
            <?= $entradasHoy ?>,
            <?= $salidasHoy ?>
        ],
        [
            '#2563eb',
            '#ef4444'
        ]
    );

    crearGraficaBarras(
        'ubicacionesChart',
        <?= json_encode(
            array_values($ubicacionLabels),
            $jsonSeguro
        ) ?>,
        <?= json_encode(
            array_values($ubicacionData),
            $jsonSeguro
        ) ?>,
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
        <?= json_encode(
            array_values($documentoLabels),
            $jsonSeguro
        ) ?>,
        <?= json_encode(
            array_values($documentoData),
            $jsonSeguro
        ) ?>,
        [
            '#ec4899',
            '#2563eb',
            '#14b8a6',
            '#f59e0b',
            '#8b5cf6'
        ]
    );
}());

</script>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>