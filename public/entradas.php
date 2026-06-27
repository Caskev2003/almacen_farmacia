<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/EntradaController.php';

requireLogin();

$user = currentUser();
$controller = new EntradaController();

$message = '';
$messageType = 'danger';

// Variables para edición
$editarId = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
$modoEdicion = $editarId > 0;
$entradaEditar = null;
$observacionesEditar = '';
$referenciaEditar = '';
$tipoDoc = '';
$folioDoc = '';
$tipoEntradaSeleccionado = '';
$proveedorSeleccionado = '';

if ($modoEdicion) {
    $entradaEditar = $controller->obtenerEntrada($editarId);

    if (!$entradaEditar) {
        $message = 'La entrada que intentas editar no existe.';
        $messageType = 'danger';
        $modoEdicion = false;
    } elseif ((int)($entradaEditar['cancelado'] ?? 0) === 1) {
        $message = 'No puedes editar una entrada cancelada.';
        $messageType = 'danger';
        $modoEdicion = false;
    } else {
      $observacionesEditar = (string)($entradaEditar['observaciones'] ?? '');
$referenciaEditar = (string)($entradaEditar['referencia'] ?? '');

$tipoEntradaSeleccionado = '';
$proveedorSeleccionado = '';
$tipoDoc = '';
$folioDoc = '';

$partesReferencia = array_map('trim', explode('|', $referenciaEditar));

$tipoEntradaSeleccionado = $partesReferencia[0] ?? '';

foreach ($partesReferencia as $parte) {
    if (stripos($parte, 'Proveedor:') === 0) {
        $proveedorSeleccionado = trim(substr($parte, strlen('Proveedor:')));
    }

    if (stripos($parte, 'Ref:') === 0) {
        $documento = trim(substr($parte, strlen('Ref:')));

        if (strpos($documento, ':') !== false) {
            [$tipoDocTmp, $folioDocTmp] = explode(':', $documento, 2);

            $tipoDoc = strtoupper(trim($tipoDocTmp));
            $folioDoc = trim($folioDocTmp);
        } else {
            $folioDoc = $documento;
        }
    }
}
    }
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editarPostId = (int)($_POST['editar_id'] ?? 0);

    if ($editarPostId > 0) {
        $result = $controller->actualizar($editarPostId, $_POST, (int)$user['id']);
    } else {
        $result = $controller->guardar($_POST, (int)$user['id']);
    }

    if ($result['success']) {
        $movimientoId = (int)($result['movimiento_id'] ?? 0);

        if ($movimientoId > 0) {
            header('Location: imprimir_entrada.php?id=' . $movimientoId . '&preview=1');
            exit;
        }

        $message = '✅ La entrada se guardó correctamente.';
        $messageType = 'success';
        echo "<script>localStorage.removeItem('borradorEntrada');</script>";
    } else {
        $message = '❌ ' . ($result['message'] ?? 'Error al guardar la entrada');
        $messageType = 'danger';
    }
}

// Datos necesarios para la vista
$almacenes = $controller->almacenes();
$productos = $controller->productos();
$tiposEntrada = $controller->tiposEntrada();

$almacenSesion = (int)($user['almacen_id'] ?? 0);
$rolUsuario = strtoupper(trim($user['rol'] ?? ''));

$folio = $controller->generarFolio($almacenSesion);
if ($modoEdicion && $entradaEditar) {
    $folio = $entradaEditar['folio'];
}

$folioAnterior = method_exists($controller, 'ultimoFolioEntrada')
    ? $controller->ultimoFolioEntrada($almacenSesion)
    : '';

date_default_timezone_set('America/Mexico_City');

// ===== FECHA AUTOMÁTICA =====
$fechaActual = date('Y-m-d\TH:i');
if ($modoEdicion && $entradaEditar && !empty($entradaEditar['fecha'])) {
    $fechaActual = date('Y-m-d\TH:i', strtotime($entradaEditar['fecha']));
}

// ===== UBICACIONES GENERALES PARA ENTRADAS =====
$ubicacionesGenerales = [];

$ubicacionesTodas = method_exists($controller, 'ubicacionesTodas')
    ? $controller->ubicacionesTodas($almacenSesion)
    : [];

foreach ($ubicacionesTodas as $ubicacion) {
    $ubicacion = strtoupper(trim((string)$ubicacion));
    if ($ubicacion !== '' && $ubicacion !== 'SIN UBICACION') {
        $ubicacionesGenerales[$ubicacion] = $ubicacion;
    }
}

ksort($ubicacionesGenerales);

// ===== CONSTRUIR ARRAY DE PRODUCTOS PARA JS =====
$productosJson = [];
foreach ($productos as $p) {
    $ubicacionesProducto = [];
    if (!empty($p['ubicaciones']) && is_array($p['ubicaciones'])) {
        foreach ($p['ubicaciones'] as $ubi) {
            $ubicacionTmp = strtoupper(trim((string)($ubi['ubicacion'] ?? '')));
            if ($ubicacionTmp !== '' && $ubicacionTmp !== 'SIN UBICACION') {
                $ubicacionesProducto[] = [
                    'ubicacion' => $ubicacionTmp,
                    'existencia_actual' => (int)($ubi['existencia_actual'] ?? $ubi['existencia'] ?? 0)
                ];
            }
        }
    }
    if (empty($ubicacionesProducto)) {
        $ubicacionNormal = strtoupper(trim((string)($p['ubicacion'] ?? '')));
        if ($ubicacionNormal !== '' && $ubicacionNormal !== 'SIN UBICACION') {
            $ubicacionesProducto[] = [
                'ubicacion' => $ubicacionNormal,
                'existencia_actual' => (int)($p['existencia_actual'] ?? 0)
            ];
        }
    }
    
    usort($ubicacionesProducto, function($a, $b) {
        return $a['existencia_actual'] - $b['existencia_actual'];
    });
    
    $ubicacionSugerida = $ubicacionesProducto[0]['ubicacion'] ?? '';
    
    $productosJson[] = [
        'id' => (int)$p['id'],
        'codigo' => $p['codigo'],
        'descripcion' => $p['descripcion'],
        'precio_compra' => (float)($p['precio_compra'] ?? 0),
        'ubicacion_sugerida' => $ubicacionSugerida,
        'ubicaciones' => $ubicacionesProducto
    ];
}

