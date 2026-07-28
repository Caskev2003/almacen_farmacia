<?php

// Duración de sesión: 1 año
$tiempoSesion = 60 * 60 * 24 * 365;

ini_set('session.gc_maxlifetime', (string)$tiempoSesion);
ini_set('session.cookie_lifetime', (string)$tiempoSesion);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $tiempoSesion,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function renovarCookieSesion(): void
{
    global $tiempoSesion;

    if (session_status() === PHP_SESSION_ACTIVE) {
        setcookie(
            session_name(),
            session_id(),
            [
                'expires' => time() + $tiempoSesion,
                'path' => '/',
                'domain' => '',
                'secure' => false,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }

    $_SESSION['ultimo_acceso'] = time();
    renovarCookieSesion();

    auditTrackCurrentRequest();
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function logoutUser(): void
{
    if (isLoggedIn()) {
        $user = currentUser();

        auditLog([
            'modulo' => 'Autenticación',
            'accion' => 'CIERRE_SESION',
            'entidad' => 'usuario',
            'registro_id' => $user['id'] ?? null,
            'descripcion' => 'Cerró sesión en el sistema.',
        ]);
    }

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params["path"] ?? '/',
                'domain' => $params["domain"] ?? '',
                'secure' => $params["secure"] ?? false,
                'httponly' => $params["httponly"] ?? true,
                'samesite' => $params["samesite"] ?? 'Lax',
            ]
        );
    }

    session_destroy();
}

require_once __DIR__ . '/audit.php';
