<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/ProductoController.php';

requireLogin();

$controller = new ProductoController();
$user = currentUser();

$rolUsuario = strtoupper(trim($user['rol'] ?? ''));
$esAdmin = in_array($rolUsuario, ['ADMINISTRADOR', 'ADMIN'], true);

$almacenNombre = strtoupper(trim($user['almacen_nombre'] ?? ''));

if (str_contains($almacenNombre, 'HIDALGO')) {
    $sucursalUsuario = 'CIUDAD HIDALGO';
} elseif (str_contains($almacenNombre, 'TUXTLA')) {
    $sucursalUsuario = 'TUXTLA';
} else {
    $sucursalUsuario = $almacenNombre;
}

$message = '';
$messageType = '';

$search = trim($_GET['search'] ?? '');
$filtroCategoria = trim($_GET['categoria_id'] ?? '');
$filtroProveedor = '';
$filtroUbicacion = trim($_GET['ubicacion'] ?? '');
$filtroStock = trim($_GET['estado_stock'] ?? '');

$editando = null;

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $result = $controller->store($_POST);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'danger';
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $result = $controller->update($id, $_POST);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'danger';
    }

    if ($action === 'guardar_ubicacion_existencia') {
        $result = $controller->guardarUbicacionExistencia($_POST);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'danger';
    }

    if ($action === 'editar_ubicacion_existencia') {
        $result = $controller->editarUbicacionExistencia($_POST);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'danger';
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $result = $controller->destroy($id);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'danger';
    }
}

if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editando = $controller->find($editId);
}

$productosTodos = $controller->index(
    $search,
    $sucursalUsuario,
    $filtroCategoria,
    $filtroProveedor,
    $filtroUbicacion,
    $filtroStock
);

$categorias = $controller->categorias();
$proveedores = $controller->proveedores();

$totalProductos = count($productosTodos);
$totalPages = max(1, (int)ceil($totalProductos / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;
$productos = array_slice($productosTodos, $offset, $perPage);

$queryBase = http_build_query([
    'search' => $search,
    'categoria_id' => $filtroCategoria,
    'ubicacion' => $filtroUbicacion,
    'estado_stock' => $filtroStock
]);

$moduleCss = 'productos';
include __DIR__ . '/../app/views/layouts/header.php';

function estadoStockProducto(array $producto, bool $esAdmin): array
{
    $existencia = $esAdmin
        ? (int)($producto['existencia_total'] ?? ((int)($producto['existencia_hidalgo'] ?? 0) + (int)($producto['existencia_tuxtla'] ?? 0)))
        : (int)($producto['existencia'] ?? 0);

    if ($existencia <= 0) {
        return [
            'texto' => 'AGOTADO',
            'clase' => 'stock-agotado',
            'existencia' => $existencia
        ];
    }

    if ($existencia <= 120) {
        return [
            'texto' => 'BAJO STOCK',
            'clase' => 'stock-bajo',
            'existencia' => $existencia
        ];
    }

    return [
        'texto' => 'NORMAL',
        'clase' => 'stock-normal',
        'existencia' => $existencia
    ];
}
?>

<div class="module-header">
    <div>
        <h2>Catálogo de Productos</h2>
        <p>
            Catálogo general de productos y control de existencias por almacén.
            <?php if (!$esAdmin && $sucursalUsuario !== ''): ?>
                <strong>Almacén actual: <?= e($sucursalUsuario) ?></strong>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= e($messageType) ?>">
        <?= e($message) ?>
    </div>
<?php endif; ?>

<style>
.pagination-wrapper {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-top: 18px;
    flex-wrap: wrap;
}

.pagination-info {
    font-size: 14px;
    color: #475569;
    font-weight: 600;
}

.pagination {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    align-items: center;
}

.pagination a,
.pagination span {
    padding: 8px 12px;
    border-radius: 8px;
    text-decoration: none;
    border: 1px solid #d1d5db;
    color: #0f3b66;
    font-size: 14px;
    font-weight: 700;
    background: #fff;
}

.pagination .active {
    background: #00529b;
    color: white;
    border-color: #00529b;
}

.pagination .disabled {
    opacity: .45;
    cursor: not-allowed;
}

.table-topbar-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.filters-card {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 14px;
    margin-bottom: 16px;
}

.filters-grid {
    display: grid;
    grid-template-columns: 1.4fr 1fr 1fr 1fr 1fr auto;
    gap: 10px;
    align-items: end;
}

.filters-grid .form-group {
    margin: 0;
}

.filters-grid label {
    font-size: 12px;
    font-weight: 800;
    color: #475569;
    margin-bottom: 5px;
    display: block;
}

.filters-grid input,
.filters-grid select {
    width: 100%;
}

.stock-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 900;
    white-space: nowrap;
}

.stock-normal {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
}

.stock-bajo {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fcd34d;
}

.stock-agotado {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}

.badge-existencia {
    font-weight: bold;
    color: #15803d;
}

.badge-hidalgo {
    font-weight: bold;
    color: #1d4ed8;
}

.badge-tuxtla {
    font-weight: bold;
    color: #7c3aed;
}

.ubicaciones-badge-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.ubicacion-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
    border: 1px solid transparent;
    cursor: pointer;
}

