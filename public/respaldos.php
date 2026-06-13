<?php
require_once __DIR__ . '/../app/helpers/auth.php';

requireLogin();

$user = currentUser();

$rol = strtoupper(trim($user['rol'] ?? ''));

if (!in_array($rol, ['ADMINISTRADOR', 'ENCARGADO'], true)) {
    http_response_code(403);
    exit('Acceso denegado.');
}

$backupDir = '/backups/inventario';

if (!is_dir($backupDir)) {
    die('La carpeta de respaldos no existe.');
}

$archivos = glob($backupDir . '/*.sql*');

usort($archivos, function ($a, $b) {
    return filemtime($b) - filemtime($a);
});

if (isset($_GET['descargar'])) {
    $archivo = basename($_GET['descargar']);
    $rutaArchivo = $backupDir . '/' . $archivo;

    if (!file_exists($rutaArchivo)) {
        die('El archivo no existe.');
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    header('Content-Length: ' . filesize($rutaArchivo));

    readfile($rutaArchivo);
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Respaldos de Base de Datos</title>

    <style>
        *{
            box-sizing:border-box;
        }

        body{
            margin:0;
            padding:30px;
            background:#f4f6f9;
            font-family:Arial,sans-serif;
        }

        .contenedor{
            max-width:1200px;
            margin:auto;
            background:#fff;
            border-radius:12px;
            padding:25px;
            box-shadow:0 4px 14px rgba(0,0,0,.12);
        }

        h1{
            margin-top:0;
            color:#1f5ea8;
        }

        .info{
            margin-bottom:20px;
            padding:12px;
            border-radius:8px;
            background:#eef5ff;
            color:#1f5ea8;
            font-weight:bold;
        }

        table{
            width:100%;
            border-collapse:collapse;
        }

        th{
            background:#1f5ea8;
            color:white;
            padding:12px;
        }

        td{
            padding:12px;
            border-bottom:1px solid #ddd;
            text-align:center;
        }

        tr:hover{
            background:#f8f9fa;
        }

        .boton{
            display:inline-block;
            padding:8px 14px;
            border-radius:6px;
            text-decoration:none;
            background:#1f5ea8;
            color:white;
            font-weight:bold;
        }

        .boton:hover{
            background:#164579;
        }

        .volver{
            display:inline-block;
            margin-bottom:15px;
            text-decoration:none;
            color:#1f5ea8;
            font-weight:bold;
        }

        .vacio{
            text-align:center;
            padding:25px;
            color:#777;
        }
    </style>
</head>
<body>

<div class="contenedor">

    <a href="dashboard.php" class="volver">
        ← Regresar al Dashboard
    </a>

    <h1>Respaldos de Base de Datos</h1>

    <div class="info">
        Usuario: <?= htmlspecialchars($user['nombre'] ?? '') ?>
        |
        Rol: <?= htmlspecialchars($user['rol'] ?? '') ?>
    </div>

    <?php if (empty($archivos)): ?>

        <div class="vacio">
            No existen respaldos disponibles.
        </div>

    <?php else: ?>

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
                $fecha = date('d/m/Y H:i:s', filemtime($ruta));
                $tamano = round(filesize($ruta) / 1024 / 1024, 2) . ' MB';
                ?>

                <tr>
                    <td><?= htmlspecialchars($nombre) ?></td>
                    <td><?= $fecha ?></td>
                    <td><?= $tamano ?></td>
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

    <?php endif; ?>

</div>

</body>
</html>