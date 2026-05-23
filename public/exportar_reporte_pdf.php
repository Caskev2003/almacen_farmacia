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
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Inventario</title>

    <style>
        @page {
            size: letter landscape;
            margin: 10mm;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            color: #111;
        }

        h1 {
            text-align: center;
            font-size: 18px;
            margin-bottom: 4px;
        }

        .subtitle {
            text-align: center;
            margin-bottom: 15px;
            font-size: 11px;
        }

        .actions {
            text-align: right;
            margin-bottom: 12px;
        }

        .actions button {
            padding: 7px 14px;
            border: none;
            background: #0f62a5;
            color: #fff;
            border-radius: 5px;
            cursor: pointer;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #0f62a5;
            color: white;
            padding: 6px;
            border: 1px solid #333;
            text-align: center;
        }

        td {
            padding: 5px;
            border: 1px solid #555;
        }

        .center {
            text-align: center;
        }

        @media print {
            .actions {
                display: none;
            }
        }
    </style>
</head>
<body>

    <div class="actions">
        <button onclick="window.print()">Imprimir / Guardar PDF</button>
    </div>

    <h1>REPORTE DE INVENTARIO</h1>
    <div class="subtitle">
        Generado el <?= date('d/m/Y H:i') ?> |
        Total de registros: <?= count($datos) ?>
    </div>

    <table>
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
            <?php if (empty($datos)): ?>
                <tr>
                    <td colspan="<?= max(count($columnasSeleccionadas), 1) ?>" class="center">
                        No se encontraron datos.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($datos as $fila): ?>
                    <tr>
                        <?php foreach ($columnasSeleccionadas as $col): ?>
                            <?php if (isset($columnasDisponibles[$col])): ?>
                                <td><?= htmlspecialchars((string)($fila[$col] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

</body>
</html>