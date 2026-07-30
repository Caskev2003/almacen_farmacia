<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isLoggedIn()) {
    http_response_code(401);

    echo json_encode([
        'ok' => false,
        'mensaje' => 'La sesión no está disponible.'
    ]);

    exit;
}

$_SESSION['ultimo_acceso'] = time();
renovarCookieSesion();

echo json_encode([
    'ok' => true,
    'hora' => date('c')
]);

session_write_close();
