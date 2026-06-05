<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/config/database.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

requireLogin();

$db = new Database();
$conn = $db->connect();

$mensaje = "";
$errores = 0;

function limpiarTexto($valor): string
{
    return trim((string)($valor ?? ''));
}

function limpiarCodigo($valor): string
{
    $valor = trim((string)($valor ?? ''));
    $valor = str_replace(["\n", "\r", "\t", " "], '', $valor);

    if (str_contains($valor, '.')) {
        $valor = rtrim(rtrim($valor, '0'), '.');
    }

    return $valor;
}

function codigoValido(string $codigo): bool
{
    return $codigo !== ''
        && preg_match('/[1-9]/', $codigo)
        && strlen($codigo) >= 3;
}

function normalizarEncabezado(string $texto): string
{
    $texto = strtolower(trim($texto));

    $texto = str_replace(
        [' ', '_', '-', '.', '/', '\\'],
        '',
        $texto
    );

    $texto = str_replace(
        ['á', 'é', 'í', 'ó', 'ú', 'ñ'],
        ['a', 'e', 'i', 'o', 'u', 'n'],
        $texto
    );

    return $texto;
}

function valorFila(array $fila, ?string $columna): string
{
    return $columna ? limpiarTexto($fila[$columna] ?? '') : '';
}

function codigoFila(array $fila, ?string $columna): string
{
    return $columna ? limpiarCodigo($fila[$columna] ?? '') : '';
}

function campoVacio($valor): bool
{
    $valor = trim((string)($valor ?? ''));
    return $valor === '' || $valor === '-';
}

function numeroExcel($valor): float
{
    $valor = trim((string)($valor ?? '0'));
    $valor = str_replace([',', '$'], '', $valor);

    if ($valor === '' || !is_numeric($valor)) {
        return 0;
    }

    return (float)$valor;
}

