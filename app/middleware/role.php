<?php

require_once __DIR__ . '/../helpers/auth.php';

function requireRole(array $rolesPermitidos): void
{
    requireLogin();

    $user = currentUser();

    if (!$user || !in_array($user['rol'], $rolesPermitidos, true)) {
        auditLog([
            'modulo' => auditModuleForScript(
                basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''))
            ),
            'accion' => 'ACCESO_DENEGADO',
            'descripcion' => 'Intentó ingresar a un módulo sin contar con el rol requerido.',
            'metadata' => [
                'roles_permitidos' => $rolesPermitidos,
                'rol_usuario' => $user['rol'] ?? null,
            ],
        ]);

        http_response_code(403);
        echo "<h2>Acceso denegado</h2>";
        echo "<p>No tienes permisos para acceder a este módulo.</p>";
        exit;
    }
}

function denyGerenteWrite(): void
{
    requireLogin();

    $user = currentUser();

    if (($user['rol'] ?? '') === 'GERENTE') {
        auditLog([
            'modulo' => auditModuleForScript(
                basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''))
            ),
            'accion' => 'ACCION_DENEGADA',
            'descripcion' => 'Un usuario GERENTE intentó realizar una acción de escritura.',
        ]);

        http_response_code(403);
        echo "<h2>Acceso denegado</h2>";
        echo "<p>El perfil GERENTE solo tiene permisos de consulta.</p>";
        exit;
    }
}
