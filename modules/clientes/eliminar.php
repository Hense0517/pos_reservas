<?php
session_start();
require_once '../../config/database.php'; // Cambiado para que coincida con los demás

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$cliente_id = $_GET['id'];

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    // Verificar si el cliente tiene ventas asociadas
    $query_ventas = "SELECT COUNT(*) as total FROM ventas WHERE cliente_id = ?";
    $stmt_ventas = $db->prepare($query_ventas);
    $stmt_ventas->execute([$cliente_id]);
    $resultado = $stmt_ventas->fetch(PDO::FETCH_ASSOC);
    
    if ($resultado['total'] > 0) {
        $_SESSION['error'] = "No se puede eliminar el cliente porque tiene ventas asociadas.";
        header('Location: index.php');
        exit;
    }

    // Eliminar cliente
    $query = "DELETE FROM clientes WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$cliente_id]);

    $_SESSION['success'] = "Cliente eliminado exitosamente";
    
} catch (Exception $e) {
    $_SESSION['error'] = "Error al eliminar el cliente: " . $e->getMessage();
}

header('Location: index.php');
exit;
?>