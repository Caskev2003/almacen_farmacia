<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/controllers/AgotadoController.php';

requireLogin();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$controller = new AgotadoController();

$user = $_SESSION['user'] ?? [];
$rol = strtoupper(trim($user['rol'] ?? ''));
$esAdmin = in_array($rol, ['ADMINISTRADOR', 'ADMIN'], true);

$tipo = trim($_GET['tipo'] ?? 'sin_ubicacion');

if (!in_array($tipo, ['sin_ubicacion', 'sin_existencia', 'ambas'], true)) {
    $tipo = 'sin_ubicacion';
}

$filtros = [
    'buscar' => trim($_GET['buscar'] ?? ''),
    'almacen_id' => isset($_GET['almacen_id']) ? (int)$_GET['almacen_id'] : 0,
    'tipo' => $tipo,
    'pagina' => 1,
    'por_pagina' => 100000,
];

if (!$esAdmin) {
    $filtros['almacen_id'] = (int)($user['almacen_id'] ?? 0);
}

$resultado = $controller->listar($filtros);
$productos = $resultado['items'] ?? [];

$tituloReporte = match ($tipo) {
    'sin_existencia' => 'REPORTE DE PRODUCTOS SIN EXISTENCIA',
    'ambas' => 'REPORTE DE PRODUCTOS SIN ALMACÉN',
    default => 'REPORTE DE PRODUCTOS SIN UBICACIÓN',
};

$filename = 'reporte_agotados_' . $tipo . '_' . date('Y-m-d_H-i-s') . '.xls';

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename={$filename}");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF";
?>

<table border="1">
    <thead>
        <tr>
            <th colspan="10" style="font-size:18px; background:#991b1b; color:#ffffff;">
                <?= htmlspecialchars($tituloReporte, ENT_QUOTES, 'UTF-8') ?>
            </th>
        </tr>

        <tr>
            <th colspan="10">
                Generado: <?= date('d/m/Y H:i') ?> |
                Total registros: <?= count($productos) ?>
            </th>
        </tr>

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

                    $sucursal = trim((string)($producto['sucursal'] ?? ''));
                    $sucursales = trim((string)($producto['sucursales'] ?? ''));

                    $almacenMostrar = $sucursal !== '' && strtoupper($sucursal) !== 'SIN ALMACEN'
                        ? $sucursal
                        : ($sucursales !== '' ? $sucursales : 'SIN ALMACEN');

                    $ubicacion = strtoupper(trim((string)($producto['ubicacion'] ?? 'SIN UBICACION')));
                    $motivo = $producto['motivo'] ?? '';

                    if ($motivo === '') {
                        if ($almacenMostrar === 'SIN ALMACEN') {
                            $motivo = 'SIN ALMACEN';
                        } elseif ($existencia <= 0 && in_array($ubicacion, ['', 'SIN UBICACION', 'SIN UBICACIÓN'], true)) {
                            $motivo = 'SIN UBICACIÓN Y SIN EXISTENCIA';
                        } elseif ($existencia <= 0) {
                            $motivo = 'SIN EXISTENCIA';
                        } else {
                            $motivo = 'SIN UBICACIÓN';
                        }
                    }
                ?>

                <tr>
                    <td><?= htmlspecialchars($producto['codigo'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($producto['codigo_barras'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($producto['descripcion'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($producto['categoria'] ?? 'Sin categoría', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($producto['proveedor'] ?? 'Sin proveedor', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($producto['laboratorio'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($almacenMostrar, ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($producto['ubicacion'] ?? 'SIN UBICACION', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $existencia ?></td>
                    <td><?= htmlspecialchars($motivo, ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="10">
                    No se encontraron productos para los filtros seleccionados.
                </td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>