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

    if ($result['success']) {
        $movimientoId = (int)($result['movimiento_id'] ?? 0);

        if ($movimientoId > 0) {
            header('Location: imprimir_entrada.php?id=' . $movimientoId . '&preview=1');
            exit;
        }

        $message = 'La entrada se guardó, pero no se pudo abrir la vista previa.';
        $messageType = 'danger';
    } else {
        $message = $result['message'];
        $messageType = 'danger';
    }
}

$almacenes = $controller->almacenes();
$productos = $controller->productos();
$tiposEntrada = $controller->tiposEntrada();

$almacenSesion = (int)($user['almacen_id'] ?? 0);
$rolUsuario = strtoupper(trim($user['rol'] ?? ''));

$folio = $controller->generarFolio($almacenSesion);
$folioAnterior = method_exists($controller, 'ultimoFolioEntrada')
    ? $controller->ultimoFolioEntrada($almacenSesion)
    : '';

date_default_timezone_set('America/Mexico_City');

$ubicacionesGenerales = [];

foreach ($productos as $producto) {
    $ubicacionProducto = strtoupper(trim((string)($producto['ubicacion'] ?? '')));

    if ($ubicacionProducto !== '' && $ubicacionProducto !== 'SIN UBICACION') {
        $ubicacionesGenerales[$ubicacionProducto] = $ubicacionProducto;
    }

    if (!empty($producto['ubicaciones']) && is_array($producto['ubicaciones'])) {
        foreach ($producto['ubicaciones'] as $ubicacionItem) {
            $ubicacionMultiple = strtoupper(trim((string)($ubicacionItem['ubicacion'] ?? '')));

            if ($ubicacionMultiple !== '' && $ubicacionMultiple !== 'SIN UBICACION') {
                $ubicacionesGenerales[$ubicacionMultiple] = $ubicacionMultiple;
            }
        }
    }
}

