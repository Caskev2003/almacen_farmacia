<?php

require_once __DIR__ . '/../app/helpers/auth.php';

requireLogin();

date_default_timezone_set('America/Mexico_City');

$user = currentUser();
$rol = strtoupper(trim($user['rol'] ?? ''));

if (!in_array($rol, ['ADMINISTRADOR', 'ENCARGADO'], true)) {
    http_response_code(403);
    exit('Acceso denegado.');
}

// ======================================================
// CONFIGURACIÓN DEL RESPALDO PARA WINDOWS Y XAMPP
// ======================================================

// La carpeta se creará en:
// carpeta-del-proyecto/backups/inventario
$backupDir = dirname(__DIR__)
    . DIRECTORY_SEPARATOR
    . 'backups'
    . DIRECTORY_SEPARATOR
    . 'inventario';

// Ruta de mysqldump de XAMPP.
$mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';

// Configuración de la base de datos.
$dbHost = '127.0.0.1';
$dbPort = '3307';
$dbName = 'almacen_farmacia';
$dbUser = 'root';
$dbPassword = '';

$mensaje = '';
$error = '';

// ======================================================
// CREAR CARPETA DE RESPALDOS
// ======================================================

if (!is_dir($backupDir)) {
    if (!mkdir($backupDir, 0775, true)) {
        die(
            'No se pudo crear la carpeta de respaldos: '
            . htmlspecialchars($backupDir, ENT_QUOTES, 'UTF-8')
        );
    }
}

// ======================================================
// GENERAR TOKEN DE SEGURIDAD
// ======================================================

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_respaldo'])) {
    $_SESSION['csrf_respaldo'] = bin2hex(random_bytes(32));
}

// ======================================================
// GENERAR RESPALDO
// ======================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['generar_backup'])
) {
    $tokenRecibido = $_POST['csrf_token'] ?? '';

    if (
        !is_string($tokenRecibido)
        || !hash_equals($_SESSION['csrf_respaldo'], $tokenRecibido)
    ) {
        $error = 'La solicitud no es válida. Recarga la página e inténtalo nuevamente.';
    } elseif (!file_exists($mysqldump)) {
        $error = 'No se encontró mysqldump.exe en la ruta: ' . $mysqldump;
    } elseif (!is_file($mysqldump)) {
        $error = 'La ruta configurada para mysqldump no es válida.';
    } else {
        $fecha = date('Y-m-d_H-i-s');

        $nombreArchivo =
            'respaldo_' . $dbName . '_' . $fecha . '.sql';

        $rutaArchivo =
            $backupDir
            . DIRECTORY_SEPARATOR
            . $nombreArchivo;

        /*
         * --result-file se utiliza en lugar de > para evitar
         * problemas con rutas y caracteres especiales en Windows.
         */
        $comando =
            '"' . $mysqldump . '"'
            . ' --host=' . escapeshellarg($dbHost)
            . ' --port=' . escapeshellarg($dbPort)
            . ' --user=' . escapeshellarg($dbUser);

        if ($dbPassword !== '') {
            $comando .=
                ' --password=' . escapeshellarg($dbPassword);
        }

        $comando .=
            ' --single-transaction'
            . ' --routines'
            . ' --triggers'
            . ' --events'
            . ' --hex-blob'
            . ' --default-character-set=utf8mb4'
            . ' --databases ' . escapeshellarg($dbName)
            . ' --result-file=' . escapeshellarg($rutaArchivo)
            . ' 2>&1';

        $salida = [];
        $codigo = 0;

        exec($comando, $salida, $codigo);

        if (
            $codigo === 0
            && file_exists($rutaArchivo)
            && filesize($rutaArchivo) > 0
        ) {
            $_SESSION['csrf_respaldo'] = bin2hex(random_bytes(32));

            auditLog([
                'modulo' => 'Respaldos',
                'accion' => 'CREAR_RESPALDO',
                'entidad' => 'archivo_respaldo',
                'registro_id' => $nombreArchivo,
                'descripcion' => 'Generó el respaldo '
                    . $nombreArchivo . ' de la base de datos.',
                'nuevos' => [
                    'archivo' => $nombreArchivo,
                    'tamano_bytes' => filesize($rutaArchivo),
                    'base_datos' => $dbName,
                ],
            ]);

            header('Location: respaldos.php?ok=1');
            exit;
        }

        // Eliminar un respaldo vacío o incompleto.
        if (
            file_exists($rutaArchivo)
            && filesize($rutaArchivo) === 0
        ) {
            unlink($rutaArchivo);
        }

        $detalle = trim(implode(' ', $salida));

        $error = 'No se pudo generar el respaldo.';

        if ($detalle !== '') {
            $error .= ' Detalle: ' . $detalle;
        } elseif ($codigo !== 0) {
            $error .= ' Código de salida: ' . $codigo . '.';
        }

        auditLog([
            'modulo' => 'Respaldos',
            'accion' => 'RESPALDO_FALLIDO',
            'descripcion' => 'Intentó generar un respaldo, pero el proceso falló.',
            'metadata' => [
                'codigo_salida' => $codigo,
                'detalle' => $detalle,
            ],
        ]);
    }
}

