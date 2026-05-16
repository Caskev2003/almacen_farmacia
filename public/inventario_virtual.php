<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/InventarioFisicoVirtualController.php';

requireLogin();

$user = currentUser();

$rolUsuario = strtoupper(trim($user['rol'] ?? ''));
$almacenSesion = (int)($user['almacen_id'] ?? 0);

$puedeEntrar =
    $rolUsuario === 'ADMINISTRADOR'
    || $rolUsuario === 'ENCARGADO'
    || $rolUsuario === 'ALMACEN'
    || $rolUsuario === 'GERENTE'
    || in_array($almacenSesion, [1, 2, 3], true);

if (!$puedeEntrar) {
    header('Location: dashboard.php');
    exit;
}

$controller = new InventarioFisicoVirtualController();

$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resultado = $controller->guardar(
        $_POST,
        (int)$user['id']
    );

    if ($resultado['success']) {
        header('Location: inventario_virtual.php?success=1');
        exit;
    }

    $error = $resultado['message'];
}

if (isset($_GET['success'])) {
    $mensaje = 'Inventario guardado correctamente.';
}

$folio = $controller->generarFolio();
$almacenes = $controller->almacenes();

$moduleCss = 'inventario_virtual';

include __DIR__ . '/../app/views/layouts/header.php';
?>