function catalogoCerrado(PDO $conn): bool
{
    $stmt = $conn->query("
        SELECT catalogo_cerrado
        FROM configuracion_importacion
        WHERE id = 1
        LIMIT 1
    ");

    return (int)($stmt->fetchColumn() ?: 0) === 1;
}

function totalProductos(PDO $conn): int
{
    $stmt = $conn->query("
        SELECT COUNT(*)
        FROM productos
        WHERE estado = 1
    ");

    return (int)$stmt->fetchColumn();
}

function obtenerCategoriaId(PDO $conn, string $nombre): ?int
{
    $nombre = trim($nombre);

    if ($nombre === '' || $nombre === '-') {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT id
        FROM categorias
        WHERE nombre = ?
        LIMIT 1
    ");

    $stmt->execute([$nombre]);
    $categoria = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($categoria) {
        return (int)$categoria['id'];
    }

    $stmtInsert = $conn->prepare("
        INSERT INTO categorias (nombre, estado)
        VALUES (?, 1)
    ");

    $stmtInsert->execute([$nombre]);

    return (int)$conn->lastInsertId();
}

function buscarProductoPorCodigo(PDO $conn, string $codigo): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM productos
        WHERE codigo = ?
           OR codigo_barras = ?
        LIMIT 1
    ");

    $stmt->execute([$codigo, $codigo]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);

    return $producto ?: null;
}

// ===== NUEVA FUNCIÓN: Actualizar ubicación y existencia =====
function actualizarUbicacionExistencia(PDO $conn, int $productoId, string $sucursal, string $ubicacion, int $existencia): bool
{
    $sucursal = strtoupper(trim($sucursal));
    $ubicacion = strtoupper(trim($ubicacion));
    
    if ($ubicacion === '' || $ubicacion === '-' || $ubicacion === 'SIN UBICACION' || $ubicacion === 'SINUBICACION') {
        $ubicacion = 'SIN UBICACION';
    }
    
    // Verificar si ya existe el registro
    $stmtCheck = $conn->prepare("
        SELECT id, existencia
        FROM producto_existencias
        WHERE producto_id = ?
        AND UPPER(sucursal) = UPPER(?)
        AND UPPER(ubicacion) = UPPER(?)
    ");
    
    $stmtCheck->execute([$productoId, $sucursal, $ubicacion]);
    $existe = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if ($existe) {
        // Actualizar existencia
        $stmtUpdate = $conn->prepare("
            UPDATE producto_existencias
            SET existencia = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        return $stmtUpdate->execute([$existencia, $existe['id']]);
    } else {
        // Insertar nuevo
        $stmtInsert = $conn->prepare("
            INSERT INTO producto_existencias (
                producto_id,
                sucursal,
                ubicacion,
                existencia,
                created_at,
                updated_at
            ) VALUES (
                ?, ?, ?, ?,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
        ");
        return $stmtInsert->execute([$productoId, $sucursal, $ubicacion, $existencia]);
    }
}

// ===== NUEVA FUNCIÓN: Actualizar ubicación principal del producto =====
function actualizarUbicacionPrincipal(PDO $conn, int $productoId, string $sucursal): void
{
    $stmt = $conn->prepare("
        SELECT ubicacion
        FROM producto_existencias
        WHERE producto_id = ?
        AND UPPER(sucursal) = UPPER(?)
        AND existencia > 0
        ORDER BY existencia DESC, ubicacion ASC
        LIMIT 1
    ");
    
    $stmt->execute([$productoId, $sucursal]);
    $ubicacion = $stmt->fetchColumn();
    
    if ($ubicacion) {
        $stmtUpdate = $conn->prepare("
            UPDATE productos
            SET ubicacion = ?
            WHERE id = ?
        ");
        $stmtUpdate->execute([$ubicacion, $productoId]);
    }
}

// ===== NUEVA FUNCIÓN: Reactivar un producto si estaba inactivo =====
function reactivarProducto(PDO $conn, int $productoId): void
{
    $stmt = $conn->prepare("
        UPDATE productos
        SET estado = 1
        WHERE id = ? AND estado = 0
    ");
    $stmt->execute([$productoId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $tipoImportacion = limpiarTexto($_POST['tipo_importacion'] ?? '');
    $sucursal = limpiarTexto($_POST['sucursal'] ?? '');

    if ($tipoImportacion === '') {

        $mensaje = "Debes seleccionar el tipo de importación.";

    } elseif ($tipoImportacion === 'existencia' && $sucursal === '') {

        $mensaje = "Debes seleccionar una sucursal para importar existencias.";

    } elseif (empty($_FILES['excel']['tmp_name'])) {

        $mensaje = "Debes seleccionar un archivo Excel.";

    } else {

        try {

            $spreadsheet = IOFactory::load($_FILES['excel']['tmp_name']);
            $hoja = $spreadsheet->getActiveSheet();
            $filas = $hoja->toArray(null, true, true, true);

            if (count($filas) < 2) {
                throw new Exception("El Excel no contiene datos suficientes.");
            }

            $filaEncabezado = array_shift($filas);
            $encabezados = [];

            foreach ($filaEncabezado as $columna => $valor) {
                $encabezados[normalizarEncabezado((string)$valor)] = $columna;
            }

            $insertados = 0;
            $actualizados = 0;
            $omitidos = 0;
            $noEncontrados = 0;
            $reactivados = 0;
            $detalleErrores = [];

            if ($tipoImportacion === 'informacion') {

                $productosActuales = totalProductos($conn);
                $catalogoCerrado = catalogoCerrado($conn);

                $colCodigoBarras = $encabezados['codigobarras'] ?? null;
                $colNombre = $encabezados['nombre'] ?? null;
                $colMarca = $encabezados['descripcioncategoria'] ?? null;
                $colCategoria = $encabezados['descripcionsubfamilia'] ?? null;
                $colUbicacion = $encabezados['ubicacion'] ?? null;

                $colPrecioCompra =
                    $encabezados['preciocompra'] ??
                    $encabezados['costo'] ??
                    $encabezados['ultimocosto'] ??
                    null;

                $colPrecioVenta =
                    $encabezados['precioventa'] ??
                    $encabezados['precio'] ??
                    $encabezados['preciopublico'] ??
                    null;

                if (!$colCodigoBarras || !$colNombre) {
                    throw new Exception("No se encontraron las columnas obligatorias: CodigoBarras y Nombre.");
                }

                foreach ($filas as $numeroFila => $fila) {

                    $codigo = codigoFila($fila, $colCodigoBarras);
                    $codigoBarras = $codigo;
                    $descripcion = valorFila($fila, $colNombre);
                    $marca = valorFila($fila, $colMarca);
                    $categoriaNombre = valorFila($fila, $colCategoria);
                    $ubicacion = valorFila($fila, $colUbicacion);
                    $precioCompra = numeroExcel(valorFila($fila, $colPrecioCompra));
                    $precioVenta = numeroExcel(valorFila($fila, $colPrecioVenta));

                    if (!codigoValido($codigo) || campoVacio($descripcion)) {
                        $omitidos++;
                        continue;
                    }

                    try {

                        $producto = buscarProductoPorCodigo($conn, $codigo);

                        if ($producto) {

                            $categoriaId = null;

                            if (!campoVacio($categoriaNombre) && empty($producto['categoria_id'])) {
                                $categoriaId = obtenerCategoriaId($conn, $categoriaNombre);
                            }

                            $nuevoCodigo = campoVacio($producto['codigo']) ? $codigo : $producto['codigo'];
                            $nuevoCodigoBarras = campoVacio($producto['codigo_barras']) ? $codigoBarras : $producto['codigo_barras'];
                            $nuevaDescripcion = campoVacio($producto['descripcion']) ? $descripcion : $producto['descripcion'];
                            $nuevoLaboratorio = campoVacio($producto['laboratorio']) ? $marca : $producto['laboratorio'];

                            $nuevaCategoriaId = !empty($producto['categoria_id'])
                                ? $producto['categoria_id']
                                : $categoriaId;

                            $nuevoPrecioCompra = ((float)$producto['precio_compra'] <= 0 && $precioCompra > 0)
                                ? $precioCompra
                                : $producto['precio_compra'];

                            $nuevoPrecioVenta = ((float)$producto['precio_venta'] <= 0 && $precioVenta > 0)
                                ? $precioVenta
                                : $producto['precio_venta'];

                            $stmtUpdate = $conn->prepare("
                                UPDATE productos
                                SET
                                    codigo = ?,
                                    codigo_barras = ?,
                                    descripcion = ?,
                                    categoria_id = ?,
                                    laboratorio = ?,
                                    precio_compra = ?,
                                    precio_venta = ?,
                                    estado = 1,
                                    updated_at = CURRENT_TIMESTAMP
                                WHERE id = ?
                            ");

                            $stmtUpdate->execute([
                                $nuevoCodigo,
                                $nuevoCodigoBarras,
                                $nuevaDescripcion,
                                $nuevaCategoriaId,
                                campoVacio($nuevoLaboratorio) ? null : $nuevoLaboratorio,
                                $nuevoPrecioCompra,
                                $nuevoPrecioVenta,
                                $producto['id']
                            ]);
                            
                            // Si el producto estaba inactivo, reactivar
                            if ((int)($producto['estado'] ?? 0) === 0) {
                                $reactivados++;
                            }

                            $actualizados++;

                        } else {

                            if ($catalogoCerrado) {
                                $noEncontrados++;
                                continue;
                            }

                            $categoriaId = obtenerCategoriaId($conn, $categoriaNombre);

                            $stmtInsert = $conn->prepare("
                                INSERT INTO productos (
                                    codigo,
                                    codigo_barras,
                                    descripcion,
                                    categoria_id,
                                    proveedor_id,
                                    laboratorio,
                                    unidad_medida,
                                    precio_compra,
                                    precio_venta,
                                    stock_minimo,
                                    stock_maximo,
                                    ubicacion,
                                    existencia_bodega,
                                    sucursal,
                                    estado,
                                    created_at,
                                    updated_at
                                ) VALUES (
                                    ?, ?, ?, ?, NULL,
                                    ?, NULL, ?, ?,
                                    0, 0, ?,
                                    0, NULL, 1,
                                    CURRENT_TIMESTAMP,
                                    CURRENT_TIMESTAMP
                                )
                            ");

                            $stmtInsert->execute([
                                $codigo,
                                $codigoBarras,
                                $descripcion,
                                $categoriaId,
                                campoVacio($marca) ? null : $marca,
                                $precioCompra,
                                $precioVenta,
                                $ubicacion ?: null
                            ]);

                            $insertados++;
                        }

                    } catch (Exception $e) {

                        $errores++;

                        if (count($detalleErrores) < 10) {
                            $detalleErrores[] = "Fila {$numeroFila}: " . $e->getMessage();
                        }
                    }
                }

                $mensaje = "
                    Importación de <strong>INFORMACIÓN</strong> completada.<br><br>
                    Productos antes de importar: <strong>{$productosActuales}</strong><br>
                    Insertados (nuevos): <strong>{$insertados}</strong><br>
                    Actualizados / Reactivados: <strong>{$actualizados}</strong><br>
                    Reactivados: <strong>{$reactivados}</strong><br>
                    No encontrados: <strong>{$noEncontrados}</strong><br>
                    Omitidos: <strong>{$omitidos}</strong><br>
                    Errores: <strong>{$errores}</strong>
                ";

                if ($catalogoCerrado) {
                    $mensaje .= "<br><br><strong>Catálogo cerrado:</strong> los códigos nuevos no fueron insertados.";
                } else {
                    $mensaje .= "<br><br><strong>Catálogo abierto:</strong> los códigos nuevos sí fueron insertados.";
                }

            } elseif ($tipoImportacion === 'existencia') {

                $colCodigoBarras = $encabezados['codigobarras'] ?? null;
                $colExistencia = $encabezados['existencia'] ?? null;
                $colUbicacion = $encabezados['ubicacion'] ?? null;

                if (!$colCodigoBarras || !$colExistencia) {
                    throw new Exception("No se encontraron las columnas obligatorias: CodigoBarras y Existencia.");
                }

                foreach ($filas as $numeroFila => $fila) {

                    $codigo = codigoFila($fila, $colCodigoBarras);
                    $existenciaRaw = valorFila($fila, $colExistencia);
                    $ubicacion = $colUbicacion ? valorFila($fila, $colUbicacion) : '';

                    if (!codigoValido($codigo)) {
                        $omitidos++;
                        continue;
                    }

                    $existencia = (int)str_replace(',', '', $existenciaRaw);

                    if ($existencia < 0) {
                        $existencia = 0;
                    }

                    // Limpiar ubicación
                    $ubicacion = strtoupper(trim($ubicacion));
                    if ($ubicacion === '' || $ubicacion === '-' || $ubicacion === 'SIN UBICACION' || $ubicacion === 'SINUBICACION') {
                        $ubicacion = 'SIN UBICACION';
                    }

                    try {

                        $producto = buscarProductoPorCodigo($conn, $codigo);

                        if (!$producto) {
                            $noEncontrados++;
                            continue;
                        }

                        // Reactivar producto si estaba inactivo
                        reactivarProducto($conn, (int)$producto['id']);

                        // Actualizar ubicación y existencia
                        actualizarUbicacionExistencia(
                            $conn,
                            (int)$producto['id'],
                            $sucursal,
                            $ubicacion,
                            $existencia
                        );

                        // Actualizar ubicación principal del producto (la que tiene más stock)
                        actualizarUbicacionPrincipal($conn, (int)$producto['id'], $sucursal);

                        $actualizados++;

                    } catch (Exception $e) {

                        $errores++;

                        if (count($detalleErrores) < 10) {
                            $detalleErrores[] = "Fila {$numeroFila}: " . $e->getMessage();
                        }
                    }
                }

                $mensaje = "
                    Importación de <strong>EXISTENCIAS</strong> completada para <strong>{$sucursal}</strong>.<br><br>
                    Existencias actualizadas / insertadas: <strong>{$actualizados}</strong><br>
                    Productos no encontrados en catálogo: <strong>{$noEncontrados}</strong><br>
                    Omitidos: <strong>{$omitidos}</strong><br>
                    Errores: <strong>{$errores}</strong>
                ";

            } else {

                throw new Exception("Tipo de importación no válido.");
            }

            if (!empty($detalleErrores)) {
                $mensaje .= "<br><br><strong>Primeros errores:</strong><br>";
                $mensaje .= implode("<br>", $detalleErrores);
            }

        } catch (Exception $e) {

            $mensaje = "Error: " . $e->getMessage();
            $errores++;
        }
    }
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Importar Productos</title>
    <link rel="stylesheet" href="assets/css/importar_productos.css">
    <style>
        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
            padding: 15px;
            border-radius: 12px;
            margin-top: 20px;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
            padding: 15px;
            border-radius: 12px;
            margin-top: 20px;
        }
        
        .import-container {
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
        }
        
        .import-card {
            background: white;
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        }
        
        .import-title {
            font-size: 24px;
            font-weight: 700;
            color: #1a2c3e;
            margin-bottom: 8px;
        }
        
        .import-subtitle {
            color: #64748b;
            margin-bottom: 24px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #334155;
        }
        
        .form-control {
            width: 100%;
            padding: 12px;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }
        
        .btn-importar {
            background: #3b82f6;
            color: white;
            padding: 12px 24px;
            border-radius: 40px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            width: 100%;
            font-size: 16px;
        }
        
        .btn-importar:hover {
            background: #2563eb;
        }
        
        .btn-volver {
            display: inline-block;
            margin-top: 20px;
            color: #64748b;
            text-decoration: none;
        }
        
        .btn-volver:hover {
            color: #3b82f6;
        }
        
        small {
            color: #64748b;
            font-size: 12px;
        }
    </style>
</head>

<body>

<div class="import-container">
    <div class="import-card">

        <h1 class="import-title">Importar Productos</h1>

        <p class="import-subtitle">Restaura productos desde Excel (incluye aquellos con stock 0)</p>

        <form method="POST" enctype="multipart/form-data">

            <div class="form-group">
                <label>Tipo de importación</label>

                <select name="tipo_importacion" id="tipo_importacion" class="form-control" required>
                    <option value="">Seleccione una opción</option>
                    <option value="informacion">Información de productos (catálogo)</option>
                    <option value="existencia">Existencias por almacén (incluye ubicación)</option>
                </select>
            </div>

            <div class="form-group">
                <label>Sucursal / Almacén</label>

                <select name="sucursal" id="sucursal" class="form-control" required>
                    <option value="">Seleccione una opción</option>
                </select>

                <small id="ayudaSucursal" style="display:block; margin-top:6px;">
                    Selecciona primero el tipo de importación.
                </small>
            </div>

            <div class="form-group">
                <label>Archivo Excel</label>

                <input
                    type="file"
                    name="excel"
                    class="form-control"
                    accept=".xlsx,.xls"
                    required
                >
                <small>Debe contener columnas: Código, Descripción, Existencia, Ubicación (opcional)</small>
            </div>

            <button type="submit" class="btn-importar">
                Importar Excel
            </button>

        </form>

        <?php if (!empty($mensaje)): ?>
            <div class="<?= $errores > 0 ? 'alert-error' : 'alert-success' ?>">
                <?= $mensaje ?>
            </div>
        <?php endif; ?>

        <a href="productos.php" class="btn-volver">
            ← Volver a productos
        </a>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tipo = document.getElementById('tipo_importacion');
    const sucursal = document.getElementById('sucursal');
    const ayuda = document.getElementById('ayudaSucursal');

    function limpiarOpciones() {
        sucursal.innerHTML = '';
    }

    function agregarOpcion(valor, texto, selected = false) {
        const option = document.createElement('option');
        option.value = valor;
        option.textContent = texto;

        if (selected) {
            option.selected = true;
        }

        sucursal.appendChild(option);
    }

    function actualizarSucursal() {
        limpiarOpciones();

        if (tipo.value === 'informacion') {
            agregarOpcion('ADMINISTRADOR', 'ADMINISTRADOR (Catálogo General)', true);
            sucursal.disabled = false;
            ayuda.textContent = 'La información de productos se carga como catálogo general (sin ubicaciones específicas).';
            return;
        }

        if (tipo.value === 'existencia') {
            agregarOpcion('', 'Seleccione una opción');
            agregarOpcion('CIUDAD HIDALGO', '🏪 CIUDAD HIDALGO');
            agregarOpcion('TUXTLA', '🏪 TUXTLA');
            sucursal.disabled = false;
            ayuda.textContent = 'Las existencias se cargarán con su ubicación específica. Los productos con stock 0 se restaurarán.';
            return;
        }

        agregarOpcion('', 'Seleccione una opción');
        ayuda.textContent = 'Selecciona primero el tipo de importación.';
    }

    tipo.addEventListener('change', actualizarSucursal);
    actualizarSucursal();
});
</script>

</body>
</html>