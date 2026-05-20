<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/SalidaController.php';

requireLogin();

$user = currentUser();
$controller = new SalidaController();

$message = '';
$messageType = 'danger';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $controller->guardar($_POST, (int)$user['id']);

    if ($result['success']) {
        $movimientoId = (int)($result['movimiento_id'] ?? 0);

        if ($movimientoId > 0) {
            header('Location: imprimir_salida.php?id=' . $movimientoId . '&preview=1');
            exit;
        }

        $message = 'La salida se guardó, pero no se pudo abrir la vista previa.';
        $messageType = 'danger';
    } else {
        $message = $result['message'];
        $messageType = 'danger';
    }
}

$almacenes = $controller->almacenes();
$productos = $controller->productos();
$tiposSalida = $controller->tiposSalida();

$almacenSesion = (int)($user['almacen_id'] ?? 0);
$rolUsuario = strtoupper(trim($user['rol'] ?? ''));

$folio = $controller->generarFolio($almacenSesion);
$folioAnterior = $controller->ultimoFolioSalida($almacenSesion);

date_default_timezone_set('America/Mexico_City');
$fechaActual = date('Y-m-d\TH:i');

/*
    IMPORTANTE:
    Este archivo ya queda preparado para recibir productos con varias ubicaciones.

    Para que funcione al 100%, en el siguiente archivo vamos a modificar:
    app/controllers/SalidaController.php

    La idea es que cada producto pueda traer un arreglo así:
    $producto['ubicaciones'] = [
        ['ubicacion' => 'R1N1Z01', 'existencia_actual' => 500],
        ['ubicacion' => 'R4N1Z05', 'existencia_actual' => 300],
    ];

    Mientras todavía no exista ese arreglo, este archivo usa la ubicación normal actual.
*/

$ubicacionesGenerales = [];

foreach ($productos as $producto) {
    $ubicacionProducto = trim((string)($producto['ubicacion'] ?? ''));

    if ($ubicacionProducto !== '') {
        $ubicacionesGenerales[$ubicacionProducto] = $ubicacionProducto;
    }

    if (!empty($producto['ubicaciones']) && is_array($producto['ubicaciones'])) {
        foreach ($producto['ubicaciones'] as $ubicacionItem) {
            $ubicacionMultiple = trim((string)($ubicacionItem['ubicacion'] ?? ''));

            if ($ubicacionMultiple !== '') {
                $ubicacionesGenerales[$ubicacionMultiple] = $ubicacionMultiple;
            }
        }
    }
}

ksort($ubicacionesGenerales);

$moduleCss = 'salidas';
include __DIR__ . '/../app/views/layouts/header.php';
?>

<div class="module-header">
    <div>
        <h2>Salidas de Almacén</h2>
        <p>Registro de salidas con vista previa e impresión.</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= e($messageType) ?>">
        <?= e($message) ?>
    </div>
<?php endif; ?>

