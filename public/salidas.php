<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/SalidaController.php';

requireLogin();

$user = currentUser();
$controller = new SalidaController();

$message = '';
$messageType = 'danger';

// Variables para edición
$folioOperacionEditar = '';
$observacionesLimpiasEditar = '';
$editarId = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
$modoEdicion = $editarId > 0;
$salidaEditar = null;

if ($modoEdicion) {
    $salidaEditar = $controller->obtenerSalida($editarId);

    if (!$salidaEditar) {
        $message = 'La salida que intentas editar no existe.';
        $messageType = 'danger';
        $modoEdicion = false;
    } elseif ((int)($salidaEditar['cancelado'] ?? 0) === 1) {
        $message = 'No puedes editar una salida cancelada.';
        $messageType = 'danger';
        $modoEdicion = false;
    } else {
        $observacionesEditar = (string)($salidaEditar['observaciones'] ?? '');
        $observacionesLimpiasEditar = $observacionesEditar;

        $patterns = [
            '/Folio\s+ticket:\s*([^|]+)\|?(.*)/i',
            '/Folio\s+resurtido:\s*([^|]+)\|?(.*)/i',
            '/Folio\s+nota_remision:\s*([^|]+)\|?(.*)/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $observacionesEditar, $matches)) {
                $folioOperacionEditar = trim($matches[1]);
                $observacionesLimpiasEditar = trim($matches[2] ?? '');
                break;
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
            header('Location: imprimir_salida.php?id=' . $movimientoId . '&preview=1');
            exit;
        }

        $message = '✅ La salida se guardó correctamente.';
        $messageType = 'success';
        echo "<script>localStorage.removeItem('borradorSalida');</script>";
    } else {
        $message = '❌ ' . ($result['message'] ?? 'Error al guardar la salida');
        $messageType = 'danger';
    }
}

// Datos necesarios para la vista
$almacenes = $controller->almacenes();
$productos = $controller->productos();
$productosPorId = [];

foreach ($productos as $productoTmp) {
    $productosPorId[(int)$productoTmp['id']] = $productoTmp;
}

$tiposSalida = $controller->tiposSalida();
$almacenSesion = (int)($user['almacen_id'] ?? 0);
$rolUsuario = strtoupper(trim($user['rol'] ?? ''));

$folio = $controller->generarFolio($almacenSesion);
if ($modoEdicion && $salidaEditar) {
    $folio = $salidaEditar['folio'];
}
$folioAnterior = $controller->ultimoFolioSalida($almacenSesion);

date_default_timezone_set('America/Mexico_City');

// ===== FECHA AUTOMÁTICA =====
$fechaActual = date('Y-m-d\TH:i');

// Ya no necesitamos generar ubicaciones desde PHP, lo haremos en JS
$moduleCss = 'salidas';
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
    grid-template-columns: 1fr 180px 200px 180px 140px;
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
    z-index: 1000;
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
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
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

