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

$productosTodos = $controller->index($search, $sucursalUsuario);
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
    'search' => $search
]);

$moduleCss = 'productos';
include __DIR__ . '/../app/views/layouts/header.php';
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
                    <select name="proveedor_id">
                        <option value="">Seleccione</option>
                        <?php foreach ($proveedores as $proveedor): ?>
                            <option value="<?= (int)$proveedor['id'] ?>"
                                <?= isset($editando['proveedor_id']) && (int)$editando['proveedor_id'] === (int)$proveedor['id'] ? 'selected' : '' ?>>
                                <?= e($proveedor['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
                    <label>Precio de compra</label>
                    <input type="number" step="0.01" min="0" name="precio_compra" value="<?= e($editando['precio_compra'] ?? '0.00') ?>">
                </div>

                <div class="form-group">
                    <label>Precio de venta</label>
                    <input type="number" step="0.01" min="0" name="precio_venta" value="<?= e($editando['precio_venta'] ?? '0.00') ?>">
                </div>

                <div class="form-group">
                    <label>Stock mínimo</label>
                    <input type="number" min="0" name="stock_minimo" value="<?= e($editando['stock_minimo'] ?? '0') ?>">
                </div>

                <div class="form-group">
                    <label>Stock máximo</label>
                    <input type="number" min="0" name="stock_maximo" value="<?= e($editando['stock_maximo'] ?? '0') ?>">
                </div>

                <div class="form-group">
                    <label>Ubicación</label>
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

            <form method="GET" action="" class="search-form">
                <input
                    type="text"
                    name="search"
                    placeholder="Buscar por código, barras o descripción"
                    value="<?= e($search) ?>"
                >
                <button type="submit" class="btn-search">Buscar</button>
                <a href="productos.php" class="btn-clear">Limpiar</a>
            </form>
        </div>

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
                        <th>Proveedor</th>
                        <th>Marca</th>
                        <th>Unidad</th>
                        <th>P. Compra</th>
                        <th>P. Venta</th>

                        <?php if ($esAdmin): ?>
                            <th>Ciudad Hidalgo</th>
                            <th>Tuxtla</th>
                        <?php else: ?>
                            <th>Existencia</th>
                        <?php endif; ?>

                        <th>Ubicación</th>
                        <th>Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (count($productos) > 0): ?>
                        <?php foreach ($productos as $producto): ?>
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
                                <td>$<?= number_format((float)($producto['precio_venta'] ?? 0), 2) ?></td>

                                <?php if ($esAdmin): ?>
                                    <td class="badge-hidalgo"><?= (int)($producto['existencia_hidalgo'] ?? 0) ?></td>
                                    <td class="badge-tuxtla"><?= (int)($producto['existencia_tuxtla'] ?? 0) ?></td>
                                <?php else: ?>
                                    <td class="badge-existencia"><?= (int)($producto['existencia'] ?? 0) ?></td>
                                <?php endif; ?>

                                <td><?= e($producto['ubicacion'] ?? '') ?></td>

                                <td>
                                    <div class="action-buttons">
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
                            <td colspan="<?= $esAdmin ? '14' : '13' ?>" class="empty-table">
                                No hay productos registrados.
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

    ubicaciones.push('R7N1Z01 - PASILLO 1');
    ubicaciones.push('R8N1Z01 - PASILLO 2');
    ubicaciones.push('R9N1Z01 - PASILLO 3');
    ubicaciones.push('BODEGA PEDYALITE');

    lista.innerHTML = '';

    ubicaciones.forEach(u => {
        const option = document.createElement('option');
        option.value = u;
        lista.appendChild(option);
    });
});
</script>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>