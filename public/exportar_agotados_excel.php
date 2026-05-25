<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/controllers/AgotadoController.php';

requireLogin();

$controller = new AgotadoController();

$filtros = [
    'buscar' => trim($_GET['buscar'] ?? ''),
    'almacen_id' => isset($_GET['almacen_id']) ? (int)$_GET['almacen_id'] : 0,
];

$productos = $controller->listar($filtros);

$filename = 'reporte_agotados_' . date('Y-m-d_H-i-s') . '.xls';

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename={$filename}");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF";
?>

<table border="1">
    <thead>
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
        <?php foreach ($productos as $producto): ?>
            <?php
                $existencia = (int)($producto['existencia'] ?? 0);
                $ubicacion = strtoupper(trim($producto['ubicacion'] ?? ''));

                if ($existencia <= 0 && ($ubicacion === '' || $ubicacion === 'SIN UBICACION')) {
                    $motivo = 'SIN UBICACIÓN Y SIN EXISTENCIA';
                } elseif ($existencia <= 0) {
                    $motivo = 'SIN EXISTENCIA';
                } else {
                    $motivo = 'SIN UBICACIÓN';
                }
            ?>
            <tr>
                <td><?= htmlspecialchars($producto['codigo'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($producto['codigo_barras'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($producto['descripcion'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($producto['categoria'] ?? 'Sin categoría', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($producto['proveedor'] ?? 'Sin proveedor', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($producto['laboratorio'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($producto['sucursal'] ?? 'SIN ALMACEN', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($producto['ubicacion'] ?? 'SIN UBICACION', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= $existencia ?></td>
                <td><?= htmlspecialchars($motivo, ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>