<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/controllers/InventarioFisicoVirtualController.php';

requireLogin();

$controller = new InventarioFisicoVirtualController();
$controller->verificarAcceso();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$conteo = $controller->obtenerConteo($id);
$detalle = $controller->obtenerDetalle($id);

if (!$conteo) {
    die('Inventario no encontrado.');
}

$filename = 'inventario_virtual_' . $conteo['folio'] . '.xls';

header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF";
?>

<table border="1">
    <tr>
        <th colspan="8">INVENTARIO VIRTUAL TUXTLA</th>
    </tr>
    <tr>
        <td>Folio</td>
        <td><?= htmlspecialchars($conteo['folio']) ?></td>
    </tr>
    <tr>
        <td>Fecha</td>
        <td><?= htmlspecialchars($conteo['created_at']) ?></td>
    </tr>
    <tr>
        <td>Estado</td>
        <td><?= htmlspecialchars($conteo['estado']) ?></td>
    </tr>
</table>

<br>

<table border="1">
    <thead>
        <tr>
            <th>Artículo</th>
            <th>Descripción</th>
            <th>Mostrador</th>
            <th>Piqueo</th>
            <th>Almacén</th>
            <th>Bodega</th>
            <th>Total</th>
            <th>Fecha captura</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($detalle as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['codigo_barras']) ?></td>
                <td><?= htmlspecialchars($row['descripcion']) ?></td>
                <td><?= (int)$row['mostrador'] ?></td>
                <td><?= (int)$row['piqueo'] ?></td>
                <td><?= (int)$row['almacen'] ?></td>
                <td><?= (int)$row['bodega'] ?></td>
                <td><?= (int)$row['total'] ?></td>
                <td><?= htmlspecialchars($row['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>