$moduleCss = 'entradas';
include __DIR__ . '/../app/views/layouts/header.php';
?>

<style>
/* =========================================================
   DISEÑO CLARO Y ESPACIOSO CON MODAL GRANDE ESTILO EXCEL
   ========================================================= */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    background: #eef2f7;
    font-family: 'Segoe UI', 'Inter', system-ui, -apple-system, sans-serif;
    padding: 24px;
    min-height: 100vh;
}

/* ===== HEADER SIMPLE ===== */
.page-header {
    margin-bottom: 28px;
}

.page-header h1 {
    font-size: 26px;
    font-weight: 600;
    color: #1a2c3e;
}

/* ===== CONTENEDOR PRINCIPAL ===== */
.main-container {
    max-width: 1600px;
    margin: 0 auto;
}

/* ===== SECCIÓN DE DATOS DEL DOCUMENTO ===== */
.doc-section {
    background: white;
    border-radius: 20px;
    padding: 24px 28px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    border: 1px solid #e4e7eb;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px 24px;
}

.form-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.form-field label {
    font-size: 12px;
    font-weight: 600;
    color: #4a5b6e;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.form-field input,
.form-field select,
.form-field textarea {
    padding: 12px 14px;
    border: 1px solid #d0d5dd;
    border-radius: 12px;
    font-size: 14px;
    color: #1a2c3e;
    background: white;
    transition: all 0.2s;
}

.form-field input:focus,
.form-field select:focus,
.form-field textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
}

.form-field input:read-only {
    background: #f8f9fa;
    color: #6c7a8a;
}

.form-field textarea {
    resize: vertical;
    min-height: 70px;
}

.folio-previous {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 12px 14px;
    margin-top: 16px;
    border: 1px solid #e4e7eb;
}

.folio-previous span {
    font-size: 13px;
    color: #2c7a4d;
    font-weight: 500;
}

/* ===== SECCIÓN DE CAPTURA RÁPIDA ===== */
.capture-section {
    background: white;
    border-radius: 20px;
    padding: 24px 28px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    border: 1px solid #e4e7eb;
}

.capture-title {
    font-size: 18px;
    font-weight: 600;
    color: #1a2c3e;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 2px solid #e4e7eb;
}

.capture-grid {
    display: grid;
    grid-template-columns: 1fr 150px 180px 160px 160px 140px;
    gap: 16px;
    align-items: end;
}

.capture-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.capture-field label {
    font-size: 12px;
    font-weight: 600;
    color: #4a5b6e;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.capture-field input {
    padding: 12px 14px;
    border: 1px solid #d0d5dd;
    border-radius: 12px;
    font-size: 14px;
    background: white;
}

.capture-field input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
}

/* Botones rápidos cantidad */
.qty-actions {
    display: flex;
    gap: 8px;
    margin-top: 8px;
}

.qty-btn {
    flex: 1;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    padding: 6px 0;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.1s;
    color: #4a5b6e;
}

.qty-btn:hover {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}

.btn-add {
    background: #3b82f6;
    border: none;
    padding: 12px 20px;
    border-radius: 40px;
    color: white;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    white-space: nowrap;
}

.btn-add:hover {
    background: #2563eb;
    transform: scale(1.01);
}

/* Producto seleccionado */
.selected-info {
    background: #eff6ff;
    border-radius: 14px;
    padding: 16px;
    margin: 16px 0 0 0;
    border: 1px solid #bfdbfe;
}

.selected-info .row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 13px;
}

.selected-info .label {
    color: #4a5b6e;
}

.selected-info .value {
    color: #1a2c3e;
    font-weight: 600;
}

/* ===== MODAL GRANDE ESTILO EXCEL ===== */
.modal-excel {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 100000;
    backdrop-filter: blur(4px);
}

.modal-excel.active {
    display: flex;
}

.modal-excel-content {
    background: white;
    border-radius: 24px;
    width: 90%;
    max-width: 1100px;
    height: 80vh;
    max-height: 700px;
    display: flex;
    flex-direction: column;
    box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
    animation: modalZoom 0.2s ease;
}

@keyframes modalZoom {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}

.modal-excel-header {
    padding: 20px 24px;
    border-bottom: 1px solid #e4e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8f9fa;
    border-radius: 24px 24px 0 0;
}

.modal-excel-header h3 {
    font-size: 20px;
    font-weight: 600;
    color: #1a2c3e;
    display: flex;
    align-items: center;
    gap: 10px;
}

.modal-excel-header .shortcut {
    font-size: 12px;
    color: #6c7a8a;
    background: #eef2f7;
    padding: 4px 10px;
    border-radius: 40px;
}

.modal-excel-close {
    background: none;
    border: none;
    font-size: 28px;
    cursor: pointer;
    color: #6c7a8a;
    transition: all 0.1s;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

.modal-excel-close:hover {
    background: #eef2f7;
    color: #1a2c3e;
}

.modal-excel-search {
    padding: 20px 24px;
    border-bottom: 1px solid #e4e7eb;
    background: white;
}

.modal-excel-search input {
    width: 100%;
    padding: 14px 18px;
    border: 2px solid #e4e7eb;
    border-radius: 14px;
    font-size: 16px;
    transition: all 0.2s;
}

.modal-excel-search input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
}

.modal-excel-table-container {
    flex: 1;
    overflow: auto;
    padding: 0 20px 20px 20px;
}

.modal-excel-table {
    width: 100%;
    border-collapse: collapse;
}

.modal-excel-table th {
    position: sticky;
    top: 0;
    background: #f8f9fa;
    padding: 14px 12px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    color: #4a5b6e;
    border-bottom: 2px solid #e4e7eb;
    z-index: 10;
}