ksort($ubicacionesGenerales);

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
    <input type="hidden" name="referencia" id="referencia_final">

    <div class="salida-encabezado-card">
        <div class="salida-encabezado-grid">

            <div class="salida-field">
                <label>Folio</label>
                <input type="text" name="folio" value="<?= e($folio) ?>" readonly>
            </div>

            <div class="salida-field">
                <label>Fecha</label>
                <input type="datetime-local" name="fecha" id="fecha_entrada" required>
            </div>

            <div class="salida-field folio-anterior-field">
                <label>Folio anterior</label>
                <input 
                    type="text" 
                    value="<?= e($folioAnterior ?: 'Sin entradas anteriores') ?>" 
                    readonly
                    class="input-readonly"
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

            <div class="salida-field">
                <label>Proveedor</label>
                <input
                    type="text"
                    name="proveedor_nombre"
                    placeholder="Ingrese el nombre del proveedor"
                >
            </div>

            <div class="salida-field">
                <label>Tipo de documento</label>
                <select name="tipo_documento" id="tipo_documento">
                    <option value="">Seleccione...</option>
                    <option value="FACTURA">Factura</option>
                    <option value="NOTA">Nota</option>
                    <option value="REMISION">Remisión</option>
                    <option value="TICKET">Ticket</option>
                    <option value="AJUSTE">Ajuste</option>
                    <option value="TRASPASO">TRASPASO</option>
                    <option value="OTRO">Otro</option>
                </select>
            </div>

            <div class="salida-field">
                <label>Folio del documento</label>
                <input 
                    type="text" 
                    name="folio_documento" 
                    id="folio_documento"
                    placeholder="Ingrese folio"
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
                        <?php
                            $ubicacionesProducto = [];

                            if (!empty($producto['ubicaciones']) && is_array($producto['ubicaciones'])) {
                                foreach ($producto['ubicaciones'] as $ubicacionItem) {
                                    $ubicacionTmp = strtoupper(trim((string)($ubicacionItem['ubicacion'] ?? '')));
                                    $existenciaTmp = (int)($ubicacionItem['existencia_actual'] ?? $ubicacionItem['existencia'] ?? 0);

                                    if ($ubicacionTmp !== '' && $ubicacionTmp !== 'SIN UBICACION') {
                                        $ubicacionesProducto[] = [
                                            'ubicacion' => $ubicacionTmp,
                                            'existencia_actual' => $existenciaTmp,
                                        ];
                                    }
                                }
                            }

                            if (empty($ubicacionesProducto)) {
                                $ubicacionNormal = strtoupper(trim((string)($producto['ubicacion'] ?? '')));

                                if ($ubicacionNormal !== '' && $ubicacionNormal !== 'SIN UBICACION') {
                                    $ubicacionesProducto[] = [
                                        'ubicacion' => $ubicacionNormal,
                                        'existencia_actual' => (int)($producto['existencia_actual'] ?? 0),
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
                <label>Precio Unitario</label>
                <input type="number" id="costo_input" step="0.01" min="0" value="0.00">
            </div>

            <div class="salida-field">
                <label>Número de lote</label>
                <input type="text" id="lote_input">
            </div>

            <div class="salida-field">
                <label>Ubicación *</label>
                <input
                    type="text"
                    id="ubicacion_input"
                    list="lista_ubicaciones_entrada"
                    autocomplete="off"
                    placeholder="Ejemplo: R1N1Z01"
                >

                <datalist id="lista_ubicaciones_entrada">
                    <?php foreach ($ubicacionesGenerales as $ubicacionGeneral): ?>
                        <option value="<?= e($ubicacionGeneral) ?>"></option>
                    <?php endforeach; ?>
                </datalist>

                <small style="display:block; margin-top:4px; color:#64748b; font-size:11px;">
                    La entrada asigna almacén, ubicación y existencia al producto.
                </small>
            </div>

            <div class="salida-actions">
                <button type="button" class="btn-primary-action" onclick="agregarProductoDetalle()">
                    Agregar
                </button>
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
                        <th>Precio U.</th>
                        <th>Lote</th>
                        <th>Ubicación</th>
                        <th>Importe</th>
                        <th>Acción</th>
                    </tr>
                </thead>

                <tbody id="detalleBody">
                    <tr id="filaVaciaDetalle">
                        <td colspan="8" class="empty-table">No has agregado productos.</td>
                    </tr>
                </tbody>

                <tfoot>
                    <tr>
                        <th colspan="6" style="text-align:right;">Total:</th>
                        <th id="totalEntrada">$0.00</th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="form-actions" style="margin-top:20px;">
            <button type="submit" class="btn-primary-action">Guardar y ver vista previa</button>
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
                                    $ubicacionTmp = strtoupper(trim((string)($ubicacionItem['ubicacion'] ?? '')));
                                    $existenciaTmp = (int)($ubicacionItem['existencia_actual'] ?? $ubicacionItem['existencia'] ?? 0);

                                    if ($ubicacionTmp !== '' && $ubicacionTmp !== 'SIN UBICACION') {
                                        $ubicacionesProductoModal[] = [
                                            'ubicacion' => $ubicacionTmp,
                                            'existencia_actual' => $existenciaTmp,
                                        ];
                                    }
                                }
                            }

                            if (empty($ubicacionesProductoModal)) {
                                $ubicacionNormal = strtoupper(trim((string)($producto['ubicacion'] ?? '')));

                                if ($ubicacionNormal !== '' && $ubicacionNormal !== 'SIN UBICACION') {
                                    $ubicacionesProductoModal[] = [
                                        'ubicacion' => $ubicacionNormal,
                                        'existencia_actual' => (int)($producto['existencia_actual'] ?? 0),
                                    ];
                                }
                            }

                            usort($ubicacionesProductoModal, function ($a, $b) {
                                return ((int)$a['existencia_actual']) <=> ((int)$b['existencia_actual']);
                            });

                            $ubicacionSugerida = $ubicacionesProductoModal[0]['ubicacion'] ?? '';

                            $textoUbicaciones = [];

                            foreach ($ubicacionesProductoModal as $ubicacionItem) {
                                $textoUbicaciones[] = $ubicacionItem['ubicacion'] . ' (' . (int)$ubicacionItem['existencia_actual'] . ')';
                            }

                            $textoUbicacionesPlano = implode(', ', $textoUbicaciones);
                        ?>

                        <tr 
                            data-busqueda="<?= e(strtolower($producto['codigo'] . ' ' . $producto['descripcion'] . ' ' . $ubicacionSugerida . ' ' . $textoUbicacionesPlano)) ?>"
                        >
                            <td><?= e($producto['codigo']) ?></td>
                            <td><?= e($producto['descripcion']) ?></td>
                            <td>$<?= number_format((float)$producto['precio_compra'], 2) ?></td>
                            <td><?= e($ubicacionSugerida ?: 'Sin ubicación sugerida') ?></td>
                            <td><?= e($textoUbicacionesPlano ?: 'Sin ubicaciones activas') ?></td>
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
                            <td colspan="6" class="empty-table">No hay productos disponibles.</td>
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
const ubicacionInput = document.getElementById('ubicacion_input');
const detalleBody = document.getElementById('detalleBody');
const totalEntrada = document.getElementById('totalEntrada');

const tipoDocumentoInput = document.getElementById('tipo_documento');
const folioDocumentoInput = document.getElementById('folio_documento');
const referenciaFinalInput = document.getElementById('referencia_final');

const modalProductos = document.getElementById('modalProductos');
const buscarProductoInput = document.getElementById('buscarProductoInput');

function escapeHtml(text) {
    return String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/'/g, '&#039;');
}

function limpiarUbicacion(valor) {
    valor = String(valor || '').trim().toUpperCase();
    valor = valor.replace('SIN UBICACIÓN', 'SIN UBICACION');
    return valor || 'SIN UBICACION';
}

function cargarCatalogoUbicacionesEntrada() {
    const datalist = document.getElementById('lista_ubicaciones_entrada');
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

    ubicaciones.push('R7N1Z01 - PASILLO 3');
    ubicaciones.push('R8N1Z01 - PASILLO 2');
    ubicaciones.push('R9N1Z01 - PASILLO 1');
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
            ubicacion: limpiarUbicacion(item.ubicacion || ''),
            existencia_actual: parseInt(item.existencia_actual || item.existencia || '0', 10)
        }))
        .filter(item => item.ubicacion !== 'SIN UBICACION');
}

