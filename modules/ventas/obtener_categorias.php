<?php
// modules/ventas/obtener_categorias.php
session_start();
require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

try {
    $query = "SELECT id, nombre FROM categorias WHERE activo = 1 ORDER BY nombre";
    $stmt = $db->prepare($query);
    $stmt->execute();
    
    $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($categorias);
    
} catch (Exception $e) {
    error_log("Error en obtener_categorias.php: " . $e->getMessage());
    echo json_encode(['error' => 'Error al obtener categorías']);
}
?>