.modal-excel-table td {
    padding: 14px 12px;
    border-bottom: 1px solid #eef2f6;
    font-size: 14px;
    color: #1a2c3e;
}

.modal-excel-table tr {
    cursor: pointer;
    transition: background 0.1s;
}

.modal-excel-table tr:hover td {
    background: #f0f7ff;
}

.modal-excel-table tr.selected td {
    background: #dbeafe;
}

.modal-excel-table .product-code {
    font-weight: 700;
    color: #2563eb;
}

.modal-excel-footer {
    padding: 16px 24px;
    border-top: 1px solid #e4e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8f9fa;
    border-radius: 0 0 24px 24px;
    font-size: 13px;
    color: #6c7a8a;
}

.modal-excel-footer kbd {
    background: white;
    border: 1px solid #d0d5dd;
    padding: 3px 8px;
    border-radius: 6px;
    font-family: monospace;
    margin: 0 4px;
}

/* ===== TABLA DE PRODUCTOS ===== */
.products-section {
    background: white;
    border-radius: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    border: 1px solid #e4e7eb;
    overflow: hidden;
}

.products-header {
    padding: 20px 24px;
    border-bottom: 1px solid #eef2f6;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.products-header h3 {
    font-size: 17px;
    font-weight: 600;
    color: #1a2c3e;
}

.product-badge {
    background: #eef2ff;
    padding: 5px 14px;
    border-radius: 40px;
    font-size: 13px;
    font-weight: 600;
    color: #3b82f6;
}

.table-responsive {
    overflow-x: auto;
    padding: 0 20px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    text-align: left;
    padding: 14px 12px;
    color: #6c7a8a;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    border-bottom: 1px solid #eef2f6;
}

.data-table td {
    padding: 14px 12px;
    color: #1a2c3e;
    font-size: 14px;
    border-bottom: 1px solid #f0f2f5;
}

.data-table tr:hover td {
    background: #fafbfc;
}

.qty-input, .price-input {
    width: 80px;
    padding: 8px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    text-align: center;
    font-size: 13px;
}

.delete-btn {
    background: none;
    border: none;
    color: #ef4444;
    cursor: pointer;
    font-size: 18px;
    padding: 6px 10px;
    border-radius: 10px;
    transition: all 0.1s;
}

.delete-btn:hover {
    background: #fef2f2;
}

.table-footer {
    padding: 20px 24px;
    border-top: 1px solid #eef2f6;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.total-amount {
    font-size: 28px;
    font-weight: 700;
    color: #059669;
}

