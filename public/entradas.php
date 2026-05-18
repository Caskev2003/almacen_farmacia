<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/EntradaController.php';

requireLogin();

$user = currentUser();
$controller = new EntradaController();

$message = '';
$messageType = 'danger';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $controller->guardar($_POST, (int)$user['id']);

    $message = $result['message'];
    $messageType = $result['success'] ? 'success' : 'danger';

    if ($result['success']) {
        $movimientoId = (int)($result['movimiento_id'] ?? 0);

        if ($movimientoId > 0) {
            header('Location: imprimir_entrada.php?id=' . $movimientoId . '&preview=1');
            exit;
        }

        header('Location: entradas.php?success=1&folio=' . urlencode($result['folio']));
        exit;
    }
}

if (isset($_GET['success'])) {
    $folioOk = trim($_GET['folio'] ?? '');
    $message = 'Entrada registrada correctamente. Folio: ' . $folioOk;
    $messageType = 'success';
}

$almacenes = $controller->almacenes();
$proveedores = $controller->proveedores();
$productos = $controller->productos();
$tiposEntrada = $controller->tiposEntrada();

$almacenSesion = (int)($user['almacen_id'] ?? 0);

$folio = $controller->generarFolio($almacenSesion);

$fechaActual = date('Y-m-d\TH:i');

$moduleCss = 'entradas';
include __DIR__ . '/../app/views/layouts/header.php';
?>

<div class="module-header">
    <div>
        <h2>Entradas de Almacén</h2>
        <p>Registro de ingresos de mercancía al almacén.</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= e($messageType) ?>">
        <?= e($message) ?>
    </div>
<?php endif; ?>