function cargarUbicacionesDelProducto(option) {
    const datalist = document.getElementById('lista_ubicaciones_entrada');

    if (!datalist || !option) return;

    cargarCatalogoUbicacionesEntrada();

    const ubicaciones = obtenerUbicacionesProducto(option);

    ubicaciones.forEach(item => {
        if (item.ubicacion && item.ubicacion !== 'SIN UBICACION') {
            const existe = Array.from(datalist.options).some(
                opt => opt.value === item.ubicacion
            );

            if (!existe) {
                const opt = document.createElement('option');
                opt.value = item.ubicacion;
                datalist.appendChild(opt);
            }
        }
    });
}

function obtenerUbicacionSugerida(option) {
    const ubicaciones = obtenerUbicacionesProducto(option)
        .sort((a, b) => a.existencia_actual - b.existencia_actual);

    if (ubicaciones.length === 0) {
        return '';
    }

    return ubicaciones[0].ubicacion || '';
}

function limpiarSinUbicacionEntrada() {
    const valor = limpiarUbicacion(ubicacionInput.value);

    if (valor === 'SIN UBICACION') {
        ubicacionInput.value = '';
        cargarCatalogoUbicacionesEntrada();
    }
}

ubicacionInput.addEventListener('focus', limpiarSinUbicacionEntrada);
ubicacionInput.addEventListener('click', limpiarSinUbicacionEntrada);

productoSelect.addEventListener('change', cargarDatosProductoSeleccionado);