// ======================================================
// MENSAJE DE CONFIRMACIÓN
// ======================================================

if (isset($_GET['ok']) && $_GET['ok'] === '1') {
    $mensaje = 'Respaldo generado correctamente.';
}

// ======================================================
// OBTENER RESPALDOS DISPONIBLES
// ======================================================

$archivos = glob(
    $backupDir . DIRECTORY_SEPARATOR . '*.sql'
);

if ($archivos === false) {
    $archivos = [];
}

usort($archivos, function ($a, $b) {
    return filemtime($b) <=> filemtime($a);
});

// ======================================================
// DESCARGAR RESPALDO
// ======================================================

if (isset($_GET['descargar'])) {
    $archivo = basename((string) $_GET['descargar']);

    // Únicamente permite descargar los respaldos SQL generados.
    if (
        !preg_match(
            '/^respaldo_[a-zA-Z0-9_-]+\.sql$/',
            $archivo
        )
    ) {
        http_response_code(400);
        exit('El nombre del archivo no es válido.');
    }

    $rutaArchivo =
        $backupDir
        . DIRECTORY_SEPARATOR
        . $archivo;

    if (!is_file($rutaArchivo)) {
        http_response_code(404);
        exit('El archivo solicitado no existe.');
    }

    if (!is_readable($rutaArchivo)) {
        http_response_code(500);
        exit('No se puede leer el archivo solicitado.');
    }

    auditLog([
        'modulo' => 'Respaldos',
        'accion' => 'DESCARGAR_RESPALDO',
        'entidad' => 'archivo_respaldo',
        'registro_id' => $archivo,
        'descripcion' => 'Descargó el respaldo '
            . $archivo . '.',
        'metadata' => [
            'archivo' => $archivo,
            'tamano_bytes' => filesize($rutaArchivo),
        ],
    ]);

    // Limpiar cualquier contenido previo.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/sql');
    header(
        'Content-Disposition: attachment; filename="' .
        str_replace('"', '', $archivo) .
        '"'
    );
    header('Content-Length: ' . filesize($rutaArchivo));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');

    readfile($rutaArchivo);
    exit;
}

// ======================================================
// FUNCIÓN PARA MOSTRAR EL TAMAÑO
// ======================================================

function formatearTamano(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format(
            $bytes / 1073741824,
            2
        ) . ' GB';
    }

    if ($bytes >= 1048576) {
        return number_format(
            $bytes / 1048576,
            2
        ) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format(
            $bytes / 1024,
            2
        ) . ' KB';
    }

    return $bytes . ' bytes';
}

