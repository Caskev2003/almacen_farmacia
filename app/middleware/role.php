<?php

require_once __DIR__ . '/../helpers/auth.php';

function requireRole(array $rolesPermitidos): void
{
    requireLogin();

    $user = currentUser();

    if (!$user || !in_array($user['rol'], $rolesPermitidos, true)) {
        http_response_code(403);
        echo "<h2>Acceso denegado</h2>";
        echo "<p>No tienes permisos para acceder a este módulo.</p>";
        exit;
    }
}