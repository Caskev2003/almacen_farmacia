<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/controllers/InventarioFisicoVirtualController.php';

header('Content-Type: application/json');

requireLogin();

try {

    $controller = new InventarioFisicoVirtualController();
    $controller->verificarAcceso();

    $user = currentUser();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

        echo json_encode([
            'success' => false,
            'message' => 'Método no permitido.'
        ]);

        exit;
    }

    $resultado = $controller->guardar(
        $_POST,
        (int)$user['id']
    );

    echo json_encode($resultado);

} catch (Throwable $e) {

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}