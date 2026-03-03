<?php
session_start();
require_once '../../includes/database.php';

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$proveedor_id = $_GET['id'];

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    // Verificar si el proveedor tiene compras asociadas
    $query_compras = "SELECT COUNT(*) as total_compras FROM compras WHERE proveedor_id = ?";
    $stmt_compras = $db->prepare($query_compras);
    $stmt_compras->execute([$proveedor_id]);
    $compras = $stmt_compras->fetch(PDO::FETCH_ASSOC);

    if ($compras['total_compras'] > 0) {
        $_SESSION['error'] = "No se puede eliminar el proveedor porque tiene compras asociadas. Puedes desactivarlo en su lugar.";
        header('Location: index.php');
        exit;
    }

    // Eliminar proveedor
    $query = "DELETE FROM proveedores WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$proveedor_id]);

    $_SESSION['success'] = "Proveedor eliminado exitosamente";
    
} catch (Exception $e) {
    $_SESSION['error'] = "Error al eliminar el proveedor: " . $e->getMessage();
}

header('Location: index.php');
exit;