.modal-excel-table .product-stock {
    font-weight: 600;
    color: #059669;
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

.qty-input {
    width: 70px;
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
    z-index: 1100;
    animation: slideIn 0.3s ease;
    font-size: 14px;
    color: #1a2c3e;
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* Responsive */
@media (max-width: 1100px) {
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

/* Estilos para evitar interferencia con la tabla de ubicaciones */
.ubicaciones-wrapper {
    pointer-events: auto;
}

.ubicaciones-interna-table tr {
    cursor: default !important;
}

.ubicaciones-interna-table tr:hover td {
    background: transparent !important;
}

.modal-excel-table tr.producto-principal.selected td {
    background: #dbeafe;
}

.ubicaciones-wrapper::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}

.ubicaciones-wrapper::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.ubicaciones-wrapper::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.ubicaciones-wrapper::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

.ubicaciones-cell {
    cursor: default;
}
</style>

<!-- HEADER -->
<div class="page-header">
    <h1>Salida de Almacén</h1>
</div>

<div class="main-container">

<?php if ($message): ?>
    <div class="alert alert-<?= e($messageType) ?>" style="margin-bottom: 20px; padding: 14px 20px; border-radius: 12px; background: <?= $messageType === 'success' ? '#ecfdf5' : '#fef2f2' ?>; color: <?= $messageType === 'success' ? '#065f46' : '#991b1b' ?>; border-left: 4px solid <?= $messageType === 'success' ? '#059669' : '#ef4444' ?>;">
        <?= e($message) ?>
    </div>
<?php endif; ?>

<form method="POST" id="formSalida">
    <?php if ($modoEdicion && $salidaEditar): ?>
        <input type="hidden" name="editar_id" value="<?= (int)$salidaEditar['id'] ?>">
    <?php endif; ?>

    <!-- SECCIÓN 1: DATOS DEL DOCUMENTO -->
    <div class="doc-section">
        <div class="form-grid">
            <div class="form-field">
                <label>📄 Folio</label>
                <input type="text" name="folio" value="<?= e($folio) ?>" readonly>
            </div>

            <div class="form-field">
                <label>📅 Fecha y hora</label>
                <input type="datetime-local" 
                       name="fecha" 
                       id="fechaInput" 
                       required
                       value="<?= $modoEdicion && !empty($salidaEditar['fecha']) 
                                  ? e(date('Y-m-d\TH:i', strtotime($salidaEditar['fecha']))) 
                                  : $fechaActual ?>">
            </div>

            <div class="form-field">
                <label>📋 Tipo de salida</label>
                <select name="tipo_salida" required>
                    <option value="">Seleccione...</option>
                    <?php foreach ($tiposSalida as $tipo): ?>
                        <option value="<?= e($tipo['clave'] . ' - ' . $tipo['descripcion']) ?>"
                            <?= $modoEdicion && (($salidaEditar['referencia'] ?? '') === ($tipo['clave'] . ' - ' . $tipo['descripcion'])) ? 'selected' : '' ?>>
                            <?= e($tipo['clave']) ?> - <?= e($tipo['descripcion']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-field">
                <label>📑 Tipo de documento</label>
                <select name="tipo_operacion" id="tipoOperacionSelect" required>
                    <option value="">Seleccione...</option>
                    <option value="TICKET" <?= $modoEdicion && ($salidaEditar['tipo_operacion'] ?? '') === 'TICKET' ? 'selected' : '' ?>>🎫 Ticket</option>
                    <option value="RESURTIDO" <?= $modoEdicion && ($salidaEditar['tipo_operacion'] ?? '') === 'RESURTIDO' ? 'selected' : '' ?>>🔄 Resurtido</option>
                    <option value="AJUSTE" <?= $modoEdicion && ($salidaEditar['tipo_operacion'] ?? '') === 'AJUSTE' ? 'selected' : '' ?>>⚙️ Ajuste</option>
                    <option value="TRASPASO" <?= $modoEdicion && ($salidaEditar['tipo_operacion'] ?? '') === 'TRASPASO' ? 'selected' : '' ?>>🚚 Traspaso</option>
                    <option value="NOTA_REMISION" <?= $modoEdicion && ($salidaEditar['tipo_operacion'] ?? '') === 'NOTA_REMISION' ? 'selected' : '' ?>>📝 Nota de Remisión</option>
                </select>
            </div>

            <div class="form-field" id="folioOperacionBox" style="display:none;">
                <label id="folioOperacionLabel">🔢 Folio de operación</label>
                <input type="text" name="folio_operacion" id="folioOperacionInput" 
                    placeholder="Ingrese el folio" value="<?= e($folioOperacionEditar) ?>">
            </div>

            <div class="form-field">
                <label>🏪 Almacén</label>
                <select name="almacen_id" required <?= $rolUsuario !== 'ADMINISTRADOR' ? 'disabled' : '' ?>>
                    <option value="">Seleccione...</option>
                    <?php foreach ($almacenes as $almacen): ?>
                        <option value="<?= (int)$almacen['id'] ?>" <?= (int)$almacen['id'] === $almacenSesion ? 'selected' : '' ?>>
                            <?= e($almacen['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($rolUsuario !== 'ADMINISTRADOR'): ?>
                    <input type="hidden" name="almacen_id" value="<?= (int)$almacenSesion ?>">
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label>📝 Observaciones</label>
                <textarea name="observaciones" placeholder="Información adicional..."><?= $modoEdicion ? e($observacionesLimpiasEditar) : '' ?></textarea>
            </div>
        </div>
        
        <div class="folio-previous">
            <span>📋 Último folio registrado: <?= e($folioAnterior ?: 'Sin salidas anteriores') ?></span>
        </div>
    </div>

    <!-- SECCIÓN 2: CAPTURA RÁPIDA -->
    <div class="capture-section">
        <div class="capture-title">Agregar productos</div>
        
        <div class="capture-grid">
            <!-- Campo de producto con botón para abrir modal -->
            <div class="capture-field">
                <label>🔍 Producto</label>
                <div style="display: flex; gap: 8px;">
                    <input type="text" id="productoDisplayInput" placeholder="Presiona Ctrl+B para buscar" readonly style="background: #f8f9fa; cursor: pointer;">
                    <button type="button" id="openModalBtn" style="background: #3b82f6; border: none; padding: 0 20px; border-radius: 12px; color: white; font-weight: 600; cursor: pointer;">🔍 Buscar</button>
                </div>
            </div>

            <!-- Cantidad -->
            <div class="capture-field">
                <label>🔢 Cantidad</label>
                <input type="number" id="cantidadInput" value="1" min="1" step="1">
                <div class="qty-actions">
                    <button type="button" class="qty-btn" onclick="cambiarCantidad(1)">+1</button>
                    <button type="button" class="qty-btn" onclick="cambiarCantidad(5)">+5</button>
                    <button type="button" class="qty-btn" onclick="cambiarCantidad(10)">+10</button>
                    <button type="button" class="qty-btn" onclick="setMaxCantidad()">MAX</button>
                </div>
            </div>

            <!-- Ubicación -->
            <div class="capture-field">
                <label>📍 Ubicación</label>
                <input type="text" id="ubicacionInput" list="ubicacionesList" placeholder="Seleccione ubicación">
                <datalist id="ubicacionesList"></datalist>
            </div>

            <!-- Precio -->
            <div class="capture-field">
                <label>💰 Precio unitario</label>
                <input type="number" id="precioInput" step="0.01" value="0.00">
            </div>

            <!-- Botón agregar -->
            <button type="button" class="btn-add" id="agregarBtn">
                ➕ Agregar producto (Enter)
            </button>
        </div>

        <!-- Info del producto seleccionado -->
        <div id="selectedInfo" style="display: none;">
            <div class="selected-info">
                <div class="row"><span class="label">📦 Producto:</span><span class="value" id="infoCodigo">-</span></div>
                <div class="row"><span class="label">📝 Descripción:</span><span class="value" id="infoDescripcion">-</span></div>
                <div class="row"><span class="label">📏 Unidad:</span><span class="value" id="infoUnidad">-</span></div>
                <div class="row"><span class="label">📊 Stock disponible:</span><span class="value" id="infoStock">-</span></div>
            </div>
        </div>

        <div class="shortcuts-bar">
            🎯 <kbd>Ctrl</kbd> + <kbd>B</kbd> Abrir buscador &nbsp;&nbsp;|&nbsp;&nbsp;
            <kbd>↑</kbd> <kbd>↓</kbd> Navegar resultados en modal &nbsp;&nbsp;|&nbsp;&nbsp;
            <kbd>Enter</kbd> Seleccionar producto &nbsp;&nbsp;|&nbsp;&nbsp;
            <kbd>Enter</kbd> (en cantidad/ubicación/precio) Agregar &nbsp;&nbsp;|&nbsp;&nbsp;
            <kbd>Ctrl</kbd> + <kbd>Enter</kbd> Guardar salida
        </div>
    </div>

    <!-- SECCIÓN 3: TABLA DE PRODUCTOS -->
    <div class="products-section">
        <div class="products-header">
            <h3>Productos agregados</h3>
            <span class="product-badge" id="productosCount">0 productos</span>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Cantidad</th>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th>Unidad</th>
                        <th>Ubicación</th>
                        <th>Precio</th>
                        <th>Importe</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="detalleBody">
                    <?php if ($modoEdicion && !empty($salidaEditar['detalles'])): ?>
                        <?php foreach ($salidaEditar['detalles'] as $item): 
                            $cantidad = (int)($item['cantidad'] ?? 0);
                            $precio = (float)($item['precio_unitario'] ?? 0);
                            $importe = $cantidad * $precio;
                        ?>
                            <tr data-producto-id="<?= (int)($item['producto_id'] ?? 0) ?>">
                                <td>
                                    <input type="number" class="qty-input" value="<?= $cantidad ?>" min="1" onchange="actualizarCantidadFila(this)">
                                    <input type="hidden" name="producto_id[]" value="<?= (int)($item['producto_id'] ?? 0) ?>">
                                    <input type="hidden" name="cantidad[]" value="<?= $cantidad ?>">
                                    <input type="hidden" name="costo_unitario[]" value="<?= (float)($item['costo_unitario'] ?? 0) ?>">
                                    <input type="hidden" name="precio_unitario[]" value="<?= $precio ?>">
                                    <input type="hidden" name="ubicacion[]" value="<?= e($item['ubicacion'] ?? '') ?>">
                                </td>
                                <td><strong><?= e($item['codigo'] ?? '') ?></strong></td>
                                <td><?= e(substr($item['descripcion'] ?? '', 0, 45)) ?></td>
                                <td><?= e($item['unidad_medida'] ?? '') ?></td>
                                <td><?= e($item['ubicacion'] ?? '') ?></td>
                                <td>$<?= number_format($precio, 2) ?></td>
                                <td class="importe-fila" data-importe="<?= $importe ?>">$<?= number_format($importe, 2) ?></td>
                                <td><button type="button" class="delete-btn" onclick="eliminarFila(this)">🗑️</button></td>
                            </table>
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
                <span style="color: #6c7a8a; font-size: 14px;">Total general:</span>
                <span class="total-amount" id="totalSalida">$0.00</span>
            </div>
            <button type="button" class="btn-save" id="guardarBtn">
                💾 Guardar salida (Ctrl+Enter)
            </button>
        </div>
    </div>
</form>
</div>

<!-- MODAL GRANDE ESTILO EXCEL -->
<div class="modal-excel" id="modalExcel">
    <div class="modal-excel-content">
        <div class="modal-excel-header">
            <h3>
                <span>🔍</span> Buscar producto
                <span class="shortcut">Ctrl+B para abrir</span>
            </h3>
            <button class="modal-excel-close" id="closeModalBtn">✕</button>
        </div>
        <div class="modal-excel-search">
            <input type="text" id="modalSearchInput" placeholder="Escribe código o nombre del producto..." autocomplete="off">
        </div>
        <div class="modal-excel-table-container">
            <table class="modal-excel-table" id="modalTable">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th>Unidad</th>
                        <th>Stock disponible</th>
                        <th>Precio</th>
                        <th style="min-width: 250px;">Ubicaciones (Ubicación / Existencia)</th>
                    </tr>
                </thead>
                <tbody id="modalTableBody">
                    <!-- Productos se cargan vía JS -->
                </tbody>
            </table>
        </div>
        <div class="modal-excel-footer">
            <span><kbd>↑</kbd> <kbd>↓</kbd> Navegar &nbsp;&nbsp;|&nbsp;&nbsp; <kbd>Enter</kbd> Seleccionar &nbsp;&nbsp;|&nbsp;&nbsp; <kbd>Esc</kbd> Cerrar</span>
            <span>Mostrando <span id="modalResultCount">0</span> productos</span>
        </div>
    </div>
</div>

<script>
// ===== VARIABLES =====
const productos = <?php 
    $productosArray = [];
foreach ($productos as $p) {
    $existenciaTotal = 0;
    $ubicacionesLista = [];
    
    if (!empty($p['ubicaciones']) && is_array($p['ubicaciones'])) {
        foreach ($p['ubicaciones'] as $ubi) {
            $existenciaTotal += (int)($ubi['existencia_actual'] ?? 0);
            $ubicacionTmp = strtoupper(trim((string)($ubi['ubicacion'] ?? '')));
            if ($ubicacionTmp !== '' && $ubicacionTmp !== 'SIN UBICACION') {
                $ubicacionesLista[] = [
                    'ubicacion' => $ubicacionTmp,
                    'existencia' => (int)($ubi['existencia_actual'] ?? 0)
                ];
            }
        }
    } else {
        $existenciaTotal = (int)($p['existencia_actual'] ?? $p['existencia_bodega'] ?? 0);
        $ubicacionNormal = strtoupper(trim((string)($p['ubicacion'] ?? '')));
        if ($ubicacionNormal !== '' && $ubicacionNormal !== 'SIN UBICACION') {
            $ubicacionesLista[] = [
                'ubicacion' => $ubicacionNormal,
                'existencia' => $existenciaTotal
            ];
        }
    }
    
    // Ordenar ubicaciones por existencia (menor primero)
    usort($ubicacionesLista, function($a, $b) {
        return $a['existencia'] - $b['existencia'];
    });
    
    $ubicacionSugerida = !empty($ubicacionesLista) ? $ubicacionesLista[0]['ubicacion'] : '';
    
    $productosArray[] = [
        'id' => (int)$p['id'],
        'codigo' => $p['codigo'],
        'descripcion' => $p['descripcion'],
        'unidad_medida' => $p['unidad_medida'],
        'precio_compra' => (float)$p['precio_compra'],
        'existencia_total' => $existenciaTotal,
        'ubicacion_sugerida' => $ubicacionSugerida,
        'ubicaciones' => $ubicacionesLista
    ];
}
    echo json_encode($productosArray, JSON_UNESCAPED_UNICODE);
?>;

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
const ubicacionInput = document.getElementById('ubicacionInput');
const detalleBody = document.getElementById('detalleBody');

// ===== FUNCIÓN PARA GENERAR TODAS LAS UBICACIONES =====
function generarTodasLasUbicaciones() {
    const lista = document.getElementById('ubicacionesList');
    if (!lista) return;
    
    const ubicaciones = [];
    
    function add(rack, nivel, zona) {
        const z = String(zona).padStart(2, '0');
        ubicaciones.push(`R${rack}N${nivel}Z${z}`);
    }
    
    for (let n = 1; n <= 3; n++) {
        for (let z = 1; z <= 22; z++) add(1, n, z);
    }
    for (let n = 1; n <= 3; n++) {
        for (let z = 1; z <= 20; z++) add(2, n, z);
    }
    for (let n = 1; n <= 3; n++) {
        for (let z = 1; z <= 20; z++) add(3, n, z);
    }
    for (let n = 1; n <= 2; n++) {
        for (let z = 1; z <= 16; z++) add(4, n, z);
    }
    for (let z = 10; z <= 16; z++) add(4, 3, z);
    for (let n = 1; n <= 2; n++) {
        for (let z = 1; z <= 15; z++) add(5, n, z);
    }
    for (let z = 10; z <= 15; z++) add(5, 3, z);
    for (let n = 1; n <= 3; n++) {
        for (let z = 1; z <= 22; z++) add(6, n, z);
    }
    
    ubicaciones.push('R7N1Z01 - PASILLO 3', 'R8N1Z01 - PASILLO 2', 'R9N1Z01 - PASILLO 1');
    ubicaciones.push('BODEGA PEDYALITE', 'ALMACEN_PRINCIPAL', 'ESTANTE_A1', 'ESTANTE_A2');
    ubicaciones.push('ESTANTE_B1', 'ESTANTE_B2', 'CONGELADOR', 'REFRIGERADOR', 'RECEPCION', 'DEVOLUCIONES');
    
    lista.innerHTML = '';
    ubicaciones.forEach(u => {
        const option = document.createElement('option');
        option.value = u;
        lista.appendChild(option);
    });
}

// ===== FUNCIONES DEL MODAL CORREGIDAS =====
function abrirModal() {
    modal.classList.add('active');
    modalSearch.value = '';
    cargarProductosEnModal(productos);
    modalSearch.focus();
    modalSelectedIndex = -1;
}

function cerrarModal() {
    modal.classList.remove('active');
}

function cargarProductosEnModal(productosList) {
    modalProductosFiltrados = productosList;
    modalResultCount.textContent = productosList.length;
    
    if (productosList.length === 0) {
        modalTableBody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 40px;">No se encontraron productos</td></tr>';
        return;
    }
    
    modalTableBody.innerHTML = productosList.map((p, idx) => {
        let ubicacionesHtml = '';
        if (p.ubicaciones && p.ubicaciones.length > 0) {
            ubicacionesHtml = `
                <div class="ubicaciones-wrapper" style="max-height: 120px; overflow-y: auto; font-size: 11px;">
                    <table class="ubicaciones-interna-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f0f2f5;">
                                <th style="padding: 4px 6px; text-align: left;">Ubicación</th>
                                <th style="padding: 4px 6px; text-align: right;">Existencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${p.ubicaciones.map(u => `
                                <tr style="border-bottom: 1px solid #eef2f6;">
                                    <td style="padding: 4px 6px;">${escapeHtml(u.ubicacion)}</td>
                                    <td style="padding: 4px 6px; text-align: right;">${u.existencia}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
            if (p.ubicaciones.length > 10) {
                ubicacionesHtml += `<div style="font-size: 10px; color: #6c7a8a; padding-top: 4px; text-align: center;">+ ${p.ubicaciones.length - 10} ubicaciones más...</div>`;
            }
        } else {
            ubicacionesHtml = `<div style="color: #9ca3af; text-align: center;">Sin ubicaciones</div>`;
        }
        
        return `
            <tr data-idx="${idx}" data-producto-id="${p.id}" class="producto-principal" style="cursor: pointer;">
                <td class="product-code" style="vertical-align: top;">${escapeHtml(p.codigo)}</td>
                <td style="vertical-align: top;">${escapeHtml(p.descripcion)}</td>
                <td style="vertical-align: top;">${escapeHtml(p.unidad_medida)}</td>
                <td class="product-stock" style="vertical-align: top;">${p.existencia_total}</td>
                <td style="vertical-align: top;">$${p.precio_compra.toFixed(2)}</td>
                <td style="vertical-align: top;" class="ubicaciones-cell">${ubicacionesHtml}</td>
            </tr>
        `;
    }).join('');
    
    // Evento click SOLO para filas de productos
    document.querySelectorAll('#modalTableBody > tr.producto-principal').forEach(row => {
        row.addEventListener('click', (e) => {
            if (e.target.closest('.ubicaciones-interna-table') || e.target.closest('.ubicaciones-wrapper')) {
                e.stopPropagation();
                return;
            }
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

function actualizarSeleccionModal() {
    const filas = document.querySelectorAll('#modalTableBody > tr.producto-principal');
    filas.forEach((fila, idx) => {
        if (idx === modalSelectedIndex) {
            fila.classList.add('selected');
            fila.scrollIntoView({ block: 'nearest' });
        } else {
            fila.classList.remove('selected');
        }
    });
}

function seleccionarProductoDelModal(producto) {
    productoSeleccionado = producto;
    
    document.getElementById('selectedInfo').style.display = 'block';
    document.getElementById('infoCodigo').innerHTML = `<strong>${escapeHtml(producto.codigo)}</strong>`;
    document.getElementById('infoDescripcion').textContent = producto.descripcion;
    document.getElementById('infoUnidad').textContent = producto.unidad_medida;
    document.getElementById('infoStock').textContent = producto.existencia_total;
    
    productoDisplayInput.value = `${producto.codigo} - ${producto.descripcion.substring(0, 50)}`;
    precioInput.value = producto.precio_compra;
    
    if (producto.ubicaciones && producto.ubicaciones.length > 0) {
        ubicacionInput.value = producto.ubicaciones[0].ubicacion;
    } else {
        ubicacionInput.value = producto.ubicacion_sugerida || '';
    }
    
    cerrarModal();
    cantidadInput.focus();
    cantidadInput.select();
    mostrarToast(`✅ Producto seleccionado: ${producto.codigo}`);
}

// ===== FUNCIONES DE CANTIDAD =====
function cambiarCantidad(valor) {
    let nuevo = parseInt(cantidadInput.value) + valor;
    if (nuevo < 1) nuevo = 1;
    if (productoSeleccionado && nuevo > productoSeleccionado.existencia_total) {
        nuevo = productoSeleccionado.existencia_total;
        mostrarToast(`⚠️ Máximo disponible: ${productoSeleccionado.existencia_total}`, 'warning');
    }
    cantidadInput.value = nuevo;
}

function setMaxCantidad() {
    if (productoSeleccionado) {
        cantidadInput.value = productoSeleccionado.existencia_total;
    }
}

// ===== AGREGAR PRODUCTO =====
function agregarProducto() {
    if (!productoSeleccionado) {
        mostrarToast('❌ Selecciona un producto (Ctrl+B para buscar)', 'error');
        return;
    }
    
    const cantidad = parseInt(cantidadInput.value);
    const precio = parseFloat(precioInput.value);
    let ubicacion = ubicacionInput.value.trim().toUpperCase();
    
    if (!ubicacion) ubicacion = productoSeleccionado.ubicacion_sugerida || 'SIN UBICACION';
    
    if (cantidad <= 0) {
        mostrarToast('❌ Cantidad inválida', 'error');
        return;
    }
    
    if (cantidad > productoSeleccionado.existencia_total) {
        mostrarToast(`❌ Stock insuficiente. Disponible: ${productoSeleccionado.existencia_total}`, 'error');
        return;
    }
    
    if (ubicacion === 'SIN UBICACION') {
        mostrarToast('❌ Selecciona una ubicación válida', 'error');
        ubicacionInput.focus();
        return;
    }
    
    const filasExistentes = document.querySelectorAll('#detalleBody tr:not(#filaVacia)');
    for (let fila of filasExistentes) {
        if (fila.dataset.productoId == productoSeleccionado.id) {
            mostrarToast(`⚠️ El producto ya está en la lista`, 'warning');
            return;
        }
    }
    
    const filaVacia = document.getElementById('filaVacia');
    if (filaVacia) filaVacia.remove();
    
    const importe = cantidad * precio;
    const tr = document.createElement('tr');
    tr.dataset.productoId = productoSeleccionado.id;
    tr.innerHTML = `
        <td>
            <input type="number" class="qty-input" value="${cantidad}" min="1" onchange="actualizarCantidadFila(this)">
            <input type="hidden" name="producto_id[]" value="${productoSeleccionado.id}">
            <input type="hidden" name="cantidad[]" value="${cantidad}">
            <input type="hidden" name="costo_unitario[]" value="${productoSeleccionado.precio_compra}">
            <input type="hidden" name="precio_unitario[]" value="${precio}">
            <input type="hidden" name="ubicacion[]" value="${escapeHtml(ubicacion)}">
        </td>
        <td><strong>${escapeHtml(productoSeleccionado.codigo)}</strong></td>
        <td>${escapeHtml(productoSeleccionado.descripcion.substring(0, 45))}</td>
        <td>${escapeHtml(productoSeleccionado.unidad_medida)}</td>
        <td>${escapeHtml(ubicacion)}</td>
        <td>$${precio.toFixed(2)}</td>
        <td class="importe-fila" data-importe="${importe}"><strong>$${importe.toFixed(2)}</strong></td>
        <td><button type="button" class="delete-btn" onclick="eliminarFila(this)">🗑️</button></td>
    `;
    
    detalleBody.appendChild(tr);
    actualizarTotales();
    
    productoSeleccionado = null;
    document.getElementById('selectedInfo').style.display = 'none';
    productoDisplayInput.value = '';
    cantidadInput.value = '1';
    ubicacionInput.value = '';
    precioInput.value = '0.00';
    
    mostrarToast(`✅ ${cantidad} x producto agregado`);
}

function actualizarCantidadFila(input) {
    const tr = input.closest('tr');
    let cantidad = parseInt(input.value);
    if (isNaN(cantidad) || cantidad < 1) cantidad = 1;
    
    const precioTexto = tr.children[5]?.textContent?.replace('$', '') || '0';
    const precio = parseFloat(precioTexto);
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

function eliminarFila(btn) {
    btn.closest('tr').remove();
    if (detalleBody.children.length === 0) {
        detalleBody.innerHTML = `<tr id="filaVacia"><td colspan="8" style="text-align: center; padding: 50px; color: #9ca3af;">📭 No hay productos. Presiona Ctrl+B para buscar</td></tr>`;
    }
    actualizarTotales();
    mostrarToast('🗑️ Producto eliminado', 'info');
}

function limpiarTodo() {
    if (confirm('¿Eliminar todos los productos?')) {
        detalleBody.innerHTML = `<tr id="filaVacia"><td colspan="8" style="text-align: center; padding: 50px; color: #9ca3af;">📭 No hay productos. Presiona Ctrl+B para buscar</td></tr>`;
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
    document.getElementById('totalSalida').textContent = `$${total.toFixed(2)}`;
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

// ===== CONTROL DE FOLIO DE OPERACIÓN =====
const tipoOperacionSelect = document.getElementById('tipoOperacionSelect');
const folioOperacionBox = document.getElementById('folioOperacionBox');
const folioOperacionInput = document.getElementById('folioOperacionInput');
const folioOperacionLabel = document.getElementById('folioOperacionLabel');

function controlarFolioOperacion() {
    const valor = tipoOperacionSelect.value;
    const tiposRequeridos = ['TICKET', 'RESURTIDO', 'NOTA_REMISION'];
    
    if (tiposRequeridos.includes(valor)) {
        folioOperacionBox.style.display = 'block';
        const etiquetas = {
            'TICKET': '🎫 Folio de Ticket',
            'RESURTIDO': '🔄 Folio de Resurtido',
            'NOTA_REMISION': '📝 N# de Nota de Remisión'
        };
        folioOperacionLabel.innerHTML = etiquetas[valor] || '🔢 Folio de operación';
        folioOperacionInput.required = true;
    } else {
        folioOperacionBox.style.display = 'none';
        folioOperacionInput.required = false;
        <?php if (!$modoEdicion): ?>folioOperacionInput.value = '';<?php endif; ?>
    }
}

function ponerFechaActual() {
    const fechaInput = document.getElementById('fechaInput');
    if (fechaInput.value && !<?= $modoEdicion ? 'true' : 'false' ?>) return;
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

function guardarSalida() {
    const productosEnLista = document.querySelectorAll('#detalleBody tr:not(#filaVacia)');
    if (productosEnLista.length === 0) {
        mostrarToast('❌ Agrega al menos un producto', 'error');
        return;
    }
    document.getElementById('formSalida').submit();
}

// ===== EVENTOS GLOBALES =====
document.addEventListener('DOMContentLoaded', () => {
    generarTodasLasUbicaciones();
    actualizarTotales();
    controlarFolioOperacion();
    ponerFechaActual();
    
    // Ctrl+B para abrir modal
    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey && (e.key === 'b' || e.key === 'B')) {
            e.preventDefault();
            e.stopPropagation();
            abrirModal();
        }
    });
    
    // Enter en cantidad, precio o ubicación
    ['cantidadInput', 'precioInput', 'ubicacionInput'].forEach(id => {
        document.getElementById(id)?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                agregarProducto();
            }
        });
    });
    
    document.getElementById('agregarBtn')?.addEventListener('click', agregarProducto);
    document.getElementById('guardarBtn')?.addEventListener('click', guardarSalida);
    document.getElementById('openModalBtn')?.addEventListener('click', abrirModal);
    document.getElementById('closeModalBtn')?.addEventListener('click', cerrarModal);
    
    // Ctrl+Enter para guardar
    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey && e.key === 'Enter') {
            e.preventDefault();
            guardarSalida();
        }
    });
    
    // Cerrar modal con ESC
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('active')) {
            cerrarModal();
        }
    });
    
    modal.addEventListener('click', (e) => {
        if (e.target === modal) cerrarModal();
    });
});

// ===== EVENTOS DE TECLADO PARA EL MODAL (CORREGIDOS) =====
if (modalSearch) {
    modalSearch.addEventListener('keydown', (e) => {
        const filasProductos = document.querySelectorAll('#modalTableBody > tr.producto-principal');
        const totalFilas = filasProductos.length;
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            e.stopPropagation();
            if (totalFilas > 0) {
                modalSelectedIndex = Math.min(modalSelectedIndex + 1, totalFilas - 1);
                actualizarSeleccionModal();
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            e.stopPropagation();
            if (totalFilas > 0) {
                modalSelectedIndex = Math.max(modalSelectedIndex - 1, 0);
                actualizarSeleccionModal();
            }
        } else if (e.key === 'Enter' && modalSelectedIndex >= 0 && modalProductosFiltrados[modalSelectedIndex]) {
            e.preventDefault();
            e.stopPropagation();
            seleccionarProductoDelModal(modalProductosFiltrados[modalSelectedIndex]);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            cerrarModal();
        }
    });
    
    modalSearch.addEventListener('input', filtrarProductosModal);
}

tipoOperacionSelect.addEventListener('change', controlarFolioOperacion);
</script>

<?php
if (file_exists(__DIR__ . '/../app/views/layouts/footer.php')) {
    include __DIR__ . '/../app/views/layouts/footer.php';
}
?>