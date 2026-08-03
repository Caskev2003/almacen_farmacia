<?php
require_once __DIR__ . '/../app/helpers/auth.php';

if (isLoggedIn()) {
    header('Location: ' . paginaInicialUsuario());
    exit;
}

header("Location: login.php");
exit;
