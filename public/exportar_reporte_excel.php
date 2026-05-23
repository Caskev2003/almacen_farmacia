<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/controllers/ReporteController.php';

requireLogin();

$user = currentUser();
$rol = strtoupper($user['rol'] ?? '');
$isAdmin = $rol === 'ADMINISTRADOR';

$sucursalUsuario = $user['almacen_nombre'] 
    ?? $user['sucursal'] 
    ?? $user['almacen'] 
    ?? '';

$controller = new ReporteController();

$columnasDisponibles = $controller->columnasDisponibles();

$columnasSeleccionadas = $_GET['columnas'] ?? [
    'codigo',
    'descripcion',
    'sucursal',
    'ubicacion',
    'existencia',
    'estado_stock'
];

if (!is_array($columnasSeleccionadas)) {
    $columnasSeleccionadas = [];
}

$filtros = [
    'buscar' => trim($_GET['buscar'] ?? ''),
    'sucursal' => trim($_GET['sucursal'] ?? ''),
    'rack' => trim($_GET['rack'] ?? ''),
    'existencia' => trim($_GET['existencia'] ?? ''),
    'orden' => trim($_GET['orden'] ?? 'descripcion'),
];

$datos = $controller->obtenerDatosReporte(
    $filtros,
    $columnasSeleccionadas,
    $isAdmin,
    $sucursalUsuario
);

$filename = 'reporte_inventario_' . date('Y-m-d_H-i-s') . '.xls';

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename={$filename}");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF";
?>

<table border="1">
    <thead>
        <tr>
            <?php foreach ($columnasSeleccionadas as $col): ?>
                <?php if (isset($columnasDisponibles[$col])): ?>
                    <th><?= htmlspecialchars($columnasDisponibles[$col], ENT_QUOTES, 'UTF-8') ?></th>
                <?php endif; ?>
            <?php endforeach; ?>
        </tr>
    </thead>

    <tbody>
        <?php foreach ($datos as $fila): ?>
            <tr>
                <?php foreach ($columnasSeleccionadas as $col): ?>
                    <?php if (isset($columnasDisponibles[$col])): ?>
                        <td><?= htmlspecialchars((string)($fila[$col] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>