.low-stock {
    background: #fee2e2;
    color: #b91c1c;
    border-color: #fca5a5;
}

.medium-stock {
    background: #fef3c7;
    color: #92400e;
    border-color: #fcd34d;
}

.high-stock {
    background: #dcfce7;
    color: #166534;
    border-color: #86efac;
}

.modal-inventario {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 99999;
    align-items: center;
    justify-content: center;
}

.modal-inventario-card {
    width: 95%;
    max-width: 520px;
    background: white;
    border-radius: 18px;
    padding: 24px;
}

.modal-inventario-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-inventario-close {
    border: none;
    background: none;
    font-size: 28px;
    cursor: pointer;
}

@media (max-width: 1200px) {
    .filters-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 700px) {
    .filters-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="erp-container">
    <div class="erp-form-card">
        <h3><?= $editando ? 'Editar producto' : 'Nuevo producto' ?></h3>

        <form method="POST" action="">
            <input type="hidden" name="action" value="<?= $editando ? 'update' : 'create' ?>">

            <?php if ($editando): ?>
                <input type="hidden" name="id" value="<?= (int)$editando['id'] ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-group">
                    <label>Código *</label>
                    <input type="text" name="codigo" required value="<?= e($editando['codigo'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label>Código de barras</label>
                    <input type="text" name="codigo_barras" value="<?= e($editando['codigo_barras'] ?? '') ?>">
                </div>

                <div class="form-group form-group-full">
                    <label>Descripción *</label>
                    <input type="text" name="descripcion" required value="<?= e($editando['descripcion'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label>Categoría</label>
                    <select name="categoria_id">
                        <option value="">Seleccione</option>
                        <?php foreach ($categorias as $categoria): ?>
                            <option value="<?= (int)$categoria['id'] ?>"
                                <?= isset($editando['categoria_id']) && (int)$editando['categoria_id'] === (int)$categoria['id'] ? 'selected' : '' ?>>
                                <?= e($categoria['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Proveedor</label>
                    <input
                        type="text"
                        name="proveedor_nombre"
                        list="listaProveedores"
                        autocomplete="off"
                        placeholder="Escribe o selecciona proveedor"
                        value="<?= e($editando['proveedor_nombre'] ?? '') ?>"
                    >

                    <datalist id="listaProveedores">
                        <?php foreach ($proveedores as $proveedor): ?>
                            <option value="<?= e($proveedor['nombre']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="form-group">
                    <label>Laboratorio / Marca</label>
                    <input type="text" name="laboratorio" value="<?= e($editando['laboratorio'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label>Unidad de medida</label>
                    <input type="text" name="unidad_medida" value="<?= e($editando['unidad_medida'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label>Precio Unitario</label>
                    <input type="number" step="0.01" min="0" name="precio_compra" value="<?= e($editando['precio_compra'] ?? '0.00') ?>">
                </div>

                <div class="form-group">
                    <label>Ubicación principal</label>
                    <input
                        type="text"
                        name="ubicacion"
                        id="ubicacion"
                        list="listaUbicaciones"
                        autocomplete="off"
                        placeholder="Ejemplo: R1N1Z01"
                        value="<?= e($editando['ubicacion'] ?? '') ?>"
                    >
                    <datalist id="listaUbicaciones"></datalist>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary-action">
                    <?= $editando ? 'Actualizar producto' : 'Guardar producto' ?>
                </button>

                <?php if ($editando): ?>
                    <a href="productos.php" class="btn-secondary-action">Cancelar edición</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="erp-table-card">
        <div class="table-topbar">
            <div class="table-topbar-actions">
                <h3>Lista de productos</h3>

                <a href="importar_productos.php" class="btn-primary-action">
                    Importar Excel
                </a>
            </div>
        </div>

        <form method="GET" action="" class="filters-card">
            <div class="filters-grid">
                <div class="form-group">
                    <label>Buscar</label>
                    <input
                        type="text"
                        name="search"
                        placeholder="Código, barras, descripción, proveedor..."
                        value="<?= e($search) ?>"
                    >
                </div>

                <div class="form-group">
                    <label>Categoría</label>
                    <select name="categoria_id">
                        <option value="">Todas</option>
                        <?php foreach ($categorias as $categoria): ?>
                            <option value="<?= (int)$categoria['id'] ?>"
                                <?= $filtroCategoria !== '' && (int)$filtroCategoria === (int)$categoria['id'] ? 'selected' : '' ?>>
                                <?= e($categoria['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>


                <div class="form-group">
    <label>Rack</label>
    <select name="ubicacion" class="auto-filter">
        <option value="">Todos</option>
        <?php for ($r = 1; $r <= 9; $r++): ?>
            <option value="R<?= $r ?>" <?= $filtroUbicacion === 'R' . $r ? 'selected' : '' ?>>
                R<?= $r ?>
            </option>
        <?php endfor; ?>
    </select>
</div>

                <div class="form-group">
                    <label>Estado stock</label>
                    <select name="estado_stock">
                        <option value="">Todos</option>
                        <option value="normal" <?= $filtroStock === 'normal' ? 'selected' : '' ?>>Stock normal</option>
                        <option value="bajo" <?= $filtroStock === 'bajo' ? 'selected' : '' ?>>Bajo stock</option>
                        <option value="agotado" <?= $filtroStock === 'agotado' ? 'selected' : '' ?>>Agotados</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>&nbsp;</label>
                    <div style="display:flex; gap:8px;">
                        <a href="productos.php" class="btn-clear">Limpiar</a>
                    </div>
                </div>
            </div>
        </form>

        <div class="pagination-info" style="margin-bottom:12px;">
            Mostrando <?= $totalProductos > 0 ? ($offset + 1) : 0 ?> -
            <?= min($offset + $perPage, $totalProductos) ?>
            de <?= $totalProductos ?> productos
        </div>

        <div class="table-responsive">
            <table class="erp-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Código</th>
                        <th>Cód. barras</th>
                        <th>Descripción</th>
                        <th>Categoría</th>
                        <th>Marca</th>
                        <th>Unidad</th>
                        <th>Precio Unitario</th>

                        <?php if ($esAdmin): ?>
                            <th>Ciudad Hidalgo</th>
                            <th>Tuxtla</th>
                        <?php else: ?>
                            <th>Existencia</th>
                        <?php endif; ?>

                        <th>Estado stock</th>
                        <th>Ubicación</th>
                        <th>Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (count($productos) > 0): ?>
                        <?php foreach ($productos as $producto): ?>
                            <?php $estadoStock = estadoStockProducto($producto, $esAdmin); ?>

                            <tr>
                                <td><?= (int)$producto['id'] ?></td>
                                <td><?= e($producto['codigo']) ?></td>
                                <td><?= e($producto['codigo_barras']) ?></td>
                                <td><?= e($producto['descripcion']) ?></td>
                                <td><?= e($producto['categoria'] ?? '') ?></td>
                                <td><?= e($producto['proveedor'] ?? '') ?></td>
                                <td><?= e($producto['laboratorio'] ?? '') ?></td>
                                <td><?= e($producto['unidad_medida'] ?? '') ?></td>
                                <td>$<?= number_format((float)($producto['precio_compra'] ?? 0), 2) ?></td>

                                <?php if ($esAdmin): ?>
                                    <td class="badge-hidalgo"><?= (int)($producto['existencia_hidalgo'] ?? 0) ?></td>
                                    <td class="badge-tuxtla"><?= (int)($producto['existencia_tuxtla'] ?? 0) ?></td>
                                <?php else: ?>
                                    <td class="badge-existencia"><?= (int)($producto['existencia'] ?? 0) ?></td>
                                <?php endif; ?>

                                <td>
                                    <span class="stock-pill <?= e($estadoStock['clase']) ?>">
                                        <?= e($estadoStock['texto']) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php
                                    $ubicaciones = $producto['ubicaciones'] ?? [];

                                    if (!empty($ubicaciones) && is_array($ubicaciones)):
                                    ?>
                                        <div class="ubicaciones-badge-wrap">
                                            <?php foreach ($ubicaciones as $ubicacionItem): ?>
                                                <?php
                                                $ubi = strtoupper(trim((string)($ubicacionItem['ubicacion'] ?? '')));
                                                $exist = (int)($ubicacionItem['existencia_actual'] ?? 0);
                                                $sucursalBadge = strtoupper(trim((string)($ubicacionItem['sucursal'] ?? $sucursalUsuario)));

                                                if ($ubi === '') {
                                                    $ubi = 'SIN UBICACION';
                                                }

                                                $classStock = 'high-stock';

                                                if ($exist <= 10) {
                                                    $classStock = 'low-stock';
                                                } elseif ($exist <= 50) {
                                                    $classStock = 'medium-stock';
                                                }
                                                ?>

                                                <button
                                                    type="button"
                                                    class="ubicacion-badge <?= $classStock ?>"
                                                    onclick="abrirModalEditarUbicacion(
                                                        '<?= (int)$producto['id'] ?>',
                                                        '<?= e($producto['descripcion']) ?>',
                                                        '<?= e($sucursalBadge) ?>',
                                                        '<?= e($ubi) ?>',
                                                        '<?= (int)$exist ?>'
                                                    )"
                                                >
                                                    <?= e($ubi) ?> (<?= $exist ?>)
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <?= e($producto['ubicacion'] ?? '') ?>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="action-buttons">
                                        <button
                                            type="button"
                                            class="btn-search"
                                            onclick="abrirModalUbicacion(
                                                '<?= e($producto['codigo']) ?>',
                                                '<?= e($producto['descripcion']) ?>'
                                            )"
                                        >
                                            Ubicaciones
                                        </button>

                                        <a href="productos.php?edit=<?= (int)$producto['id'] ?>&page=<?= $page ?>&<?= e($queryBase) ?>" class="btn-edit">
                                            Editar
                                        </a>

                                        <form method="POST" action="" onsubmit="return confirm('¿Deseas eliminar este producto?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$producto['id'] ?>">
                                            <button type="submit" class="btn-delete">
                                                Eliminar
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?= $esAdmin ? '13' : '12' ?>" class="empty-table">
                                No hay productos registrados con esos filtros.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination-wrapper">
                <div class="pagination-info">
                    Página <?= $page ?> de <?= $totalPages ?>
                </div>

                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="productos.php?page=1&<?= e($queryBase) ?>">Primera</a>
                        <a href="productos.php?page=<?= $page - 1 ?>&<?= e($queryBase) ?>">Anterior</a>
                    <?php else: ?>
                        <span class="disabled">Primera</span>
                        <span class="disabled">Anterior</span>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);

                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <?php if ($i === $page): ?>
                            <span class="active"><?= $i ?></span>
                        <?php else: ?>
                            <a href="productos.php?page=<?= $i ?>&<?= e($queryBase) ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="productos.php?page=<?= $page + 1 ?>&<?= e($queryBase) ?>">Siguiente</a>
                        <a href="productos.php?page=<?= $totalPages ?>&<?= e($queryBase) ?>">Última</a>
                    <?php else: ?>
                        <span class="disabled">Siguiente</span>
                        <span class="disabled">Última</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="modalUbicacion" class="modal-inventario">
    <div class="modal-inventario-card">
        <div class="modal-inventario-header">
            <div>
                <h3 id="tituloProductoUbicacion">Ubicaciones</h3>
                <p style="margin:0;color:#64748b;">
                    Agrega existencia por ubicación.
                </p>
            </div>

            <button type="button" onclick="cerrarModalUbicacion()" class="modal-inventario-close">
                &times;
            </button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="guardar_ubicacion_existencia">
            <input type="hidden" name="codigo" id="codigoUbicacion">

            <div class="form-group">
                <label>Sucursal</label>

                <select name="sucursal" required>
                    <?php if ($esAdmin): ?>
                        <option value="CIUDAD HIDALGO">Ciudad Hidalgo</option>
                        <option value="TUXTLA">Tuxtla</option>
                    <?php else: ?>
                        <option value="<?= e($sucursalUsuario) ?>">
                            <?= e($sucursalUsuario) ?>
                        </option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Ubicación</label>
                <input
                    type="text"
                    name="ubicacion"
                    list="listaUbicaciones"
                    required
                    autocomplete="off"
                    placeholder="Ejemplo: R1N1Z01"
                >
            </div>

            <div class="form-group">
                <label>Existencia</label>
                <input
                    type="number"
                    name="existencia"
                    min="0"
                    required
                    value="0"
                >
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary-action">
                    Guardar ubicación
                </button>
            </div>
        </form>
    </div>
</div>

<div id="modalEditarUbicacion" class="modal-inventario">
    <div class="modal-inventario-card">
        <div class="modal-inventario-header">
            <div>
                <h3 id="tituloEditarUbicacion">Editar ubicación</h3>
                <p style="margin:0;color:#64748b;">
                    Cambia la ubicación y la cantidad existente.
                </p>
            </div>

            <button type="button" onclick="cerrarModalEditarUbicacion()" class="modal-inventario-close">
                &times;
            </button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="editar_ubicacion_existencia">
            <input type="hidden" name="producto_id" id="editarProductoId">
            <input type="hidden" name="sucursal" id="editarSucursal">
            <input type="hidden" name="ubicacion_anterior" id="editarUbicacionAnterior">

            <div class="form-group">
                <label>Ubicación nueva</label>
                <input
                    type="text"
                    name="ubicacion_nueva"
                    id="editarUbicacionNueva"
                    list="listaUbicaciones"
                    required
                    autocomplete="off"
                >
            </div>

            <div class="form-group">
                <label>Existencia</label>
                <input
                    type="number"
                    name="existencia"
                    id="editarExistencia"
                    min="0"
                    required
                >
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary-action">
                    Guardar cambios
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const lista = document.getElementById('listaUbicaciones');

    if (!lista) return;

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

    lista.innerHTML = '';

    ubicaciones.forEach(u => {
        const option = document.createElement('option');
        option.value = u;
        lista.appendChild(option);
    });
});

