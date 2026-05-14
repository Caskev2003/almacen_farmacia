<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$db = new Database();
$conn = $db->connect();

$mensaje = "";

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
    if ($codigo === '') {
        return false;
    }

    if (!preg_match('/[1-9]/', $codigo)) {
        return false;
    }

    if (strlen($codigo) < 5) {
        return false;
    }

    return true;
}

function limpiarEntero($valor): int
{
    $valor = trim((string)($valor ?? ''));

    if ($valor === '' || $valor === '-') {
        return 0;
    }

    $valor = str_replace(['$', ',', ' '], ['', '', ''], $valor);

    if (!is_numeric($valor)) {
        return 0;
    }

    return (int) round((float)$valor);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $sucursal = limpiarTexto($_POST['sucursal'] ?? '');

    if ($sucursal === '') {
        $mensaje = "Debes seleccionar una sucursal.";
    } elseif (!empty($_FILES['excel']['tmp_name'])) {

        $archivo = $_FILES['excel']['tmp_name'];

        try {
            $spreadsheet = IOFactory::load($archivo);
            $hoja = $spreadsheet->getActiveSheet();
            $filas = $hoja->toArray(null, true, true, true);

            if (count($filas) < 1) {
                throw new Exception("El archivo Excel no contiene registros.");
            }

            $colCodigo = 'A';
            $colCodigoBarras = 'B';
            $colDescripcion = 'C';
            $colExistencia = 'D';

            $insertados = 0;
            $actualizados = 0;
            $omitidos = 0;
            $errores = 0;

            foreach ($filas as $numeroFila => $fila) {

                $codigo = limpiarCodigo($fila[$colCodigo] ?? '');
                $codigo_barras = limpiarCodigo($fila[$colCodigoBarras] ?? '');
                $descripcion = limpiarTexto($fila[$colDescripcion] ?? '');
                $existenciaTotalExcel = limpiarEntero($fila[$colExistencia] ?? 0);

                if (!codigoValido($codigo) || $descripcion === '') {
                    $omitidos++;
                    continue;
                }

                if ($codigo_barras === '') {
                    $codigo_barras = $codigo;
                }

                try {
                    $stmtExiste = $conn->prepare("
                        SELECT 
                            id,
                            existencia_bodega
                        FROM productos
                        WHERE codigo = ?
                        AND sucursal = ?
                        LIMIT 1
                    ");

                    $stmtExiste->execute([$codigo, $sucursal]);
                    $productoExistente = $stmtExiste->fetch(PDO::FETCH_ASSOC);

                    if ($productoExistente) {

                        $existenciaBodegaActual = (int)($productoExistente['existencia_bodega'] ?? 0);
                        $existenciaFarmaciaCalculada = max(0, $existenciaTotalExcel - $existenciaBodegaActual);

                        $stmtUpdate = $conn->prepare("
                            UPDATE productos
                            SET 
                                codigo_barras = ?,
                                descripcion = ?,
                                existencia_actual = ?,
                                existencia_farmacia = ?
                            WHERE id = ?
                        ");

                        $stmtUpdate->execute([
                            $codigo_barras,
                            $descripcion,
                            $existenciaTotalExcel,
                            $existenciaFarmaciaCalculada,
                            $productoExistente['id']
                        ]);

                        $actualizados++;
                        continue;
                    }

                    $existenciaBodega = 0;
                    $existenciaFarmacia = $existenciaTotalExcel;

                    $stmtProducto = $conn->prepare("
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
                            existencia_actual,
                            existencia_bodega,
                            existencia_farmacia,
                            sucursal,
                            estado
                        ) VALUES (
                            ?, ?, ?, NULL, NULL, NULL, NULL, 0, 0, 0, 0, NULL, ?, ?, ?, ?, 1
                        )
                    ");

                    $stmtProducto->execute([
                        $codigo,
                        $codigo_barras,
                        $descripcion,
                        $existenciaTotalExcel,
                        $existenciaBodega,
                        $existenciaFarmacia,
                        $sucursal
                    ]);

                    $insertados++;

                } catch (Exception $e) {
                    $errores++;
                    continue;
                }
            }

            $mensaje = "Importación completada para $sucursal. Insertados: $insertados | Actualizados: $actualizados | Filas omitidas: $omitidos | Errores: $errores";

        } catch (Exception $e) {
            $mensaje = "Error: " . $e->getMessage();
        }

    } else {
        $mensaje = "Debes seleccionar un archivo Excel.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Importar Productos</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f6fa;
            padding: 40px;
        }

        .card {
            background: white;
            max-width: 650px;
            margin: auto;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 0 12px rgba(0,0,0,.12);
        }

        h2 {
            margin-bottom: 10px;
            color: #0f3b66;
        }

        p {
            color: #555;
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
            color: #334155;
        }

        select,
        input[type=file] {
            width: 100%;
            padding: 10px;
            margin-bottom: 18px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
        }

        button {
            background: #00529b;
            color: white;
            border: none;
            padding: 12px 22px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
        }

        button:hover {
            background: #003f78;
        }

        .mensaje {
            margin-top: 20px;
            padding: 15px;
            background: #d1e7dd;
            border-radius: 6px;
            color: #0f5132;
            font-weight: bold;
        }

        .volver {
            display: inline-block;
            margin-top: 18px;
            text-decoration: none;
            color: #00529b;
            font-weight: bold;
        }
    </style>
</head>
<body>

<div class="card">

    <h2>Importar Productos desde Excel</h2>

    <p>
        El Excel debe venir sin encabezados y con este orden:
        <br>
        <strong>A:</strong> Articulo / Código,
        <strong>B:</strong> Código de barras,
        <strong>C:</strong> Nombre,
        <strong>D:</strong> Existencia total.
    </p>

    <form method="POST" enctype="multipart/form-data">

        <label>Sucursal / Almacén</label>
        <select name="sucursal" required>
            <option value="">Seleccione una opción</option>
            <option value="TUXTLA">TUXTLA</option>
            <option value="CIUDAD HIDALGO">CIUDAD HIDALGO</option>
        </select>

        <label>Archivo Excel</label>
        <input type="file" name="excel" accept=".xlsx,.xls" required>

        <button type="submit">
            Importar Excel
        </button>

    </form>

    <?php if ($mensaje): ?>
        <div class="mensaje">
            <?= htmlspecialchars($mensaje) ?>
        </div>
    <?php endif; ?>

    <a class="volver" href="productos.php">
        ← Volver a productos
    </a>

</div>

</body>
</html>