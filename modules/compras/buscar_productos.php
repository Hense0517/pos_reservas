<?php
session_start();
require_once '../../config/database.php';

header('Content-Type: application/json');

if (!isset($_GET['q'])) {
    echo json_encode([]);
    exit();
}

$searchTerm = '%' . $_GET['q'] . '%';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    $query = "SELECT id, nombre, codigo, precio_compra, stock 
              FROM productos 
              WHERE (nombre LIKE :search OR codigo LIKE :search) 
              AND activo = 1 
              ORDER BY nombre 
              LIMIT 20";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':search', $searchTerm);
    $stmt->execute();
    
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results = [];
    foreach ($productos as $producto) {
        $results[] = [
            'id' => $producto['id'],
            'text' => $producto['nombre'] . ' (' . $producto['codigo'] . ') - Stock: ' . $producto['stock'],
            'precio_compra' => $producto['precio_compra'],
            'stock' => $producto['stock']
        ];
    }
    
    echo json_encode($results);
    
} catch (Exception $e) {
    echo json_encode([]);
}