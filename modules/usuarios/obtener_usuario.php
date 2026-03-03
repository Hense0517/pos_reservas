<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/auth.php';

header('Content-Type: application/json');

$database = Database::getInstance();
$auth = new Auth($database);

// Verificar permisos
if (!$auth->hasPermission('usuarios', 'lectura')) {
    echo json_encode(['success' => false, 'error' => 'No tienes permisos']);
    exit;
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'ID no especificado']);
    exit;
}

$id = $_GET['id'];
$db = $database->getConnection();

try {
    $query = "SELECT id, username, nombre, email, rol, activo FROM usuarios WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $usuario]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Error de base de datos']);
}
?>