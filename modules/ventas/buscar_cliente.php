<?php
session_start();
require_once '../../config/database.php';

header('Content-Type: application/json');

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    $query = $_GET['q'] ?? '';

    if (strlen($query) < 2) {
        echo json_encode([]);
        exit;
    }

    // Buscar clientes por nombre, cédula, documento, etc.
    $sql = "SELECT id, nombre, tipo_documento, numero_documento, telefono, email, direccion 
            FROM clientes 
            WHERE nombre LIKE ? OR numero_documento LIKE ? OR telefono LIKE ? OR email LIKE ?
            ORDER BY nombre 
            LIMIT 10";
    
    $stmt = $db->prepare($sql);
    $searchTerm = "%$query%";
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($clientes);

} catch (Exception $e) {
    echo json_encode([]);
}