function abrirModalUbicacion(codigo, descripcion) {
    document.getElementById('modalUbicacion').style.display = 'flex';
    document.getElementById('codigoUbicacion').value = codigo;
    document.getElementById('tituloProductoUbicacion').innerText = descripcion;
}

function cerrarModalUbicacion() {
    document.getElementById('modalUbicacion').style.display = 'none';
}

function abrirModalEditarUbicacion(productoId, descripcion, sucursal, ubicacion, existencia) {
    document.getElementById('modalEditarUbicacion').style.display = 'flex';
    document.getElementById('tituloEditarUbicacion').innerText = descripcion;
    document.getElementById('editarProductoId').value = productoId;
    document.getElementById('editarSucursal').value = sucursal;
    document.getElementById('editarUbicacionAnterior').value = ubicacion;
    document.getElementById('editarUbicacionNueva').value = ubicacion;
    document.getElementById('editarExistencia').value = existencia;
}

function cerrarModalEditarUbicacion() {
    document.getElementById('modalEditarUbicacion').style.display = 'none';
}

const formFiltrosProductos = document.querySelector('.filters-card');

if (formFiltrosProductos) {
    const searchInput = formFiltrosProductos.querySelector('input[name="search"]');
    const selects = formFiltrosProductos.querySelectorAll('select');

    let timerFiltro = null;

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(timerFiltro);

            timerFiltro = setTimeout(() => {
                formFiltrosProductos.submit();
            }, 500);
        });
    }

    selects.forEach(select => {
        select.addEventListener('change', function () {
            formFiltrosProductos.submit();
        });
    });
}
</script>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>