<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/SalidaController.php';

requireLogin();

$controller = new SalidaController();

$buscar = trim($_GET['buscar'] ?? '');
$salidas = $controller->historialSalidas($buscar);

$moduleCss = 'salidas';
include __DIR__ . '/../app/views/layouts/header.php';
?>

<div class="module-header">
    <div>
        <h2>Historial de Salidas</h2>
        <p>Consulta todas las salidas registradas y vuelve a imprimirlas.</p>
    </div>
</div>

<div class="erp-table-card">
    <form method="GET" style="display:flex; gap:10px; margin-bottom:18px;">
        <input 
            type="text" 
            name="buscar" 
            value="<?= e($buscar) ?>" 
            placeholder="Buscar por folio, documento, almacén o usuario..."
            style="flex:1; padding:10px 12px; border:1px solid #d1d5db; border-radius:10px;"
        >

        <button class="btn-primary-action" type="submit">Buscar</button>
        <a href="historial_salidas.php" class="btn-secondary-action">Limpiar</a>
    </form>

    <div class="table-responsive">
        <table class="erp-table tabla-salida">
            <thead>
                <tr>
                    <th>Folio</th>
                    <th>Fecha</th>
                    <th>Movimiento</th>
                    <th>Documento</th>
                    <th>Almacén</th>
                    <th>Usuario</th>
                    <th>Total</th>
                    <th>Imprimir</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($salidas)): ?>
                    <tr>
                        <td colspan="8" class="empty-table">No hay salidas registradas.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($salidas as $salida): ?>
                        <tr>
                            <td><?= e($salida['folio']) ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($salida['fecha'])) ?></td>
                            <td><?= e($salida['referencia'] ?? '') ?></td>
                            <td><?= e($salida['tipo_operacion'] ?? '') ?></td>
                            <td><?= e($salida['almacen_nombre'] ?? '') ?></td>
                            <td><?= e($salida['usuario_nombre'] ?? '') ?></td>
                            <td>$<?= number_format((float)$salida['total'], 2) ?></td>
                            <td>
                                <a 
                                    href="imprimir_salida.php?id=<?= (int)$salida['id'] ?>&preview=1" 
                                    class="btn-primary-action"
                                    target="_blank"
                                >
                                    Imprimir
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
if (file_exists(__DIR__ . '/../app/views/layouts/footer.php')) {
    include __DIR__ . '/../app/views/layouts/footer.php';
}
?>