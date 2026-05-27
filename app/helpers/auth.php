<?php

// Duración de sesión: 1 año
$tiempoSesion = 60 * 60 * 24 * 365;

ini_set('session.gc_maxlifetime', (string)$tiempoSesion);
ini_set('session.cookie_lifetime', (string)$tiempoSesion);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $tiempoSesion,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
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

    // Renueva la actividad sin cerrar sesión por inactividad
    $_SESSION['ultimo_acceso'] = time();
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function logoutUser(): void
{
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"] ?? '',
            $params["secure"] ?? false,
            $params["httponly"] ?? true
        );
    }

    session_destroy();
}