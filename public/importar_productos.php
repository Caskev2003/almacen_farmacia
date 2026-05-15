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

function actualizarExistencia(PDO $conn, int $productoId, string $sucursal, int $existencia): bool
{
    $stmt = $conn->prepare("
        INSERT INTO producto_existencias (
            producto_id,
            sucursal,
            existencia
        ) VALUES (
            ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            existencia = VALUES(existencia),
            updated_at = CURRENT_TIMESTAMP
    ");

    return $stmt->execute([
        $productoId,
        $sucursal,
        $existencia
    ]);
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
            $detalleErrores = [];

            if ($tipoImportacion === 'informacion') {

                $productosActuales = totalProductos($conn);
                $catalogoCerrado = catalogoCerrado($conn);

                $colCodigoBarras = $encabezados['codigobarras'] ?? null;
                $colNombre = $encabezados['nombre'] ?? null;
                $colMarca = $encabezados['descripcioncategoria'] ?? null;
                $colCategoria = $encabezados['descripcionsubfamilia'] ?? null;

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
                                    estado = 1
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
                                    estado
                                ) VALUES (
                                    ?, ?, ?, ?, NULL,
                                    ?, NULL, ?, ?,
                                    0, 0, NULL,
                                    0, NULL, 1
                                )
                            ");

                            $stmtInsert->execute([
                                $codigo,
                                $codigoBarras,
                                $descripcion,
                                $categoriaId,
                                campoVacio($marca) ? null : $marca,
                                $precioCompra,
                                $precioVenta
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
                    Insertados: <strong>{$insertados}</strong><br>
                    Actualizados / comparados: <strong>{$actualizados}</strong><br>
                    No encontrados y NO insertados: <strong>{$noEncontrados}</strong><br>
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

                if (!$colCodigoBarras || !$colExistencia) {
                    throw new Exception("No se encontraron las columnas obligatorias: CodigoBarras y Existencia.");
                }

                foreach ($filas as $numeroFila => $fila) {

                    $codigo = codigoFila($fila, $colCodigoBarras);
                    $existenciaRaw = valorFila($fila, $colExistencia);

                    if (!codigoValido($codigo)) {
                        $omitidos++;
                        continue;
                    }

                    $existencia = (int)str_replace(',', '', $existenciaRaw);

                    if ($existencia < 0) {
                        $existencia = 0;
                    }

                    try {

                        $producto = buscarProductoPorCodigo($conn, $codigo);

                        if (!$producto) {
                            $noEncontrados++;
                            continue;
                        }

                        actualizarExistencia(
                            $conn,
                            (int)$producto['id'],
                            $sucursal,
                            $existencia
                        );

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
                    Existencias actualizadas: <strong>{$actualizados}</strong><br>
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
</head>

<body>

<div class="import-container">
    <div class="import-card">

        <h1 class="import-title">Importar Productos</h1>

        <p class="import-subtitle">Importación masiva desde Excel</p>

        <form method="POST" enctype="multipart/form-data">

            <div class="form-group">
                <label>Tipo de importación</label>

                <select name="tipo_importacion" id="tipo_importacion" class="form-control" required>
                    <option value="">Seleccione una opción</option>
                    <option value="informacion">Información de productos</option>
                    <option value="existencia">Existencias por almacén</option>
                </select>
            </div>

            <div class="form-group">
                <label>Sucursal / Almacén</label>

                <select name="sucursal" id="sucursal" class="form-control" required>
                    <option value="">Seleccione una opción</option>
                </select>

                <small id="ayudaSucursal" style="display:block; margin-top:6px; color:#64748b;">
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
            </div>

            <button type="submit" class="btn-importar">
                Importar Excel
            </button>

        </form>

        <?php if (!empty($mensaje)): ?>
            <div class="alert <?= $errores > 0 ? 'alert-error' : 'alert-success' ?>">
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
            agregarOpcion('ADMINISTRADOR', 'ADMINISTRADOR', true);
            sucursal.disabled = false;
            ayuda.textContent = 'La información de productos se carga como catálogo general.';
            return;
        }

        if (tipo.value === 'existencia') {
            agregarOpcion('', 'Seleccione una opción');
            agregarOpcion('CIUDAD HIDALGO', 'CIUDAD HIDALGO');
            agregarOpcion('TUXTLA', 'TUXTLA');
            sucursal.disabled = false;
            ayuda.textContent = 'Las existencias sí se cargan por almacén.';
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