<div class="inventario-virtual-container">

    <div class="inventario-header">
        <div>
            <h2>Inventario Virtual</h2>
            <p>Escanea productos y captura cantidades físicas.</p>
        </div>

        <div class="header-actions">
            <a href="inventario_virtual_historial.php" class="btn-nuevo">
                Ver Historial
            </a>
        </div>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert success">
            <?= e($mensaje) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="inventarioForm">

        <div class="card encabezado-card">
            <div class="grid-top">

                <div class="field">
                    <label>Folio</label>
                    <input
                        type="text"
                        name="folio"
                        value="<?= e($folio) ?>"
                        readonly
                    >
                </div>

                <div class="field">
                    <label>Almacén</label>

                    <select
                        name="almacen_id"
                        required
                        <?= $rolUsuario !== 'ADMINISTRADOR' ? 'disabled' : '' ?>
                    >
                        <?php if ($rolUsuario === 'ADMINISTRADOR'): ?>
                            <option value="">Seleccione</option>
                        <?php endif; ?>

                        <?php foreach ($almacenes as $almacen): ?>
                            <?php
                                $idAlmacen = (int)$almacen['id'];
                                $selected = false;

                                if ($rolUsuario !== 'ADMINISTRADOR' && $idAlmacen === $almacenSesion) {
                                    $selected = true;
                                }

                                if ($rolUsuario === 'ADMINISTRADOR' && $idAlmacen === 3) {
                                    $selected = true;
                                }
                            ?>

                            <option
                                value="<?= $idAlmacen ?>"
                                <?= $selected ? 'selected' : '' ?>
                            >
                                <?= e($almacen['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php if ($rolUsuario !== 'ADMINISTRADOR'): ?>
                        <input
                            type="hidden"
                            name="almacen_id"
                            value="<?= (int)$almacenSesion ?>"
                        >
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label>Observaciones</label>
                    <input
                        type="text"
                        name="observaciones"
                        placeholder="Comentarios generales..."
                    >
                </div>

            </div>
        </div>

        <div class="card captura-card">

            <div class="scanner-box">
                <label>Escanear código</label>
                <input
                    type="text"
                    id="scannerInput"
                    placeholder="Escanea aquí..."
                    autocomplete="off"
                    autofocus
                >
            </div>

            <div class="table-wrapper">
                <table class="inventario-table">
                    <thead>
                        <tr>
                            <th>Artículo</th>
                            <th>Descripción</th>
                            <th>Mostrador</th>
                            <th>Piqueo</th>
                            <th>Almacén</th>
                            <th>Bodega</th>
                            <th>Total</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody id="inventarioBody"></tbody>
                </table>
            </div>

            <div class="acciones-footer">
                <button type="submit" class="btn-guardar">
                    Guardar Inventario
                </button>
            </div>

        </div>

    </form>
</div>

<script>
const scannerInput = document.getElementById('scannerInput');
const inventarioBody = document.getElementById('inventarioBody');

window.addEventListener('load', () => {
    scannerInput.focus();
});

scannerInput.addEventListener('keydown', async function(e) {
    if (e.key !== 'Enter') return;

    e.preventDefault();

    const codigo = scannerInput.value.trim();

    if (!codigo) return;

    const existe = Array
        .from(document.querySelectorAll('.codigo-input'))
        .find(input => input.value === codigo);

    if (existe) {
        const row = existe.closest('tr');
        const mostrador = row.querySelector('.mostrador');

        mostrador.focus();
        mostrador.select();

        scannerInput.value = '';
        return;
    }

    try {
        const response = await fetch(
            `api_buscar_producto.php?codigo=${encodeURIComponent(codigo)}`
        );

        const data = await response.json();

        agregarFila({
            id: data.success ? (data.id ?? '') : '',
            codigo: codigo,
            descripcion: data.success ? (data.descripcion ?? '') : 'PRODUCTO NO ENCONTRADO'
        });

    } catch (error) {
        agregarFila({
            id: '',
            codigo: codigo,
            descripcion: 'PRODUCTO NO ENCONTRADO'
        });
    }

    scannerInput.value = '';
});

function escaparHtml(valor) {
    return String(valor ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function agregarFila(producto) {
    const tr = document.createElement('tr');

    tr.innerHTML = `
        <td>
            <input type="hidden" name="producto_id[]" value="${escaparHtml(producto.id)}">

            <input
                type="text"
                class="codigo-input"
                name="codigo_barras[]"
                value="${escaparHtml(producto.codigo)}"
                readonly
            >
        </td>

        <td>
            <input
                type="text"
                name="descripcion[]"
                value="${escaparHtml(producto.descripcion)}"
                readonly
            >
        </td>

        <td>
            <input
                type="number"
                name="mostrador[]"
                class="cantidad-input mostrador"
                min="0"
                value="0"
            >
        </td>

        <td>
            <input
                type="number"
                name="piqueo[]"
                class="cantidad-input"
                min="0"
                value="0"
            >
        </td>

        <td>
            <input
                type="number"
                name="almacen[]"
                class="cantidad-input"
                min="0"
                value="0"
            >
        </td>

        <td>
            <input
                type="number"
                name="bodega[]"
                class="cantidad-input"
                min="0"
                value="0"
            >
        </td>

        <td>
            <input
                type="number"
                class="total-input"
                value="0"
                readonly
            >
        </td>

        <td>
            <button
                type="button"
                class="btn-delete"
                onclick="eliminarFila(this)"
            >
                X
            </button>
        </td>
    `;

    inventarioBody.appendChild(tr);
    configurarEventosFila(tr);

    const primerInput = tr.querySelector('.mostrador');
    primerInput.focus();
    primerInput.select();
}

function configurarEventosFila(tr) {
    const inputs = tr.querySelectorAll('.cantidad-input');

    inputs.forEach((input, index) => {
        input.addEventListener('input', () => {
            recalcularTotal(tr);
        });

        input.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter') return;

            e.preventDefault();

            const siguiente = inputs[index + 1];

            if (siguiente) {
                siguiente.focus();
                siguiente.select();
            } else {
                scannerInput.focus();
            }
        });
    });
}

function recalcularTotal(tr) {
    const mostrador = parseInt(tr.querySelector('[name="mostrador[]"]').value || 0);
    const piqueo = parseInt(tr.querySelector('[name="piqueo[]"]').value || 0);
    const almacen = parseInt(tr.querySelector('[name="almacen[]"]').value || 0);
    const bodega = parseInt(tr.querySelector('[name="bodega[]"]').value || 0);

    const total = mostrador + piqueo + almacen + bodega;

    tr.querySelector('.total-input').value = total;
}

function eliminarFila(btn) {
    if (!confirm('¿Eliminar este producto?')) return;

    btn.closest('tr').remove();
}

document.getElementById('inventarioForm').addEventListener('submit', function(e) {
    const filas = inventarioBody.querySelectorAll('tr');

    if (filas.length === 0) {
        e.preventDefault();
        alert('Debe capturar al menos un producto.');
        return;
    }

    const confirmar = confirm(
        '¿Seguro que deseas guardar el inventario?\n\nDespués no podrá editarse.'
    );

    if (!confirmar) {
        e.preventDefault();
    }
});
</script>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>