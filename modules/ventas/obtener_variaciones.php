<?php
session_start();
require_once '../../config/database.php';

header('Content-Type: application/json');

$database = Database::getInstance();
$db = $database->getConnection();

$producto_id = isset($_GET['producto_id']) ? intval($_GET['producto_id']) : 0;

if ($producto_id <= 0) {
    echo json_encode([]);
    exit;
}

try {
    $query = "SELECT 
                id,
                sku,
                atributo_nombre,
                atributo_valor,
                precio_venta,
                precio_compra,
                stock,
                stock_minimo
              FROM producto_variaciones
              WHERE producto_id = ? AND activo = 1
              ORDER BY atributo_nombre, atributo_valor";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$producto_id]);
    
    $variaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($variaciones);
    
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}