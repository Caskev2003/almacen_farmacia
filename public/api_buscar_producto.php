<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/controllers/InventarioFisicoVirtualController.php';

header('Content-Type: application/json');

requireLogin();

try {

    $controller = new InventarioFisicoVirtualController();
    $controller->verificarAcceso();

    $codigo = trim($_GET['codigo'] ?? '');

    if ($codigo === '') {

        echo json_encode([
            'success' => false,
            'message' => 'Código vacío.'
        ]);

        exit;
    }

    $producto = $controller->buscarProducto($codigo);

    if (!$producto) {

        echo json_encode([
            'success' => false,
            'message' => 'Producto no encontrado.',
            'codigo' => $codigo
        ]);

        exit;
    }

    echo json_encode([
        'success' => true,
        'id' => $producto['id'] ?? null,
        'codigo_barras' => $producto['codigo_barras'] ?? '',
        'descripcion' => $producto['descripcion'] ?? '',
        'ubicacion' => $producto['ubicacion'] ?? ''
    ]);

} catch (Throwable $e) {

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}