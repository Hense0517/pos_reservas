<?php
require_once 'config/database.php';

$database = Database::getInstance();
$db = $database->getConnection();

$username = 'Administrador';
$new_password = 'Sarita.2310@123';
$hashed_password = '$2y$10$xNX4OgnW4lHC3EbvcyTa0.v5HMx2RAsPUMwfmrCuagsxfgVEYXqUW';

try {
    $query = "UPDATE usuarios SET password = :password WHERE username = :username";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':password', $hashed_password);
    $stmt->bindParam(':username', $username);
    
    if ($stmt->execute()) {
        echo "✅ Contraseña restablecida exitosamente<br>";
        echo "Usuario: $username<br>";
        echo "Contraseña: $new_password<br>";
        echo "Filas afectadas: " . $stmt->rowCount();
    } else {
        echo "❌ Error al restablecer contraseña";
    }
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>