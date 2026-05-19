<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/controllers/InventarioFisicoVirtualController.php';

header('Content-Type: application/json; charset=utf-8');

requireLogin();

try {
    $controller = new InventarioFisicoVirtualController();
    $controller->verificarAcceso();

    $codigo = trim($_GET['codigo'] ?? '');

    if ($codigo === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Código vacío.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $producto = $controller->buscarProducto($codigo);

    if (!$producto) {
        echo json_encode([
            'success' => false,
            'message' => 'Producto no encontrado.',
            'codigo' => $codigo
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $ubicacion = strtoupper(trim((string)($producto['ubicacion'] ?? '')));

    if ($ubicacion === '') {
        $ubicacion = 'SIN UBICACION';
    }

    $existencia = (int)($producto['existencia_actual'] ?? $producto['existencia'] ?? 0);

    $ubicaciones = $producto['ubicaciones'] ?? [];

    if (!is_array($ubicaciones) || empty($ubicaciones)) {
        $ubicaciones = [
            [
                'ubicacion' => $ubicacion,
                'existencia_actual' => $existencia
            ]
        ];
    }

    usort($ubicaciones, function ($a, $b) {
        return ((int)($a['existencia_actual'] ?? 0)) <=> ((int)($b['existencia_actual'] ?? 0));
    });

    echo json_encode([
        'success' => true,
        'id' => $producto['id'] ?? null,
        'codigo' => $producto['codigo'] ?? '',
        'codigo_barras' => $producto['codigo_barras'] ?? '',
        'descripcion' => $producto['descripcion'] ?? '',
        'unidad_medida' => $producto['unidad_medida'] ?? '',
        'precio_compra' => $producto['precio_compra'] ?? 0,
        'precio_venta' => $producto['precio_venta'] ?? 0,
        'existencia_actual' => $existencia,
        'ubicacion' => $ubicaciones[0]['ubicacion'] ?? $ubicacion,
        'ubicaciones' => $ubicaciones
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}