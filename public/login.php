<?php

require_once __DIR__ . '/../app/controllers/AuthController.php';
require_once __DIR__ . '/../app/helpers/auth.php';

if (isLoggedIn()) {
    header('Location: ' . paginaInicialUsuario());
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = $_POST['login'] ?? '';
    $password = $_POST['password'] ?? '';

    $authController = new AuthController();
    $result = $authController->login($login, $password);

    if ($result['success']) {
        header('Location: ' . paginaInicialUsuario());
        exit;
    } else {
        $error = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Almacén Farmacia</title>
    <link rel="stylesheet" href="assets/css/global.css">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body class="login-body">
    <div class="login-container">
        <div class="login-card">
            <h2>Iniciar sesión</h2>
            <p class="login-subtitle">Control de almacén de farmacia</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label>Usuario o correo</label>
                    <input type="text" name="login" required>
                </div>

                <div class="form-group">
                    <label>Contraseña</label>
                    <input type="password" name="password" required>
                </div>

                <button type="submit" class="btn-primary">Entrar</button>
            </form>

            
        </div>
    </div>
</body>
</html>