<form method="POST" action="" id="formSalida">
    <div class="salida-encabezado-card">
        <div class="salida-encabezado-grid">

            <div class="salida-field">
                <label>Folio</label>
                <input type="text" name="folio" value="<?= e($folio) ?>" readonly>
            </div>

            <div class="salida-field">
                <label>Fecha</label>
                <input type="datetime-local" name="fecha" id="fecha_salida" required>
            </div>

            <div class="salida-field folio-anterior-field">
                <label>Folio anterior</label>
                <input 
                    type="text" 
                    value="<?= e($folioAnterior ?: 'Sin salidas anteriores') ?>" 
                    readonly
                    class="input-readonly"
                >
            </div>

            <div class="salida-field tipo-field">
                <label>Tipo de salida *</label>
                <select name="tipo_salida" required>
                    <option value="">Seleccione el tipo de salida</option>
                    <?php foreach ($tiposSalida as $tipo): ?>
                        <option value="<?= e($tipo['clave'] . ' - ' . $tipo['descripcion']) ?>">
                            <?= e($tipo['clave']) ?> <?= e($tipo['descripcion']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="salida-field">
                <label>Tipo de documento *</label>
                <select name="tipo_operacion" id="tipo_operacion" required>
                    <option value="">Seleccione...</option>
                    <option value="TICKET">Ticket</option>
                    <option value="RESURTIDO">Resurtido</option>
                    <option value="AJUSTE">Ajuste</option>
                    <option value="TRASPASO">Traspaso</option>
                </select>
            </div>

            <div class="salida-field" id="folioOperacionBox" style="display:none;">
                <label id="folioOperacionLabel">Folio</label>
                <input 
                    type="text" 
                    name="folio_operacion" 
                    id="folio_operacion" 
                    placeholder="Ingrese folio"
                >
            </div>

            <div class="salida-field almacen-field">
                <label>Almacén *</label>
                <select 
                    name="almacen_id" 
                    required
                    <?= $rolUsuario !== 'ADMINISTRADOR' ? 'disabled' : '' ?>
                >
                    <option value="">Seleccione un almacén</option>

                    <?php foreach ($almacenes as $almacen): ?>
                        <option 
                            value="<?= (int)$almacen['id'] ?>"
                            <?= (int)$almacen['id'] === $almacenSesion ? 'selected' : '' ?>
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

            <div class="salida-field observaciones-field">
                <label>Observaciones</label>
                <textarea name="observaciones" rows="4" placeholder="Escribe observaciones de la salida."></textarea>
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
                        <?php
                            $ubicacionesProducto = [];

                            if (!empty($producto['ubicaciones']) && is_array($producto['ubicaciones'])) {
                                foreach ($producto['ubicaciones'] as $ubicacionItem) {
                                    $ubicacionTmp = trim((string)($ubicacionItem['ubicacion'] ?? ''));
                                    $existenciaTmp = (int)($ubicacionItem['existencia_actual'] ?? $ubicacionItem['existencia'] ?? 0);

                                    if ($ubicacionTmp !== '') {
                                        $ubicacionesProducto[] = [
                                            'ubicacion' => $ubicacionTmp,
                                            'existencia_actual' => $existenciaTmp,
                                        ];
                                    }
                                }
                            }

                            if (empty($ubicacionesProducto)) {
                                $ubicacionNormal = trim((string)($producto['ubicacion'] ?? ''));
                                $existenciaNormal = (int)($producto['existencia_actual'] ?? 0);

                                if ($ubicacionNormal !== '') {
                                    $ubicacionesProducto[] = [
                                        'ubicacion' => $ubicacionNormal,
                                        'existencia_actual' => $existenciaNormal,
                                    ];
                                }
                            }

                            $ubicacionesJson = htmlspecialchars(
                                json_encode($ubicacionesProducto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                ENT_QUOTES,
                                'UTF-8'
                            );
                        ?>
                        <option
                            value="<?= (int)$producto['id'] ?>"
                            data-codigo="<?= e($producto['codigo']) ?>"
                            data-descripcion="<?= e($producto['descripcion']) ?>"
                            data-costo="<?= e((string)$producto['precio_compra']) ?>"
                            data-precio="<?= e((string)$producto['precio_compra']) ?>"
                            data-existencia="<?= e((string)$producto['existencia_actual']) ?>"
                            data-unidad="<?= e($producto['unidad_medida']) ?>"
                            data-ubicacion="<?= e($producto['ubicacion'] ?? '') ?>"
                            data-ubicaciones="<?= $ubicacionesJson ?>"
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
                <label>Unidad</label>
                <input type="text" id="unidad_input" readonly>
            </div>

            <div class="salida-field mini-field">
                <label>Existencia Actual</label>
                <input type="text" id="existencia_input" value="0" readonly>
            </div>

            <div class="salida-field mini-field">
                <label>Precio Unitario</label>
                <input type="number" id="precio_input" step="0.01" min="0" value="0.00">
            </div>

            <div class="salida-field">
                <label>Ubicación</label>
                <input 
                    type="text" 
                    id="ubicacion_input" 
                    list="lista_ubicaciones_salida" 
                    placeholder="Seleccione o escriba ubicación"
                    autocomplete="off"
                >
                <datalist id="lista_ubicaciones_salida">
                    <?php foreach ($ubicacionesGenerales as $ubicacionGeneral): ?>
                        <option value="<?= e($ubicacionGeneral) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <small style="display:block; margin-top:4px; color:#64748b; font-size:11px;">
                    Puedes seleccionar una ubicación existente o escribir una nueva.
                </small>
            </div>

            <div class="salida-actions">
                <button type="button" class="btn-primary-action" onclick="agregarProductoSalida()">Agregar</button>
            </div>
        </div>
    </div>

    <div class="erp-table-card">
        <div class="table-topbar">
            <h3>Detalle de la salida</h3>
        </div>

        <div class="table-responsive">
            <table class="erp-table tabla-salida">
                <thead>
                    <tr>
                        <th>Cantidad</th>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th>Unidad</th>
                        <th>Existencia</th>
                        <th>Precio U.</th>
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
                        <th id="totalSalida">$0.00</th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="form-actions" style="margin-top:20px;">
            <button type="submit" class="btn-primary-action">Guardar y ver vista previa</button>
            <a href="salidas.php" class="btn-secondary-action">Nueva captura</a>
        </div>
    </div>
</form>

<div class="modal-productos-overlay" id="modalProductos">
    <div class="modal-productos-card">
        <div class="modal-productos-header">
            <div>
                <h3>Buscar producto</h3>
                <p>Selecciona el producto que deseas agregar a la salida.</p>
            </div>

            <button type="button" class="modal-productos-close" onclick="cerrarModalProductos()">
                &times;
            </button>
        </div>

        <div class="modal-productos-search">
            <input 
                type="text" 
                id="buscarProductoInput" 
                placeholder="Buscar por código, descripción, unidad o ubicación..."
                onkeyup="filtrarProductosModal()"
            >
        </div>

        <div class="modal-productos-table-wrap">
            <table class="modal-productos-table">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th>Unidad</th>
                        <th>Existencia</th>
                        <th>Precio Unitario</th>
                        <th>Ubicación sugerida</th>
                        <th>Ubicaciones</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody id="productosModalBody">
                    <?php foreach ($productos as $producto): ?>
                        <?php
                            $ubicacionesProductoModal = [];

                            if (!empty($producto['ubicaciones']) && is_array($producto['ubicaciones'])) {
                                foreach ($producto['ubicaciones'] as $ubicacionItem) {
                                    $ubicacionTmp = trim((string)($ubicacionItem['ubicacion'] ?? ''));
                                    $existenciaTmp = (int)($ubicacionItem['existencia_actual'] ?? $ubicacionItem['existencia'] ?? 0);

                                    if ($ubicacionTmp !== '') {
                                        $ubicacionesProductoModal[] = [
                                            'ubicacion' => $ubicacionTmp,
                                            'existencia_actual' => $existenciaTmp,
                                        ];
                                    }
                                }
                            }

                            if (empty($ubicacionesProductoModal)) {
                                $ubicacionNormal = trim((string)($producto['ubicacion'] ?? ''));
                                $existenciaNormal = (int)($producto['existencia_actual'] ?? 0);

                                if ($ubicacionNormal !== '') {
                                    $ubicacionesProductoModal[] = [
                                        'ubicacion' => $ubicacionNormal,
                                        'existencia_actual' => $existenciaNormal,
                                    ];
                                }
                            }

                            usort($ubicacionesProductoModal, function ($a, $b) {
                                return ((int)$a['existencia_actual']) <=> ((int)$b['existencia_actual']);
                            });

                            $ubicacionSugerida = $ubicacionesProductoModal[0]['ubicacion'] ?? ($producto['ubicacion'] ?? '');

                            $textoUbicaciones = [];
                            foreach ($ubicacionesProductoModal as $ubicacionItem) {
                                $textoUbicaciones[] = $ubicacionItem['ubicacion'] . ' (' . (int)$ubicacionItem['existencia_actual'] . ')';
                            }

                            $textoUbicacionesPlano = implode(', ', $textoUbicaciones);
                        ?>
                        <tr 
                            data-busqueda="<?= e(strtolower($producto['codigo'] . ' ' . $producto['descripcion'] . ' ' . $producto['unidad_medida'] . ' ' . $ubicacionSugerida . ' ' . $textoUbicacionesPlano)) ?>"
                        >
                            <td><?= e($producto['codigo']) ?></td>
                            <td><?= e($producto['descripcion']) ?></td>
                            <td><?= e($producto['unidad_medida']) ?></td>
                            <td><?= e((string)$producto['existencia_actual']) ?></td>
                            <td>$<?= number_format((float)$producto['precio_compra'], 2) ?></td>
                            <td><?= e($ubicacionSugerida) ?></td>
                            <td><?= e($textoUbicacionesPlano ?: ($producto['ubicacion'] ?? '')) ?></td>
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
                            <td colspan="8" class="empty-table">No hay productos disponibles.</td>
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
const unidadInput = document.getElementById('unidad_input');
const existenciaInput = document.getElementById('existencia_input');
const precioInput = document.getElementById('precio_input');
const ubicacionInput = document.getElementById('ubicacion_input');
const detalleBody = document.getElementById('detalleBody');
const totalSalida = document.getElementById('totalSalida');

const tipoOperacionSelect = document.getElementById('tipo_operacion');
const folioOperacionBox = document.getElementById('folioOperacionBox');
const folioOperacionInput = document.getElementById('folio_operacion');
const folioOperacionLabel = document.getElementById('folioOperacionLabel');

const modalProductos = document.getElementById('modalProductos');
const buscarProductoInput = document.getElementById('buscarProductoInput');

function escaparHtml(valor) {
    return String(valor ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function cargarCatalogoUbicacionesSalida() {
    const datalist = document.getElementById('lista_ubicaciones_salida');
    if (!datalist) return;

    const ubicaciones = [];

    function add(rack, nivel, zona) {
        const z = String(zona).padStart(2, '0');
        ubicaciones.push(`R${rack}N${nivel}Z${z}`);
    }

    for (let n = 1; n <= 3; n++) for (let z = 1; z <= 22; z++) add(1, n, z);
    for (let n = 1; n <= 3; n++) for (let z = 1; z <= 20; z++) add(2, n, z);
    for (let n = 1; n <= 3; n++) for (let z = 1; z <= 20; z++) add(3, n, z);
    for (let n = 1; n <= 2; n++) for (let z = 1; z <= 16; z++) add(4, n, z);
    for (let z = 10; z <= 16; z++) add(4, 3, z);
    for (let n = 1; n <= 2; n++) for (let z = 1; z <= 15; z++) add(5, n, z);
    for (let z = 10; z <= 15; z++) add(5, 3, z);
    for (let n = 1; n <= 3; n++) for (let z = 1; z <= 22; z++) add(6, n, z);

    ubicaciones.push('R7N1Z01 - PASILLO 1');
    ubicaciones.push('R8N1Z01 - PASILLO 2');
    ubicaciones.push('R9N1Z01 - PASILLO 3');
    ubicaciones.push('BODEGA PEDYALITE');

    datalist.innerHTML = '';

    ubicaciones.forEach(ubicacion => {
        const option = document.createElement('option');
        option.value = ubicacion;
        datalist.appendChild(option);
    });
}

function obtenerOptionProductoActual() {
    return productoSelect.options[productoSelect.selectedIndex];
}

function obtenerUbicacionesProducto(option) {
    if (!option) return [];

    let ubicaciones = [];

    try {
        ubicaciones = JSON.parse(option.dataset.ubicaciones || '[]');
    } catch (error) {
        ubicaciones = [];
    }

    if (!Array.isArray(ubicaciones)) {
        ubicaciones = [];
    }

    return ubicaciones
        .map(item => ({
            ubicacion: String(item.ubicacion || '').trim().toUpperCase(),
            existencia_actual: parseInt(item.existencia_actual || item.existencia || '0', 10)
        }))
        .filter(item => item.ubicacion !== '');
}

function cargarUbicacionesDelProducto(option) {
    const datalist = document.getElementById('lista_ubicaciones_salida');

    if (!datalist || !option) return;

    datalist.innerHTML = '';

    const ubicaciones = obtenerUbicacionesProducto(option);

    ubicaciones.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item.ubicacion;
        datalist.appendChild(opt);
    });

    if (
        ubicaciones.length === 0 ||
        (
            ubicaciones.length === 1 &&
            ubicaciones[0].ubicacion === 'SIN UBICACION'
        )
    ) {
        cargarCatalogoUbicacionesSalida();
    }
}

function obtenerUbicacionConMenorExistencia(option) {
    const ubicaciones = obtenerUbicacionesProducto(option)
        .filter(item => item.existencia_actual > 0)
        .sort((a, b) => a.existencia_actual - b.existencia_actual);

    if (ubicaciones.length === 0) {
        return {
            ubicacion: 'SIN UBICACION',
            existencia_actual: 0
        };
    }

    return ubicaciones[0];
}

function actualizarExistenciaPorUbicacion() {
    const option = obtenerOptionProductoActual();

    if (!option || !productoSelect.value) return;

    existenciaInput.value = option.dataset.existencia || '0';
}

function limpiarSinUbicacionAlClick() {
    const valor = ubicacionInput.value.trim().toUpperCase();

    if (valor === 'SIN UBICACION') {
        ubicacionInput.value = '';
    }
}

ubicacionInput.addEventListener('focus', limpiarSinUbicacionAlClick);
ubicacionInput.addEventListener('click', limpiarSinUbicacionAlClick);
ubicacionInput.addEventListener('input', actualizarExistenciaPorUbicacion);
ubicacionInput.addEventListener('change', actualizarExistenciaPorUbicacion);

function controlarFolioOperacion() {
    const valor = tipoOperacionSelect.value;

    if (valor === 'TICKET') {
        folioOperacionBox.style.display = 'flex';
        folioOperacionLabel.textContent = 'Folio de Ticket';
        folioOperacionInput.placeholder = 'Ingrese folio del ticket';
        folioOperacionInput.required = true;
    } else if (valor === 'RESURTIDO') {
        folioOperacionBox.style.display = 'flex';
        folioOperacionLabel.textContent = 'Folio de Resurtido';
        folioOperacionInput.placeholder = 'Ingrese folio del resurtido';
        folioOperacionInput.required = true;
    } else {
        folioOperacionBox.style.display = 'none';
        folioOperacionInput.value = '';
        folioOperacionInput.required = false;
    }

    guardarBorradorSalida();
}

tipoOperacionSelect.addEventListener('change', controlarFolioOperacion);
productoSelect.addEventListener('change', cargarDatosProductoSeleccionado);

function cargarDatosProductoSeleccionado() {
    const option = obtenerOptionProductoActual();

    if (!option || !productoSelect.value) {
        descripcionInput.value = '';
        unidadInput.value = '';
        existenciaInput.value = '0';
        precioInput.value = '0.00';
        ubicacionInput.value = '';
        cargarCatalogoUbicacionesSalida();
        return;
    }

    const ubicacionMenor = obtenerUbicacionConMenorExistencia(option);

    descripcionInput.value = option.dataset.descripcion || '';
    unidadInput.value = option.dataset.unidad || '';
    precioInput.value = option.dataset.precio || '0.00';
    ubicacionInput.value = ubicacionMenor.ubicacion || 'SIN UBICACION';
    existenciaInput.value = option.dataset.existencia || '0';

    cargarUbicacionesDelProducto(option);
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

function agregarProductoSalida() {
    const option = obtenerOptionProductoActual();

    if (!productoSelect.value) {
        alert('Selecciona un producto.');
        return;
    }

    actualizarExistenciaPorUbicacion();

    const productoId = productoSelect.value;
    const codigo = option.dataset.codigo || '';
    const descripcion = option.dataset.descripcion || '';
    const unidad = option.dataset.unidad || '';
    const existenciaTotal = parseInt(option.dataset.existencia || '0', 10);
    const cantidad = parseInt(cantidadInput.value || '0', 10);
    const costo = parseFloat(option.dataset.costo || '0');
    const precio = parseFloat(precioInput.value || '0');
    const ubicacion = ubicacionInput.value.trim().toUpperCase();

    if (cantidad <= 0) {
        alert('La cantidad debe ser mayor a 0.');
        return;
    }

    if (!ubicacion) {
        alert('Selecciona o escribe una ubicación.');
        ubicacionInput.focus();
        return;
    }

    if (existenciaTotal <= 0) {
        alert('Este producto no tiene existencia disponible.');
        return;
    }

    if (cantidad > existenciaTotal) {
        alert('La cantidad supera la existencia total del producto.');
        return;
    }

    const filaVacia = document.getElementById('filaVaciaDetalle');

    if (filaVacia) {
        filaVacia.remove();
    }

    const importe = cantidad * precio;
    const tr = document.createElement('tr');

    tr.innerHTML = `
        <td>
            <input
                type="number"
                min="1"
                max="${escaparHtml(existenciaTotal)}"
                value="${escaparHtml(cantidad)}"
                class="input-cantidad-detalle"
                style="width:70px;"
                oninput="actualizarCantidadFila(this)"
            >

            <input type="hidden" name="producto_id[]" value="${escaparHtml(productoId)}">
            <input type="hidden" name="cantidad[]" value="${escaparHtml(cantidad)}">
            <input type="hidden" name="costo_unitario[]" value="${escaparHtml(costo)}">
            <input type="hidden" name="precio_unitario[]" value="${escaparHtml(precio)}">
            <input type="hidden" name="ubicacion[]" value="${escaparHtml(ubicacion)}">
        </td>
        <td>${escaparHtml(codigo)}</td>
        <td>${escaparHtml(descripcion)}</td>
        <td>${escaparHtml(unidad)}</td>
        <td>${escaparHtml(existenciaTotal)}</td>
        <td>$${precio.toFixed(2)}</td>
        <td>${escaparHtml(ubicacion)}</td>
        <td class="importe-fila" data-importe="${importe}">$${importe.toFixed(2)}</td>
        <td>
            <button type="button" class="btn-delete" onclick="eliminarFilaSalida(this)">
                Eliminar
            </button>
        </td>
    `;

    detalleBody.appendChild(tr);
    actualizarTotalSalida();

    productoSelect.value = '';
    cantidadInput.value = '1';
    descripcionInput.value = '';
    unidadInput.value = '';
    existenciaInput.value = '0';
    precioInput.value = '0.00';
    ubicacionInput.value = '';
    cargarCatalogoUbicacionesSalida();

    guardarBorradorSalida();
}

function actualizarCantidadFila(input) {
    const tr = input.closest('tr');

    let cantidadTexto = input.value.replace(/[^0-9]/g, '');

    input.value = cantidadTexto;

    if (cantidadTexto === '') {
        return;
    }

    const cantidad = parseInt(cantidadTexto, 10);

    const existencia = parseInt(
        tr.children[4].textContent || '0',
        10
    );

    const precioTexto = tr.children[5]
        .textContent
        .replace('$', '')
        .trim();

    const precio = parseFloat(precioTexto || '0');

    if (cantidad <= 0) {
        return;
    }

    if (cantidad > existencia) {
        alert('La cantidad no puede superar la existencia disponible.');

        input.value = existencia;

        return;
    }

    const nuevoImporte = cantidad * precio;

    const inputHiddenCantidad = tr.querySelector(
        'input[name="cantidad[]"]'
    );

    inputHiddenCantidad.value = cantidad;

    const tdImporte = tr.querySelector('.importe-fila');

    tdImporte.dataset.importe = nuevoImporte;

    tdImporte.textContent =
        '$' + nuevoImporte.toFixed(2);

    actualizarTotalSalida();

    guardarBorradorSalida();
}
function eliminarFilaSalida(btn) {
    btn.closest('tr').remove();

    if (detalleBody.children.length === 0) {
        detalleBody.innerHTML = `
            <tr id="filaVaciaDetalle">
                <td colspan="9" class="empty-table">
                    No has agregado productos.
                </td>
            </tr>
        `;
    }

    actualizarTotalSalida();
    guardarBorradorSalida();
}

function actualizarTotalSalida() {
    let total = 0;

    document.querySelectorAll('.importe-fila').forEach(td => {
        total += parseFloat(td.dataset.importe || '0');
    });

    totalSalida.textContent = '$' + total.toFixed(2);
}

document.getElementById('formSalida').addEventListener('submit', function (e) {
    if (document.querySelectorAll('input[name="producto_id[]"]').length === 0) {
        e.preventDefault();
        alert('Debes agregar al menos un producto.');
    } else {
        localStorage.removeItem('borradorSalida');
    }
});

function guardarBorradorSalida() {
    const datos = {
        folio: document.querySelector('[name="folio"]')?.value || '',
        fecha: document.querySelector('[name="fecha"]')?.value || '',
        tipo_salida: document.querySelector('[name="tipo_salida"]')?.value || '',
        tipo_operacion: document.querySelector('[name="tipo_operacion"]')?.value || '',
        folio_operacion: document.querySelector('[name="folio_operacion"]')?.value || '',
        almacen_id: document.querySelector('[name="almacen_id"]')?.value || '',
        observaciones: document.querySelector('[name="observaciones"]')?.value || ''
    };

    localStorage.setItem('borradorSalida', JSON.stringify(datos));
}

function cargarBorradorSalida() {
    const guardado = localStorage.getItem('borradorSalida');

    if (!guardado) return;

    const datos = JSON.parse(guardado);

    if (datos.tipo_salida) document.querySelector('[name="tipo_salida"]').value = datos.tipo_salida;
    if (datos.tipo_operacion) document.querySelector('[name="tipo_operacion"]').value = datos.tipo_operacion;
    if (datos.folio_operacion) document.querySelector('[name="folio_operacion"]').value = datos.folio_operacion;
    if (datos.almacen_id) document.querySelector('[name="almacen_id"]').value = datos.almacen_id;
    if (datos.observaciones) document.querySelector('[name="observaciones"]').value = datos.observaciones;

    controlarFolioOperacion();
}

document.addEventListener('DOMContentLoaded', function () {
    cargarCatalogoUbicacionesSalida();
    cargarBorradorSalida();
});

document.querySelectorAll('input, select, textarea').forEach(campo => {
    campo.addEventListener('change', guardarBorradorSalida);
    campo.addEventListener('keyup', guardarBorradorSalida);
});

function ponerFechaActualSalida() {
    const inputFecha = document.getElementById('fecha_salida');
    if (!inputFecha) return;

    const ahora = new Date();

    const year = ahora.getFullYear();
    const month = String(ahora.getMonth() + 1).padStart(2, '0');
    const day = String(ahora.getDate()).padStart(2, '0');
    const hours = String(ahora.getHours()).padStart(2, '0');
    const minutes = String(ahora.getMinutes()).padStart(2, '0');

    inputFecha.value = `${year}-${month}-${day}T${hours}:${minutes}`;
}

document.addEventListener('DOMContentLoaded', function () {
    localStorage.removeItem('borradorSalida');
    ponerFechaActualSalida();
});
</script>
<?php
if (file_exists(__DIR__ . '/../app/views/layouts/footer.php')) {
    include __DIR__ . '/../app/views/layouts/footer.php';
}
?>
