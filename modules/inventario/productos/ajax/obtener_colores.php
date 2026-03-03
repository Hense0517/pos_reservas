<?php
// pos/modules/inventario/productos/ajax/obtener_colores.php

// Configurar zona horaria
date_default_timezone_set('America/Bogota');

// Incluir configuración de base de datos - AJUSTA LA RUTA SEGÚN TU ESTRUCTURA
require_once '../../../../includes/config.php';

// Verificar que sea una petición AJAX (opcional pero recomendado)
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    http_response_code(403);
    exit('Acceso directo no permitido');
}

try {
    // Crear conexión
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // Consulta para obtener colores únicos
    $query = "SELECT DISTINCT color 
              FROM productos 
              WHERE color IS NOT NULL 
                AND TRIM(color) != '' 
                AND activo = 1 
              ORDER BY color";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    
    // Obtener resultados como array simple
    $colores = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Limpiar valores vacíos
    $colores = array_filter($colores, function($color) {
        return !empty(trim($color));
    });
    
    // Re-indexar array
    $colores = array_values($colores);
    
    // Configurar cabeceras JSON
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    
    // Devolver JSON
    echo json_encode($colores, JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // Log del error (en producción usarías un sistema de logging)
    error_log("Error en obtener_colores.php: " . $e->getMessage());
    
    // Devolver error en formato JSON
    http_response_code(500);
    echo json_encode(['error' => 'Error al obtener colores de la base de datos']);
    
} catch (Exception $e) {
    error_log("Error general en obtener_colores.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error interno del servidor']);
}
?>