<form method="POST" action="" id="formEntrada">
    <div class="salida-encabezado-card">
        <div class="salida-encabezado-grid">

            <div class="salida-field">
                <label>Folio</label>
                <input type="text" name="folio" value="<?= e($folio) ?>" readonly>
            </div>

            <div class="salida-field">
                <label>Fecha</label>
                <input type="datetime-local" name="fecha" value="<?= e($fechaActual) ?>" required>
            </div>

            <div class="salida-field folio-anterior-field">
                <label>Folio anterior</label>
                <input 
                    type="text" 
                    name="folio_anterior" 
                    placeholder="Folio anterior"
                >
            </div>

            <div class="salida-field tipo-field">
                <label>Tipo de entrada *</label>
                <select name="tipo_entrada" required>
                    <option value="">Seleccione el tipo de entrada</option>
                    <?php foreach ($tiposEntrada as $tipo): ?>
                        <option value="<?= e($tipo['clave'] . ' - ' . $tipo['descripcion']) ?>">
                            <?= e($tipo['clave']) ?> <?= e($tipo['descripcion']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="salida-field almacen-field">
                <label>Almacén *</label>
                <select name="almacen_id" required <?= strtoupper($user['rol'] ?? '') !== 'ADMINISTRADOR' ? 'disabled' : '' ?>>
                    <option value="">Seleccione un almacén</option>
                    <?php foreach ($almacenes as $almacen): ?>
                        <option value="<?= (int)$almacen['id'] ?>"<?= (int)$almacen['id'] === $almacenSesion ? 'selected' : '' ?>>
                            <?= e($almacen['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="salida-field">
                <label>Proveedor</label>

                <input
                        type="text"
                        name="proveedor_nombre"
                        placeholder="Ingrese el nombre del proveedor"
                >
            </div>

            <div class="salida-field">
                <label>Referencia</label>
                <input 
                    type="text" 
                    name="referencia" 
                    placeholder="Factura, remisión, nota, etc."
                >
            </div>

            <div class="salida-field observaciones-field">
                <label>Observaciones</label>
                <textarea name="observaciones" rows="4" placeholder="Escribe observaciones de la entrada."></textarea>
            </div>

        </div>
    </div>

    <div class="salida-captura-card">
        <h3>Captura de Partidas</h3>

        <div class="salida-captura-grid">

            <div class="salida-field mini-field">
                <label>Cantidad</label>
                <input type="number" id="cantidad_input" min="1" value="1">
            </div>

            <div class="salida-field">
                <label>Producto</label>

                <select id="producto_select" style="display:none;">
                    <option value="">Seleccione un producto</option>
                    <?php foreach ($productos as $producto): ?>
                        <option
                            value="<?= (int)$producto['id'] ?>"
                            data-codigo="<?= e($producto['codigo']) ?>"
                            data-descripcion="<?= e($producto['descripcion']) ?>"
                            data-costo="<?= e((string)$producto['precio_compra']) ?>"
                            data-ubicacion="<?= e($producto['ubicacion']) ?>"
                        >
                            <?= e($producto['codigo']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="button" class="btn-buscar-producto" onclick="abrirModalProductos()">
                    Buscar producto
                </button>
            </div>

            <div class="salida-field salida-descripcion-field">
                <label>Descripción</label>
                <input type="text" id="descripcion_input" readonly>
            </div>

            <div class="salida-field mini-field">
                <label>Costo Unitario</label>
                <input type="number" id="costo_input" step="0.01" min="0" value="0.00">
            </div>

            <div class="salida-field">
                <label>Número de lote</label>
                <input type="text" id="lote_input">
            </div>

            <div class="salida-field mini-field">
                <label>Caducidad</label>
                <input type="date" id="caducidad_input">
            </div>

            <div class="salida-field">
                <label>Ubicación</label>
                <input type="text" id="ubicacion_input">
            </div>

            <div class="salida-actions">
                <button type="button" class="btn-primary-action" onclick="agregarProductoDetalle()">Agregar</button>
            </div>
        </div>
    </div>

    <div class="erp-table-card">
        <div class="table-topbar">
            <h3>Detalle de la entrada</h3>
        </div>

        <div class="table-responsive">
            <table class="erp-table tabla-salida" id="tablaDetalleEntrada">
                <thead>
                    <tr>
                        <th>Cantidad</th>
                        <th>Clave</th>
                        <th>Descripción</th>
                        <th>Costo Unitario</th>
                        <th>Lote</th>
                        <th>Caducidad</th>
                        <th>Ubicación</th>
                        <th>Importe</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody id="detalleBody">
                    <tr id="filaVaciaDetalle">
                        <td colspan="9" class="empty-table">No has agregado productos.</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="7" style="text-align:right;">Total:</th>
                        <th id="totalEntrada">$0.00</th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="form-actions" style="margin-top:20px;">
            <button type="submit" class="btn-primary-action">Guardar entrada</button>
            <a href="entradas.php" class="btn-secondary-action">Nueva captura</a>
        </div>
    </div>
</form>

<div class="modal-productos-overlay" id="modalProductos">
    <div class="modal-productos-card">
        <div class="modal-productos-header">
            <div>
                <h3>Buscar producto</h3>
                <p>Selecciona el producto que deseas agregar a la entrada.</p>
            </div>

            <button type="button" class="modal-productos-close" onclick="cerrarModalProductos()">
                &times;
            </button>
        </div>

        <div class="modal-productos-search">
            <input 
                type="text" 
                id="buscarProductoInput" 
                placeholder="Buscar por código, descripción o ubicación..."
                onkeyup="filtrarProductosModal()"
            >
        </div>

        <div class="modal-productos-table-wrap">
            <table class="modal-productos-table">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th>Costo</th>
                        <th>Ubicación</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody id="productosModalBody">
                    <?php foreach ($productos as $producto): ?>
                        <tr 
                            data-busqueda="<?= e(strtolower($producto['codigo'] . ' ' . $producto['descripcion'] . ' ' . $producto['ubicacion'])) ?>"
                        >
                            <td><?= e($producto['codigo']) ?></td>
                            <td><?= e($producto['descripcion']) ?></td>
                            <td>$<?= number_format((float)$producto['precio_compra'], 2) ?></td>
                            <td><?= e($producto['ubicacion']) ?></td>
                            <td>
                                <button 
                                    type="button" 
                                    class="btn-seleccionar-producto"
                                    onclick="seleccionarProductoModal('<?= (int)$producto['id'] ?>')"
                                >
                                    Seleccionar
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($productos)): ?>
                        <tr>
                            <td colspan="5" class="empty-table">No hay productos disponibles.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const productoSelect = document.getElementById('producto_select');
const cantidadInput = document.getElementById('cantidad_input');
const descripcionInput = document.getElementById('descripcion_input');
const costoInput = document.getElementById('costo_input');
const loteInput = document.getElementById('lote_input');
const caducidadInput = document.getElementById('caducidad_input');
const ubicacionInput = document.getElementById('ubicacion_input');
const detalleBody = document.getElementById('detalleBody');
const totalEntrada = document.getElementById('totalEntrada');

const modalProductos = document.getElementById('modalProductos');
const buscarProductoInput = document.getElementById('buscarProductoInput');

productoSelect.addEventListener('change', function () {
    cargarDatosProductoSeleccionado();
});

function cargarDatosProductoSeleccionado() {
    const option = productoSelect.options[productoSelect.selectedIndex];

    if (!option || !productoSelect.value) {
        descripcionInput.value = '';
        costoInput.value = '0.00';
        ubicacionInput.value = '';
        return;
    }

    descripcionInput.value = option.dataset.descripcion || '';
    costoInput.value = option.dataset.costo || '0.00';
    ubicacionInput.value = option.dataset.ubicacion || '';
}

function abrirModalProductos() {
    modalProductos.classList.add('active');
    buscarProductoInput.value = '';
    filtrarProductosModal();

    setTimeout(() => {
        buscarProductoInput.focus();
    }, 100);
}

function cerrarModalProductos() {
    modalProductos.classList.remove('active');
}

function filtrarProductosModal() {
    const texto = buscarProductoInput.value.toLowerCase().trim();
    const filas = document.querySelectorAll('#productosModalBody tr');

    filas.forEach(fila => {
        const busqueda = fila.dataset.busqueda || '';
        fila.style.display = busqueda.includes(texto) ? '' : 'none';
    });
}

function seleccionarProductoModal(productoId) {
    productoSelect.value = productoId;
    cargarDatosProductoSeleccionado();
    cerrarModalProductos();
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        cerrarModalProductos();
    }
});

modalProductos.addEventListener('click', function (e) {
    if (e.target === modalProductos) {
        cerrarModalProductos();
    }
});

function agregarProductoDetalle() {
    const option = productoSelect.options[productoSelect.selectedIndex];

    if (!productoSelect.value) {
        alert('Selecciona un producto.');
        return;
    }

    const productoId = productoSelect.value;
    const codigo = option.dataset.codigo || '';
    const descripcion = option.dataset.descripcion || '';
    const cantidad = parseInt(cantidadInput.value || '0', 10);
    const costo = parseFloat(costoInput.value || '0');
    const lote = loteInput.value.trim();
    const caducidad = caducidadInput.value;
    const ubicacion = ubicacionInput.value.trim();

    if (cantidad <= 0) {
        alert('La cantidad debe ser mayor a 0.');
        return;
    }

    if (costo < 0) {
        alert('El costo unitario no es válido.');
        return;
    }

    const filaVacia = document.getElementById('filaVaciaDetalle');
    if (filaVacia) {
        filaVacia.remove();
    }

    const importe = cantidad * costo;
    const tr = document.createElement('tr');

    tr.innerHTML = `
        <td>
            ${cantidad}
            <input type="hidden" name="cantidad[]" value="${cantidad}">
            <input type="hidden" name="producto_id[]" value="${productoId}">
            <input type="hidden" name="costo_unitario[]" value="${costo.toFixed(2)}">
            <input type="hidden" name="numero_lote[]" value="${escapeHtml(lote)}">
            <input type="hidden" name="fecha_caducidad[]" value="${caducidad}">
            <input type="hidden" name="ubicacion[]" value="${escapeHtml(ubicacion)}">
        </td>
        <td>${codigo}</td>
        <td>${descripcion}</td>
        <td>$${costo.toFixed(2)}</td>
        <td>${escapeHtml(lote)}</td>
        <td>${caducidad || ''}</td>
        <td>${escapeHtml(ubicacion)}</td>
        <td class="importe-fila" data-importe="${importe}">$${importe.toFixed(2)}</td>
        <td>
            <button type="button" class="btn-delete" onclick="eliminarFilaEntrada(this)">Eliminar</button>
        </td>
    `;

    detalleBody.appendChild(tr);
    actualizarTotalEntrada();
    limpiarCapturaProducto();
    guardarBorradorEntrada();
}

function eliminarFilaEntrada(btn) {
    btn.closest('tr').remove();

    if (detalleBody.children.length === 0) {
        detalleBody.innerHTML = `
            <tr id="filaVaciaDetalle">
                <td colspan="9" class="empty-table">No has agregado productos.</td>
            </tr>
        `;
    }

    actualizarTotalEntrada();
    guardarBorradorEntrada();
}

function actualizarTotalEntrada() {
    let total = 0;

    document.querySelectorAll('.importe-fila').forEach(td => {
        total += parseFloat(td.dataset.importe || '0');
    });

    totalEntrada.textContent = '$' + total.toFixed(2);
}

function limpiarCapturaProducto() {
    productoSelect.value = '';
    cantidadInput.value = '1';
    descripcionInput.value = '';
    costoInput.value = '0.00';
    loteInput.value = '';
    caducidadInput.value = '';
    ubicacionInput.value = '';
}

document.getElementById('formEntrada').addEventListener('submit', function (e) {
    if (document.querySelectorAll('input[name="producto_id[]"]').length === 0) {
        e.preventDefault();
        alert('Debes agregar al menos un producto.');
    } else {
        localStorage.removeItem('borradorEntrada');
    }
});

function guardarBorradorEntrada() {
    const datos = {
        folio: document.querySelector('[name="folio"]')?.value || '',
        fecha: document.querySelector('[name="fecha"]')?.value || '',
        folio_anterior: document.querySelector('[name="folio_anterior"]')?.value || '',
        tipo_entrada: document.querySelector('[name="tipo_entrada"]')?.value || '',
        almacen_id: document.querySelector('[name="almacen_id"]')?.value || '',
        proveedor_id: document.querySelector('[name="proveedor_id"]')?.value || '',
        referencia: document.querySelector('[name="referencia"]')?.value || '',
        observaciones: document.querySelector('[name="observaciones"]')?.value || ''
    };

    localStorage.setItem('borradorEntrada', JSON.stringify(datos));
}

function cargarBorradorEntrada() {
    const guardado = localStorage.getItem('borradorEntrada');
    if (!guardado) return;

    const datos = JSON.parse(guardado);

    if (datos.folio_anterior) document.querySelector('[name="folio_anterior"]').value = datos.folio_anterior;
    if (datos.tipo_entrada) document.querySelector('[name="tipo_entrada"]').value = datos.tipo_entrada;
    if (datos.almacen_id) document.querySelector('[name="almacen_id"]').value = datos.almacen_id;
    if (datos.proveedor_id) document.querySelector('[name="proveedor_id"]').value = datos.proveedor_id;
    if (datos.referencia) document.querySelector('[name="referencia"]').value = datos.referencia;
    if (datos.observaciones) document.querySelector('[name="observaciones"]').value = datos.observaciones;
}

document.addEventListener('DOMContentLoaded', cargarBorradorEntrada);

document.querySelectorAll('input, select, textarea').forEach(campo => {
    campo.addEventListener('change', guardarBorradorEntrada);
    campo.addEventListener('keyup', guardarBorradorEntrada);
});

function escapeHtml(text) {
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}
</script>

<?php
if (file_exists(__DIR__ . '/../app/views/layouts/footer.php')) {
    include __DIR__ . '/../app/views/layouts/footer.php';
}
?>