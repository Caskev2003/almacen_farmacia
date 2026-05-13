<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/InventarioFisicoVirtualController.php';

requireLogin();

$controller = new InventarioFisicoVirtualController();
$controller->verificarAcceso();

$conteos = $controller->conteos();

$moduleCss = 'inventario_virtual';

include __DIR__ . '/../app/views/layouts/header.php';

?>

<div class="inventario-virtual-container">

    <div class="inventario-header">
        <div>
            <h2>Historial Inventario Virtual</h2>
            <p>
                Conteos físicos capturados desde Tuxtla.
            </p>
        </div>

        <div class="header-actions">
            <a
                href="inventario_virtual.php"
                class="btn-nuevo"
            >
                Nuevo Inventario
            </a>
        </div>
    </div>

    <div class="card historial-card">

        <div class="table-wrapper">

            <table class="inventario-table">

                <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Almacén</th>
                        <th>Usuario</th>
                        <th>Fecha</th>
                        <th>Estado</th>
                        <th>Total Productos</th>
                        <th>Acciones</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($conteos)): ?>

                        <tr>
                            <td colspan="7" class="text-center">
                                No hay inventarios registrados.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($conteos as $conteo): ?>

                            <tr>

                                <td>
                                    <?= e($conteo['folio']) ?>
                                </td>

                                <td>
                                    <?= e($conteo['almacen_nombre'] ?? 'N/A') ?>
                                </td>

                                <td>
                                    <?= e($conteo['usuario_nombre'] ?? 'N/A') ?>
                                </td>

                                <td>
                                    <?= e($conteo['created_at']) ?>
                                </td>

                                <td>

                                    <?php
                                        $estado = $conteo['estado'] ?? 'ABIERTO';
                                    ?>

                                    <span class="badge-estado <?= strtolower($estado) ?>">
                                        <?= e($estado) ?>
                                    </span>

                                </td>

                                <td>
                                    <?= (int)($conteo['total_productos'] ?? 0) ?>
                                </td>

                                <td>

                                    <div class="acciones-grid">

                                        <a
                                            href="exportar_inventario_virtual_excel.php?id=<?= (int)$conteo['id'] ?>"
                                            class="btn-accion excel"
                                            target="_blank"
                                        >
                                            Excel
                                        </a>

                                        <a
                                            href="exportar_inventario_virtual_pdf.php?id=<?= (int)$conteo['id'] ?>"
                                            class="btn-accion pdf"
                                            target="_blank"
                                        >
                                            PDF
                                        </a>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>