?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Respaldos de Base de Datos</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px;
            background: #f4f6f9;
            font-family: Arial, sans-serif;
            color: #212529;
        }

        .contenedor {
            width: 100%;
            max-width: 1200px;
            margin: auto;
            padding: 25px;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, .12);
        }

        h1 {
            margin-top: 0;
            margin-bottom: 20px;
            color: #1f5ea8;
        }

        .info {
            margin-bottom: 20px;
            padding: 12px;
            border: 1px solid #cfe2ff;
            border-radius: 8px;
            background: #eef5ff;
            color: #1f5ea8;
            font-weight: bold;
        }

        .ruta-respaldo {
            margin-bottom: 20px;
            padding: 12px;
            border-radius: 8px;
            background: #f8f9fa;
            color: #555;
            font-size: 14px;
            overflow-wrap: anywhere;
        }

        .ruta-respaldo strong {
            color: #333;
        }

        .acciones {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .acciones form {
            margin: 0;
        }

        .btn-generar {
            padding: 12px 18px;
            border: none;
            border-radius: 8px;
            background: #198754;
            color: #ffffff;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            transition:
                background-color .2s ease,
                transform .2s ease;
        }

        .btn-generar:hover {
            background: #146c43;
            transform: translateY(-1px);
        }

        .btn-generar:active {
            transform: translateY(0);
        }

        .alerta-ok,
        .alerta-error {
            margin-bottom: 15px;
            padding: 12px;
            border-radius: 8px;
            font-weight: bold;
            overflow-wrap: anywhere;
        }

        .alerta-ok {
            border: 1px solid #badbcc;
            background: #d1e7dd;
            color: #0f5132;
        }

        .alerta-error {
            border: 1px solid #f5c2c7;
            background: #f8d7da;
            color: #842029;
        }

        .tabla-contenedor {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 700px;
            border-collapse: collapse;
        }

        th {
            padding: 12px;
            background: #1f5ea8;
            color: #ffffff;
            text-align: center;
        }

        th:first-child {
            border-radius: 8px 0 0 0;
        }

        th:last-child {
            border-radius: 0 8px 0 0;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #dddddd;
            text-align: center;
        }

        td:first-child {
            max-width: 400px;
            overflow-wrap: anywhere;
        }

        tbody tr:hover {
            background: #f8f9fa;
        }

        .boton {
            display: inline-block;
            padding: 8px 14px;
            border-radius: 6px;
            background: #1f5ea8;
            color: #ffffff;
            text-decoration: none;
            font-weight: bold;
            transition: background-color .2s ease;
        }

        .boton:hover {
            background: #164579;
        }

        .volver {
            display: inline-block;
            margin-bottom: 15px;
            color: #1f5ea8;
            text-decoration: none;
            font-weight: bold;
        }

        .volver:hover {
            text-decoration: underline;
        }

        .vacio {
            padding: 25px;
            border: 1px dashed #cccccc;
            border-radius: 8px;
            color: #777777;
            text-align: center;
        }

        @media (max-width: 768px) {
            body {
                padding: 15px;
            }

            .contenedor {
                padding: 18px;
            }

            h1 {
                font-size: 24px;
            }

            .btn-generar {
                width: 100%;
            }

            .acciones,
            .acciones form {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<div class="contenedor">

    <a href="dashboard.php" class="volver">
        ← Regresar al inicio
    </a>

    <h1>Respaldos de Base de Datos</h1>

    <div class="info">
        Usuario:
        <?= htmlspecialchars(
            $user['nombre'] ?? '',
            ENT_QUOTES,
            'UTF-8'
        ) ?>

        |

        Rol:
        <?= htmlspecialchars(
            $user['rol'] ?? '',
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </div>

    <div class="ruta-respaldo">
        <strong>Ubicación de los respaldos:</strong>

        <?= htmlspecialchars(
            $backupDir,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </div>

    <?php if ($mensaje !== ''): ?>

        <div class="alerta-ok">
            <?= htmlspecialchars(
                $mensaje,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>

    <?php endif; ?>

    <?php if ($error !== ''): ?>

        <div class="alerta-error">
            <?= htmlspecialchars(
                $error,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>

    <?php endif; ?>

    <div class="acciones">

        <form method="POST" action="respaldos.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $_SESSION['csrf_respaldo'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            >

            <button
                type="submit"
                name="generar_backup"
                value="1"
                class="btn-generar"
                onclick="return confirm(
                    '¿Deseas generar un respaldo de la base de datos ahora?'
                );"
            >
                Generar respaldo ahora
            </button>

        </form>

    </div>

    <?php if (empty($archivos)): ?>

        <div class="vacio">
            No existen respaldos disponibles.
        </div>

    <?php else: ?>

        <div class="tabla-contenedor">

            <table>

                <thead>
                    <tr>
                        <th>Archivo</th>
                        <th>Fecha</th>
                        <th>Tamaño</th>
                        <th>Acción</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($archivos as $ruta): ?>

                    <?php
                    $nombre = basename($ruta);

                    $fechaArchivo = date(
                        'd/m/Y H:i:s',
                        filemtime($ruta)
                    );

                    $tamano = formatearTamano(
                        filesize($ruta)
                    );
                    ?>

                    <tr>

                        <td>
                            <?= htmlspecialchars(
                                $nombre,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                $fechaArchivo,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                $tamano,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </td>

                        <td>
                            <a
                                class="boton"
                                href="?descargar=<?= urlencode($nombre) ?>"
                            >
                                Descargar
                            </a>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

</div>

</body>
</html>