function cargarDatosProductoSeleccionado() {
    const option = obtenerOptionProductoActual();

    if (!option || !productoSelect.value) {
        descripcionInput.value = '';
        costoInput.value = '0.00';
        ubicacionInput.value = '';
        cargarCatalogoUbicacionesEntrada();
        return;
    }

    const ubicacionSugerida = obtenerUbicacionSugerida(option);

    descripcionInput.value = option.dataset.descripcion || '';
    costoInput.value = option.dataset.costo || '0.00';
    ubicacionInput.value = ubicacionSugerida || '';
    ubicacionInput.placeholder = 'Escribe nueva ubicación';

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

function agregarProductoDetalle() {
    const option = obtenerOptionProductoActual();

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
    const ubicacion = limpiarUbicacion(ubicacionInput.value);

    if (cantidad <= 0) {
        alert('La cantidad debe ser mayor a 0.');
        return;
    }

    if (costo < 0) {
        alert('El precio unitario no es válido.');
        return;
    }

    if (ubicacion === 'SIN UBICACION') {
        alert('Debes ingresar una ubicación válida.');
        ubicacionInput.focus();
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
            <input
                type="number"
                min="1"
                value="${escapeHtml(cantidad)}"
                class="input-cantidad-detalle"
                style="width:70px;"
                oninput="actualizarCantidadFilaEntrada(this)"
            >

            <input type="hidden" name="cantidad[]" value="${escapeHtml(cantidad)}">
            <input type="hidden" name="producto_id[]" value="${escapeHtml(productoId)}">
            <input type="hidden" name="costo_unitario[]" value="${escapeHtml(costo.toFixed(2))}">
            <input type="hidden" name="numero_lote[]" value="${escapeHtml(lote)}">
            <input type="hidden" name="fecha_caducidad[]" value="">
            <input type="hidden" name="ubicacion[]" value="${escapeHtml(ubicacion)}">
        </td>
        <td>${escapeHtml(codigo)}</td>
        <td>${escapeHtml(descripcion)}</td>
        <td>
            <input
                type="number"
                min="0"
                step="0.01"
                value="${escapeHtml(costo.toFixed(2))}"
                class="input-precio-detalle"
                style="width:85px;"
                oninput="actualizarPrecioFilaEntrada(this)"
            >
        </td>
        <td>${escapeHtml(lote)}</td>
        <td>${escapeHtml(ubicacion)}</td>
        <td class="importe-fila" data-importe="${importe}">$${importe.toFixed(2)}</td>
        <td>
            <button type="button" class="btn-delete" onclick="eliminarFilaEntrada(this)">
                Eliminar
            </button>
        </td>
    `;

    detalleBody.appendChild(tr);

    actualizarTotalEntrada();
    limpiarCapturaProducto();
    guardarBorradorEntrada();
}

function actualizarPrecioFilaEntrada(input) {
    const tr = input.closest('tr');

    let precio = parseFloat(input.value || '0');

    if (isNaN(precio) || precio < 0) {
        precio = 0;
    }

    const cantidadInputFila = tr.querySelector('.input-cantidad-detalle');
    const cantidad = parseInt(cantidadInputFila?.value || '0', 10);

    const inputHiddenPrecio = tr.querySelector('input[name="costo_unitario[]"]');

    if (inputHiddenPrecio) {
        inputHiddenPrecio.value = precio.toFixed(2);
    }

    const nuevoImporte = cantidad * precio;

    const tdImporte = tr.querySelector('.importe-fila');

    if (tdImporte) {
        tdImporte.dataset.importe = nuevoImporte;
        tdImporte.textContent = '$' + nuevoImporte.toFixed(2);
    }

    actualizarTotalEntrada();
    guardarBorradorEntrada();
}

function actualizarCantidadFilaEntrada(input) {
    const tr = input.closest('tr');

    let cantidadTexto = input.value.replace(/[^0-9]/g, '');
    input.value = cantidadTexto;

    if (cantidadTexto === '') return;

    const cantidad = parseInt(cantidadTexto, 10);

    if (cantidad <= 0) return;

    const precioInputFila = tr.querySelector('.input-precio-detalle');
    const precio = parseFloat(precioInputFila?.value || '0');

    const inputHiddenCantidad = tr.querySelector('input[name="cantidad[]"]');

    if (inputHiddenCantidad) {
        inputHiddenCantidad.value = cantidad;
    }

    const nuevoImporte = cantidad * precio;

    const tdImporte = tr.querySelector('.importe-fila');

    if (tdImporte) {
        tdImporte.dataset.importe = nuevoImporte;
        tdImporte.textContent = '$' + nuevoImporte.toFixed(2);
    }

    actualizarTotalEntrada();
    guardarBorradorEntrada();
}

function eliminarFilaEntrada(btn) {
    btn.closest('tr').remove();

    if (detalleBody.children.length === 0) {
        detalleBody.innerHTML = `
            <tr id="filaVaciaDetalle">
                <td colspan="8" class="empty-table">No has agregado productos.</td>
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
    ubicacionInput.value = '';

    cargarCatalogoUbicacionesEntrada();
}

function construirReferenciaDocumento() {
    const tipoDocumento = tipoDocumentoInput.value.trim();
    const folioDocumento = folioDocumentoInput.value.trim();

    if (tipoDocumento && folioDocumento) {
        referenciaFinalInput.value = `${tipoDocumento}: ${folioDocumento}`;
    } else if (tipoDocumento) {
        referenciaFinalInput.value = tipoDocumento;
    } else if (folioDocumento) {
        referenciaFinalInput.value = folioDocumento;
    } else {
        referenciaFinalInput.value = '';
    }
}

document.getElementById('formEntrada').addEventListener('submit', function (e) {
    construirReferenciaDocumento();

    if (document.querySelectorAll('input[name="producto_id[]"]').length === 0) {
        e.preventDefault();
        alert('Debes agregar al menos un producto.');
        return;
    }

    let ubicacionInvalida = false;

    document.querySelectorAll('input[name="ubicacion[]"]').forEach(input => {
        if (limpiarUbicacion(input.value) === 'SIN UBICACION') {
            ubicacionInvalida = true;
        }
    });

    if (ubicacionInvalida) {
        e.preventDefault();
        alert('Todas las partidas deben tener una ubicación válida.');
        return;
    }

    localStorage.removeItem('borradorEntrada');
});

function guardarBorradorEntrada() {
    construirReferenciaDocumento();

    const datos = {
        folio: document.querySelector('[name="folio"]')?.value || '',
        fecha: document.querySelector('[name="fecha"]')?.value || '',
        tipo_entrada: document.querySelector('[name="tipo_entrada"]')?.value || '',
        almacen_id: document.querySelector('[name="almacen_id"]')?.value || '',
        proveedor_nombre: document.querySelector('[name="proveedor_nombre"]')?.value || '',
        tipo_documento: document.querySelector('[name="tipo_documento"]')?.value || '',
        folio_documento: document.querySelector('[name="folio_documento"]')?.value || '',
        referencia: document.querySelector('[name="referencia"]')?.value || '',
        observaciones: document.querySelector('[name="observaciones"]')?.value || ''
    };

    localStorage.setItem('borradorEntrada', JSON.stringify(datos));
}

function cargarBorradorEntrada() {
    const guardado = localStorage.getItem('borradorEntrada');

    if (!guardado) return;

    const datos = JSON.parse(guardado);

    if (datos.tipo_entrada) document.querySelector('[name="tipo_entrada"]').value = datos.tipo_entrada;
    if (datos.almacen_id) document.querySelector('[name="almacen_id"]').value = datos.almacen_id;
    if (datos.proveedor_nombre) document.querySelector('[name="proveedor_nombre"]').value = datos.proveedor_nombre;
    if (datos.tipo_documento) document.querySelector('[name="tipo_documento"]').value = datos.tipo_documento;
    if (datos.folio_documento) document.querySelector('[name="folio_documento"]').value = datos.folio_documento;
    if (datos.observaciones) document.querySelector('[name="observaciones"]').value = datos.observaciones;

    construirReferenciaDocumento();
}

document.querySelectorAll('input, select, textarea').forEach(campo => {
    campo.addEventListener('change', guardarBorradorEntrada);
    campo.addEventListener('keyup', guardarBorradorEntrada);
});

function ponerFechaActualEntrada() {
    const inputFecha = document.getElementById('fecha_entrada');
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
    localStorage.removeItem('borradorEntrada');
    cargarCatalogoUbicacionesEntrada();
    cargarBorradorEntrada();
    ponerFechaActualEntrada();
});
</script>

<?php
if (file_exists(__DIR__ . '/../app/views/layouts/footer.php')) {
    include __DIR__ . '/../app/views/layouts/footer.php';
}
?>