.btn-save {
    background: #059669;
    border: none;
    padding: 12px 32px;
    border-radius: 40px;
    color: white;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-save:hover {
    background: #047857;
}

.btn-clear {
    background: none;
    border: 1px solid #ef4444;
    padding: 12px 24px;
    border-radius: 40px;
    color: #ef4444;
    font-weight: 600;
    cursor: pointer;
}

/* Shortcuts bar */
.shortcuts-bar {
    margin-top: 20px;
    padding: 12px 20px;
    background: #f8f9fa;
    border-radius: 60px;
    text-align: center;
    font-size: 12px;
    color: #6c7a8a;
}

.shortcuts-bar kbd {
    background: white;
    border: 1px solid #d0d5dd;
    padding: 3px 8px;
    border-radius: 6px;
    font-family: monospace;
    margin: 0 4px;
    font-size: 11px;
}

/* Toast */
.toast-message {
    position: fixed;
    bottom: 30px;
    right: 30px;
    background: white;
    border-left: 4px solid #059669;
    padding: 14px 24px;
    border-radius: 12px;
    box-shadow: 0 8px 20px rgba(0,0,0,0.12);
    z-index: 100001;
    animation: slideIn 0.3s ease;
    font-size: 14px;
    color: #1a2c3e;
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* Responsive */
@media (max-width: 1200px) {
    .capture-grid {
        grid-template-columns: 1fr 1fr;
    }
    
    .btn-add {
        grid-column: span 2;
    }
}

@media (max-width: 768px) {
    body {
        padding: 16px;
    }
    
    .doc-section, .capture-section {
        padding: 18px;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .capture-grid {
        grid-template-columns: 1fr;
    }
    
    .btn-add {
        grid-column: span 1;
    }
    
    .table-footer {
        flex-direction: column;
        align-items: stretch;
    }
    
    .btn-save, .btn-clear {
        width: 100%;
        justify-content: center;
    }
    
    .modal-excel-content {
        width: 95%;
        height: 85vh;
    }
}
</style>

<!-- HEADER -->
<div class="page-header">
    <h1><?= $modoEdicion ? '✏️ Editar Entrada' : '📥 Nueva Entrada de Almacén' ?></h1>
</div>

<div class="main-container">

<?php if ($message): ?>
    <div class="alert alert-<?= e($messageType) ?>" style="margin-bottom: 20px; padding: 14px 20px; border-radius: 12px; background: <?= $messageType === 'success' ? '#ecfdf5' : '#fef2f2' ?>; color: <?= $messageType === 'success' ? '#065f46' : '#991b1b' ?>; border-left: 4px solid <?= $messageType === 'success' ? '#059669' : '#ef4444' ?>;">
        <?= e($message) ?>
    </div>
<?php endif; ?>

<form method="POST" id="formEntrada">
    <?php if ($modoEdicion && $entradaEditar): ?>
        <input type="hidden" name="editar_id" value="<?= (int)$entradaEditar['id'] ?>">
    <?php endif; ?>
    <input type="hidden" name="referencia" id="referencia_final" value="<?= e($referenciaEditar) ?>">

    <!-- SECCIÓN 1: DATOS DEL DOCUMENTO -->
    <div class="doc-section">
        <div class="form-grid">
            <div class="form-field">
                <label>📄 Folio</label>
                <input type="text" name="folio" value="<?= e($folio) ?>" readonly>
            </div>

            <div class="form-field">
                <label>📅 Fecha y hora</label>
                <input type="datetime-local" name="fecha" id="fechaInput" required value="<?= $fechaActual ?>">
            </div>

            <div class="form-field">
                <label>📋 Tipo de entrada *</label>
                <select name="tipo_entrada" required>
                    <option value="">Seleccione...</option>
                    <?php foreach ($tiposEntrada as $tipo): 
                        $tipoValue = $tipo['clave'] . ' - ' . $tipo['descripcion'];
                        $selected = ($modoEdicion && $tipoValue === $tipoEntradaSeleccionado) ? 'selected' : '';
                    ?>
                        <option value="<?= e($tipoValue) ?>" <?= $selected ?>>
                            <?= e($tipo['clave']) ?> - <?= e($tipo['descripcion']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <<div class="form-field">
    <label>🏪 Almacén *</label>

    <?php
    $almacenSeleccionado = $modoEdicion && $entradaEditar
        ? (int)($entradaEditar['almacen_id'] ?? $almacenSesion)
        : $almacenSesion;

    $puedeEditarAlmacen = in_array(
        strtoupper(trim($rolUsuario)),
        ['ADMINISTRADOR', 'ENCARGADO'],
        true
    );
    ?>

    <select
        name="almacen_id"
        required
        <?= !$puedeEditarAlmacen ? 'disabled' : '' ?>
    >
        <option value="">Seleccione...</option>

        <?php foreach ($almacenes as $almacen): ?>
            <option
                value="<?= (int)$almacen['id'] ?>"
                <?= (int)$almacen['id'] === $almacenSeleccionado ? 'selected' : '' ?>
            >
                <?= e($almacen['nombre']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <?php if (!$puedeEditarAlmacen): ?>
        <input
            type="hidden"
            name="almacen_id"
            value="<?= (int)$almacenSeleccionado ?>"
        >
    <?php endif; ?>
</div>

            <div class="form-field">
                <label>🏢 Proveedor</label>
                <input type="text" name="proveedor_nombre" placeholder="Ingrese el nombre del proveedor" value="<?= $modoEdicion ? e($proveedorSeleccionado) : '' ?>">
            </div>

            <div class="form-field">
                <label>📑 Tipo de documento</label>
                <select name="tipo_documento" id="tipo_documento">
                    <option value="">Seleccione...</option>
                    <option value="FACTURA" <?= $modoEdicion && $tipoDoc === 'FACTURA' ? 'selected' : '' ?>>📄 Factura</option>
                    <option value="NOTA" <?= $modoEdicion && $tipoDoc === 'NOTA' ? 'selected' : '' ?>>📝 Nota</option>
                    <option value="REMISION" <?= $modoEdicion && $tipoDoc === 'REMISION' ? 'selected' : '' ?>>📋 Remisión</option>
                    <option value="TICKET" <?= $modoEdicion && $tipoDoc === 'TICKET' ? 'selected' : '' ?>>🎫 Ticket</option>
                    <option value="AJUSTE" <?= $modoEdicion && $tipoDoc === 'AJUSTE' ? 'selected' : '' ?>>⚙️ Ajuste</option>
                    <option value="TRASPASO" <?= $modoEdicion && $tipoDoc === 'TRASPASO' ? 'selected' : '' ?>>🚚 Traspaso</option>
                    <option value="OTRO" <?= $modoEdicion && $tipoDoc === 'OTRO' ? 'selected' : '' ?>>📎 Otro</option>
                </select>
            </div>

            <div class="form-field">
                <label>🔢 Folio del documento</label>
                <input type="text" name="folio_documento" id="folio_documento" placeholder="Ingrese folio" value="<?= $modoEdicion ? e($folioDoc) : '' ?>">
            </div>

            <div class="form-field">
                <label>📝 Observaciones</label>
                <textarea name="observaciones" placeholder="Información adicional..."><?= $modoEdicion ? e($observacionesEditar) : '' ?></textarea>
            </div>
        </div>
        
        <div class="folio-previous">
            <span>📋 Último folio registrado: <?= e($folioAnterior ?: 'Sin entradas anteriores') ?></span>
        </div>
    </div>

    <!-- SECCIÓN 2: CAPTURA RÁPIDA -->
    <div class="capture-section">
        <div class="capture-title">➕ Agregar productos</div>
        
        <div class="capture-grid">
            <div class="capture-field">
                <label>🔍 Producto</label>
                <div style="display: flex; gap: 8px;">
                    <input type="text" id="productoDisplayInput" placeholder="Presiona Ctrl+B para buscar" readonly style="background: #f8f9fa; cursor: pointer;">
                    <button type="button" id="openModalBtn" style="background: #3b82f6; border: none; padding: 0 20px; border-radius: 12px; color: white; font-weight: 600; cursor: pointer;">🔍 Buscar</button>
                </div>
            </div>

            <div class="capture-field">
                <label>🔢 Cantidad</label>
                <input type="number" id="cantidadInput" value="1" min="1" step="1">
                <div class="qty-actions">
                    <button type="button" class="qty-btn" onclick="cambiarCantidad(1)">+1</button>
                    <button type="button" class="qty-btn" onclick="cambiarCantidad(5)">+5</button>
                    <button type="button" class="qty-btn" onclick="cambiarCantidad(10)">+10</button>
                </div>
            </div>

            <div class="capture-field">
                <label>💰 Precio unitario</label>
                <input type="number" id="precioInput" step="0.01" value="0.00">
            </div>

            <div class="capture-field">
                <label>🔢 Número de lote</label>
                <input type="text" id="loteInput" placeholder="Opcional">
            </div>

            <div class="capture-field">
                <label>📍 Ubicación</label>
                <input
                    type="text"
                    id="ubicacionInput"
                    list="ubicacionesList"
                    placeholder="Escriba o seleccione ubicación"
                    autocomplete="off"
                >
                <datalist id="ubicacionesList">
                    <?php foreach ($ubicacionesGenerales as $ubicacion): ?>
                        <option value="<?= e($ubicacion) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <small style="font-size: 11px; color: #6c7a8a;">
                    <?= count($ubicacionesGenerales) ?> ubicación(es) disponible(s)
                </small>
            </div>

            <button type="button" class="btn-add" id="agregarBtn">
                ➕ Agregar producto (Enter)
            </button>
        </div>

        <div id="selectedInfo" style="display: none;">
            <div class="selected-info">
                <div class="row"><span class="label">📦 Producto:</span><span class="value" id="infoCodigo">-</span></div>
                <div class="row"><span class="label">📝 Descripción:</span><span class="value" id="infoDescripcion">-</span></div>
                <div class="row"><span class="label">📍 Ubicación sugerida:</span><span class="value" id="infoUbicacion">-</span></div>
            </div>
        </div>

        <div class="shortcuts-bar">
            🎯 <kbd>Ctrl</kbd> + <kbd>B</kbd> Abrir buscador &nbsp;&nbsp;|&nbsp;&nbsp;
            <kbd>↑</kbd> <kbd>↓</kbd> Navegar resultados &nbsp;&nbsp;|&nbsp;&nbsp;
            <kbd>Enter</kbd> Seleccionar producto &nbsp;&nbsp;|&nbsp;&nbsp;
            <kbd>Enter</kbd> (en cantidad/precio/ubicación) Agregar &nbsp;&nbsp;|&nbsp;&nbsp;
            <kbd>Ctrl</kbd> + <kbd>Enter</kbd> Guardar entrada
        </div>
    </div>

    <!-- SECCIÓN 3: TABLA DE PRODUCTOS -->
    <div class="products-section">
        <div class="products-header">
            <h3>📋 Productos agregados</h3>
            <span class="product-badge" id="productosCount">0 productos</span>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Cantidad</th>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th>Precio U.</th>
                        <th>Lote</th>
                        <th>Ubicación</th>
                        <th>Importe</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="detalleBody">
                    <?php if ($modoEdicion && !empty($entradaEditar['detalles'])): ?>
                        <?php foreach ($entradaEditar['detalles'] as $item): 
                            $cantidad = (int)($item['cantidad'] ?? 0);
                            $precio = (float)($item['precio_unitario'] ?? 0);
                            $importe = $cantidad * $precio;
                            $costoUnitario = (float)($item['costo_unitario'] ?? 0);
                            $productoId = (int)($item['producto_id'] ?? 0);
                            $ubicacion = trim($item['ubicacion'] ?? '');
                            $codigo = e($item['codigo'] ?? '');
                            $descripcion = e(substr($item['descripcion'] ?? '', 0, 60));
                            $lote = e($item['numero_lote'] ?? '');
                        ?>
                            <tr data-producto-id="<?= $productoId ?>">
                                <td>
                                    <input type="number" class="qty-input" value="<?= $cantidad ?>" min="1" onchange="actualizarCantidadFila(this)" style="width:70px; padding:6px;">
                                    <input type="hidden" name="cantidad[]" value="<?= $cantidad ?>">
                                    <input type="hidden" name="producto_id[]" value="<?= $productoId ?>">
                                    <input type="hidden" name="costo_unitario[]" value="<?= $costoUnitario ?>">
                                    <input type="hidden" name="numero_lote[]" value="<?= e($lote) ?>">
                                    <input type="hidden" name="fecha_caducidad[]" value="">
                                    <input type="hidden" name="ubicacion[]" value="<?= e($ubicacion) ?>">
                                </td>
                                <td><strong><?= $codigo ?></strong></td>
                                <td><?= $descripcion ?></td>
                                <td>
                                    <input type="number" class="price-input" value="<?= number_format($precio, 2) ?>" step="0.01" min="0" onchange="actualizarPrecioFila(this)" style="width:80px; padding:6px;">
                                </td>
                                <td><?= e($lote) ?></td>
                                <td><?= e($ubicacion) ?></td>
                                <td class="importe-fila" data-importe="<?= $importe ?>"><strong>$<?= number_format($importe, 2) ?></strong></td>
                                <td><button type="button" class="delete-btn" onclick="eliminarFila(this)">🗑️</button></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr id="filaVacia">
                            <td colspan="8" style="text-align: center; padding: 50px; color: #9ca3af;">
                                📭 No hay productos. Presiona Ctrl+B para buscar
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer">
            <button type="button" class="btn-clear" onclick="limpiarTodo()">🗑️ Limpiar todo</button>
            <div>
                <span style="color: #6c7a8a;">Total general:</span>
                <span class="total-amount" id="totalEntrada">$0.00</span>
            </div>
            <button type="button" class="btn-save" id="guardarBtn">
                💾 Guardar entrada (Ctrl+Enter)
            </button>
        </div>
    </div>
</form>
</div>

<!-- MODAL GRANDE ESTILO EXCEL -->
<div class="modal-excel" id="modalExcel">
    <div class="modal-excel-content">
        <div class="modal-excel-header">
            <h3>🔍 Buscar producto <span class="shortcut">Ctrl+B para abrir</span></h3>
            <button class="modal-excel-close" id="closeModalBtn">✕</button>
        </div>
        <div class="modal-excel-search">
            <input type="text" id="modalSearchInput" placeholder="Escribe código o nombre del producto..." autocomplete="off">
        </div>
        <div class="modal-excel-table-container">
            <table class="modal-excel-table">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th>Precio</th>
                        <th>Ubicación sugerida</th>
                        <th>Ubicaciones disponibles</th>
                    </tr>
                </thead>
                <tbody id="modalTableBody"></tbody>
            </table>
        </div>
        <div class="modal-excel-footer">
            <span><kbd>↑</kbd> <kbd>↓</kbd> Navegar | <kbd>Enter</kbd> Seleccionar | <kbd>Esc</kbd> Cerrar</span>
            <span>Mostrando <span id="modalResultCount">0</span> productos</span>
        </div>
    </div>
</div>

<script>
// ===== VARIABLES =====
const productos = <?php echo json_encode($productosJson, JSON_UNESCAPED_UNICODE); ?>;

console.log('Productos cargados:', productos.length);

let productoSeleccionado = null;
let modalProductosFiltrados = [];
let modalSelectedIndex = -1;

// Elementos DOM
const modal = document.getElementById('modalExcel');
const modalSearch = document.getElementById('modalSearchInput');
const modalTableBody = document.getElementById('modalTableBody');
const modalResultCount = document.getElementById('modalResultCount');
const productoDisplayInput = document.getElementById('productoDisplayInput');
const cantidadInput = document.getElementById('cantidadInput');
const precioInput = document.getElementById('precioInput');
const loteInput = document.getElementById('loteInput');
const ubicacionInput = document.getElementById('ubicacionInput');
const detalleBody = document.getElementById('detalleBody');

// ===== FECHA AUTOMÁTICA =====
function ponerFechaActual() {
    const fechaInput = document.getElementById('fechaInput');
    if (!fechaInput.value) {
        const ahora = new Date();
        const year = ahora.getFullYear();
        const month = String(ahora.getMonth() + 1).padStart(2, '0');
        const day = String(ahora.getDate()).padStart(2, '0');
        const hours = String(ahora.getHours()).padStart(2, '0');
        const minutes = String(ahora.getMinutes()).padStart(2, '0');
        fechaInput.value = `${year}-${month}-${day}T${hours}:${minutes}`;
    }
}

// ===== MODAL =====
function abrirModal() {
    console.log('Abriendo modal, productos:', productos.length);
    if (productos.length === 0) {
        mostrarToast('⚠️ No hay productos disponible', 'warning');
        return;
    }
    modal.classList.add('active');
    modalSearch.value = '';
    cargarProductosEnModal(productos);
    setTimeout(() => modalSearch.focus(), 100);
    modalSelectedIndex = -1;
}

function cerrarModal() {
    modal.classList.remove('active');
}

function cargarProductosEnModal(productosList) {
    modalProductosFiltrados = productosList;
    modalResultCount.textContent = productosList.length;
    
    if (productosList.length === 0) {
        modalTableBody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 40px;">No se encontraron productos</td></tr>';
        return;
    }
    
    modalTableBody.innerHTML = productosList.map((p, idx) => {
        const ubicacionesTexto = p.ubicaciones.map(u => `${u.ubicacion}(${u.existencia_actual})`).join(', ');
        return `
            <tr data-idx="${idx}" data-producto-id="${p.id}" style="cursor: pointer;">
                <td class="product-code">${escapeHtml(p.codigo)}</td>
                <td>${escapeHtml(p.descripcion)}</td>
                <td>$${p.precio_compra.toFixed(2)}</td>
                <td>${escapeHtml(p.ubicacion_sugerida || 'N/A')}</td>
                <td><small>${escapeHtml(ubicacionesTexto || 'Ninguna')}</small></td>
            </tr>
        `;
    }).join('');
    
    document.querySelectorAll('#modalTableBody tr').forEach(row => {
        row.addEventListener('click', () => {
            const idx = parseInt(row.dataset.idx);
            if (modalProductosFiltrados[idx]) {
                seleccionarProductoDelModal(modalProductosFiltrados[idx]);
            }
        });
    });
}

function filtrarProductosModal() {
    const termino = modalSearch.value.toLowerCase().trim();
    if (!termino) {
        cargarProductosEnModal(productos);
        return;
    }
    const filtrados = productos.filter(p => 
        p.codigo.toLowerCase().includes(termino) || 
        p.descripcion.toLowerCase().includes(termino)
    );
    cargarProductosEnModal(filtrados);
    modalSelectedIndex = -1;
}

function seleccionarProductoDelModal(producto) {
    productoSeleccionado = producto;
    
    document.getElementById('selectedInfo').style.display = 'block';
    document.getElementById('infoCodigo').innerHTML = `<strong>${escapeHtml(producto.codigo)}</strong>`;
    document.getElementById('infoDescripcion').textContent = producto.descripcion;
    document.getElementById('infoUbicacion').textContent = producto.ubicacion_sugerida || 'No definida';
    
    productoDisplayInput.value = `${producto.codigo} - ${producto.descripcion.substring(0, 50)}`;
    precioInput.value = producto.precio_compra;
    if (producto.ubicacion_sugerida) {
        ubicacionInput.value = producto.ubicacion_sugerida;
    }
    
    cerrarModal();
    cantidadInput.focus();
    cantidadInput.select();
    
    mostrarToast(`✅ Producto seleccionado: ${producto.codigo}`);
}

function actualizarSeleccionModal() {
    const filas = document.querySelectorAll('#modalTableBody tr');
    filas.forEach((fila, idx) => {
        if (idx === modalSelectedIndex) {
            fila.classList.add('selected');
            fila.style.background = '#dbeafe';
            fila.scrollIntoView({ block: 'nearest' });
        } else {
            fila.classList.remove('selected');
            fila.style.background = '';
        }
    });
}

// ===== CANTIDAD =====
function cambiarCantidad(valor) {
    let nuevo = parseInt(cantidadInput.value) + valor;
    if (nuevo < 1) nuevo = 1;
    cantidadInput.value = nuevo;
}

// ===== AGREGAR PRODUCTO =====
function agregarProducto() {
    if (!productoSeleccionado) {
        mostrarToast('❌ Selecciona un producto (Ctrl+B para buscar)', 'error');
        return;
    }
    
    const cantidad = parseInt(cantidadInput.value);
    const precio = parseFloat(precioInput.value);
    const lote = loteInput.value.trim();
    let ubicacion = ubicacionInput.value.trim().toUpperCase();
    
    if (!ubicacion) ubicacion = productoSeleccionado.ubicacion_sugerida || 'SIN UBICACION';
    
    if (cantidad <= 0 || isNaN(cantidad)) {
        mostrarToast('❌ Cantidad inválida', 'error');
        return;
    }
    
    if (precio < 0 || isNaN(precio)) {
        mostrarToast('❌ Precio inválido', 'error');
        return;
    }
    
    if (ubicacion === 'SIN UBICACION') {
        mostrarToast('❌ Selecciona una ubicación válida', 'error');
        ubicacionInput.focus();
        return;
    }
    
    const filaVacia = document.getElementById('filaVacia');
    if (filaVacia) filaVacia.remove();
    
    const importe = cantidad * precio;
    const tr = document.createElement('tr');
    tr.dataset.productoId = productoSeleccionado.id;
    tr.innerHTML = `
        <td>
            <input type="number" class="qty-input" value="${cantidad}" min="1" onchange="actualizarCantidadFila(this)" style="width:70px; padding:6px;">
            <input type="hidden" name="cantidad[]" value="${cantidad}">
            <input type="hidden" name="producto_id[]" value="${productoSeleccionado.id}">
            <input type="hidden" name="costo_unitario[]" value="${precio.toFixed(2)}">
            <input type="hidden" name="numero_lote[]" value="${escapeHtml(lote)}">
            <input type="hidden" name="fecha_caducidad[]" value="">
            <input type="hidden" name="ubicacion[]" value="${escapeHtml(ubicacion)}">
        </td>
        <td><strong>${escapeHtml(productoSeleccionado.codigo)}</strong></td>
        <td>${escapeHtml(productoSeleccionado.descripcion.substring(0, 45))}</td>
        <td>
            <input type="number" class="price-input" value="${precio.toFixed(2)}" step="0.01" min="0" onchange="actualizarPrecioFila(this)" style="width:80px; padding:6px;">
        </td>
        <td>${escapeHtml(lote)}</td>
        <td>${escapeHtml(ubicacion)}</td>
        <td class="importe-fila" data-importe="${importe}"><strong>$${importe.toFixed(2)}</strong></td>
        <td><button type="button" class="delete-btn" onclick="eliminarFila(this)">🗑️</button></td>
    `;
    
    detalleBody.appendChild(tr);
    actualizarTotales();
    
    // Resetear
    productoSeleccionado = null;
    document.getElementById('selectedInfo').style.display = 'none';
    productoDisplayInput.value = '';
    cantidadInput.value = '1';
    precioInput.value = '0.00';
    loteInput.value = '';
    ubicacionInput.value = '';
    
    mostrarToast(`✅ ${cantidad} x producto agregado`);
}

function actualizarCantidadFila(input) {
    const tr = input.closest('tr');
    let cantidad = parseInt(input.value);
    if (isNaN(cantidad) || cantidad < 1) cantidad = 1;
    
    const precioInputFila = tr.querySelector('.price-input');
    const precio = parseFloat(precioInputFila?.value || '0');
    const nuevoImporte = cantidad * precio;
    
    const importeTd = tr.querySelector('.importe-fila');
    if (importeTd) {
        importeTd.dataset.importe = nuevoImporte;
        importeTd.innerHTML = `<strong>$${nuevoImporte.toFixed(2)}</strong>`;
    }
    
    const hiddenInput = tr.querySelector('input[name="cantidad[]"]');
    if (hiddenInput) hiddenInput.value = cantidad;
    
    actualizarTotales();
}

function actualizarPrecioFila(input) {
    const tr = input.closest('tr');
    let precio = parseFloat(input.value);
    if (isNaN(precio) || precio < 0) precio = 0;
    
    const cantidadInputFila = tr.querySelector('.qty-input');
    const cantidad = parseInt(cantidadInputFila?.value || '0');
    const nuevoImporte = cantidad * precio;
    
    const importeTd = tr.querySelector('.importe-fila');
    if (importeTd) {
        importeTd.dataset.importe = nuevoImporte;
        importeTd.innerHTML = `<strong>$${nuevoImporte.toFixed(2)}</strong>`;
    }
    
    const hiddenInput = tr.querySelector('input[name="costo_unitario[]"]');
    if (hiddenInput) hiddenInput.value = precio.toFixed(2);
    
    actualizarTotales();
}

function eliminarFila(btn) {
    btn.closest('tr').remove();
    if (detalleBody.children.length === 0) {
        detalleBody.innerHTML = '<tr id="filaVacia"><td colspan="8" style="text-align: center; padding: 50px; color: #9ca3af;">📭 No hay productos. Presiona Ctrl+B para buscar</td></tr>';
    }
    actualizarTotales();
    mostrarToast('🗑️ Producto eliminado', 'info');
}

function limpiarTodo() {
    if (confirm('¿Eliminar todos los productos?')) {
        detalleBody.innerHTML = '<tr id="filaVacia"><td colspan="8" style="text-align: center; padding: 50px; color: #9ca3af;">📭 No hay productos. Presiona Ctrl+B para buscar</td></tr>';
        actualizarTotales();
        mostrarToast('🧹 Lista limpiada', 'info');
    }
}

function actualizarTotales() {
    let total = 0;
    let count = 0;
    document.querySelectorAll('.importe-fila').forEach(td => {
        total += parseFloat(td.dataset.importe || '0');
        count++;
    });
    document.getElementById('totalEntrada').innerHTML = `$${total.toFixed(2)}`;
    document.getElementById('productosCount').textContent = `${count} producto${count !== 1 ? 's' : ''}`;
}

function mostrarToast(mensaje, tipo = 'success') {
    const toast = document.createElement('div');
    toast.className = 'toast-message';
    toast.style.borderLeftColor = tipo === 'success' ? '#059669' : (tipo === 'error' ? '#ef4444' : '#f59e0b');
    toast.innerHTML = mensaje;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2500);
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// ===== REFERENCIA DOCUMENTO =====
const tipoDocumento = document.getElementById('tipo_documento');
const folioDocumento = document.getElementById('folio_documento');
const referenciaFinal = document.getElementById('referencia_final');

function construirReferencia() {
    const tipo = tipoDocumento.value.trim();
    const folio = folioDocumento.value.trim();
    if (tipo && folio) {
        referenciaFinal.value = `${tipo}: ${folio}`;
    } else if (tipo) {
        referenciaFinal.value = tipo;
    } else if (folio) {
        referenciaFinal.value = folio;
    } else {
        referenciaFinal.value = '';
    }
}

// ===== GUARDAR =====
function guardarEntrada() {
    construirReferencia();
    
    const productosEnLista = document.querySelectorAll('#detalleBody tr:not(#filaVacia)');
    if (productosEnLista.length === 0) {
        mostrarToast('❌ Agrega al menos un producto', 'error');
        return;
    }
    
    let ubicacionInvalida = false;
    document.querySelectorAll('input[name="ubicacion[]"]').forEach(input => {
        const ubicacion = (input.value || '').trim().toUpperCase();
        if (!ubicacion || ubicacion === 'SIN UBICACION') {
            ubicacionInvalida = true;
        }
    });
    
    if (ubicacionInvalida) {
        mostrarToast('❌ Todas las partidas deben tener ubicación válida', 'error');
        return;
    }
    
    document.getElementById('formEntrada').submit();
}

// ===== GENERAR TODAS LAS UBICACIONES (como en productos.php) =====
function generarTodasLasUbicaciones() {
    const lista = document.getElementById('ubicacionesList');
    if (!lista) return;
    
    const ubicaciones = [];
    
    function add(rack, nivel, zona) {
        const z = String(zona).padStart(2, '0');
        ubicaciones.push(`R${rack}N${nivel}Z${z}`);
    }
    
    // Rack 1: niveles 1-3, zonas 1-22
    for (let n = 1; n <= 3; n++) {
        for (let z = 1; z <= 22; z++) {
            add(1, n, z);
        }
    }
    
    // Rack 2: niveles 1-3, zonas 1-20
    for (let n = 1; n <= 3; n++) {
        for (let z = 1; z <= 20; z++) {
            add(2, n, z);
        }
    }
    
    // Rack 3: niveles 1-3, zonas 1-20
    for (let n = 1; n <= 3; n++) {
        for (let z = 1; z <= 20; z++) {
            add(3, n, z);
        }
    }
    
    // Rack 4: niveles 1-2, zonas 1-16
    for (let n = 1; n <= 2; n++) {
        for (let z = 1; z <= 16; z++) {
            add(4, n, z);
        }
    }
    // Rack 4 nivel 3 zonas 10-16
    for (let z = 10; z <= 16; z++) {
        add(4, 3, z);
    }
    
    // Rack 5: niveles 1-2, zonas 1-15
    for (let n = 1; n <= 2; n++) {
        for (let z = 1; z <= 15; z++) {
            add(5, n, z);
        }
    }
    // Rack 5 nivel 3 zonas 10-15
    for (let z = 10; z <= 15; z++) {
        add(5, 3, z);
    }
    
    // Rack 6: niveles 1-3, zonas 1-22
    for (let n = 1; n <= 3; n++) {
        for (let z = 1; z <= 22; z++) {
            add(6, n, z);
        }
    }
    
    // Ubicaciones adicionales
    ubicaciones.push('R7N1Z01 - PASILLO 3');
    ubicaciones.push('R8N1Z01 - PASILLO 2');
    ubicaciones.push('R9N1Z01 - PASILLO 1');
    ubicaciones.push('BODEGA PEDYALITE');
   
    
    // Limpiar opciones existentes y agregar todas
    lista.innerHTML = '';
    
    ubicaciones.forEach(u => {
        const option = document.createElement('option');
        option.value = u;
        lista.appendChild(option);
    });
    
    console.log('Ubicaciones cargadas:', ubicaciones.length);
}

// ===== INICIALIZACIÓN =====
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM cargado - Entradas');
    
    // Generar todas las ubicaciones
    generarTodasLasUbicaciones();
    
    actualizarTotales();
    ponerFechaActual();
    
    // Ctrl+B para abrir modal
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && (e.key === 'b' || e.key === 'B')) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Ctrl+B presionado - Abriendo modal');
            abrirModal();
            return false;
        }
    });
    
    // Enter en campos = agregar producto
    const camposEnter = ['cantidadInput', 'precioInput', 'ubicacionInput', 'loteInput'];
    camposEnter.forEach(id => {
        const campo = document.getElementById(id);
        if (campo) {
            campo.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    agregarProducto();
                }
            });
        }
    });
    
    // Ctrl+Enter = guardar
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'Enter') {
            e.preventDefault();
            guardarEntrada();
        }
    });
    
    // Botones
    document.getElementById('openModalBtn')?.addEventListener('click', abrirModal);
    document.getElementById('closeModalBtn')?.addEventListener('click', cerrarModal);
    document.getElementById('agregarBtn')?.addEventListener('click', agregarProducto);
    document.getElementById('guardarBtn')?.addEventListener('click', guardarEntrada);
    
    // Modal teclado
    if (modalSearch) {
        modalSearch.addEventListener('keydown', function(e) {
            const filas = document.querySelectorAll('#modalTableBody tr');
            const totalFilas = filas.length;
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                modalSelectedIndex = Math.min(modalSelectedIndex + 1, totalFilas - 1);
                actualizarSeleccionModal();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                modalSelectedIndex = Math.max(modalSelectedIndex - 1, 0);
                actualizarSeleccionModal();
            } else if (e.key === 'Enter' && modalSelectedIndex >= 0 && modalProductosFiltrados[modalSelectedIndex]) {
                e.preventDefault();
                seleccionarProductoDelModal(modalProductosFiltrados[modalSelectedIndex]);
            } else if (e.key === 'Escape') {
                cerrarModal();
            }
        });
        
        modalSearch.addEventListener('input', filtrarProductosModal);
    }
    
    // Cerrar modal al hacer clic fuera
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) cerrarModal();
        });
    }
    
    // Escape global
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal && modal.classList.contains('active')) {
            cerrarModal();
        }
    });
    
    // Referencia documento
    tipoDocumento?.addEventListener('change', construirReferencia);
    folioDocumento?.addEventListener('input', construirReferencia);
    
    // Forzar construcción de referencia inicial (para edición)
    construirReferencia();
});
</script>

<?php
if (file_exists(__DIR__ . '/../app/views/layouts/footer.php')) {
    include __DIR__ . '/../app/views/layouts/footer.php';
}
?>