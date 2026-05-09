<?php

require_once __DIR__ . '/../app/config/database.php';

try {
    $database = new Database();
    $conn = $database->connect();

    $nuevoHash = password_hash('admin123', PASSWORD_DEFAULT);

    $sql = "UPDATE usuarios 
            SET password = :password 
            WHERE usuario = 'admin' OR correo = 'admin@farmacia.com'";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':password' => $nuevoHash
    ]);

    echo "Contraseña actualizada correctamente para el usuario admin.";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage();
}