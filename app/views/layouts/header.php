<?php
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/utils.php';

$user = currentUser();
$moduleCss = $moduleCss ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Almacén de Farmacia</title>
    <link rel="stylesheet" href="assets/css/global.css">
<?php if (!empty($moduleCss)): ?>
    <link rel="stylesheet" href="assets/css/<?= $moduleCss ?>.css?v=<?= time() ?>">
<?php endif; ?>
</head>
<body>
    <header class="topbar">
        <div class="topbar-left">
            <h1>SISTEMA DE ALMACÉN - FARMACIA</h1>
        </div>
        <div class="topbar-right">
            <?php if ($user): ?>
                <span><?= e($user['nombre']) ?> | <?= e($user['rol']) ?></span>
                <a href="logout.php" class="btn-top">Cerrar sesión</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($user): ?>
    <nav class="navbar">
        <a href="dashboard.php">Inicio</a>
        <a href="productos.php">Productos</a>
        <a href="entradas.php">Entradas</a>
        <a href="salidas.php">Salidas</a>
        <a href="existencias.php">Existencias</a>
        <a href="kardex.php">Kardex</a>
        <a href="reportes.php">Reportes</a>
        <a href="historial_entradas.php">Historial de Entradas</a>
        <a href="historial_salidas.php">Historial Salidas</a>
        <a href="inventario_fisico.php">Inventario Físico</a>
        
    </nav>
    <?php endif; ?>

    <main class="main-content">