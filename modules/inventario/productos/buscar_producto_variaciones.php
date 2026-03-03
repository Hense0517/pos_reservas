<?php
session_start();
require_once '../../config/database.php';

header('Content-Type: application/json');

$database = Database::getInstance();
$db = $database->getConnection();

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

try {
    // Buscar productos y sus variaciones
    $query = "SELECT 
                p.id, 
                p.nombre, 
                p.codigo, 
                p.codigo_barras,
                p.tiene_variaciones,
                pv.id as variacion_id,
                pv.sku,
                pv.atributo_nombre,
                pv.atributo_valor,
                pv.precio_venta,
                pv.precio_compra,
                pv.stock,
                pv.stock_minimo
              FROM productos p
              LEFT JOIN producto_variaciones pv ON p.id = pv.producto_id AND pv.activo = 1
              WHERE p.activo = 1 
                AND (p.nombre LIKE :q 
                     OR p.codigo LIKE :q 
                     OR p.codigo_barras LIKE :q
                     OR pv.sku LIKE :q
                     OR pv.atributo_valor LIKE :q)
              ORDER BY p.nombre, pv.atributo_valor
              LIMIT 20";
    
    $stmt = $db->prepare($query);
    $searchTerm = "%$q%";
    $stmt->bindParam(':q', $searchTerm);
    $stmt->execute();
    
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Si no hay variaciones, buscar productos simples
    if (empty($resultados)) {
        $query = "SELECT 
                    p.id, 
                    p.nombre, 
                    p.codigo, 
                    p.codigo_barras,
                    p.tiene_variaciones,
                    pv.precio_venta,
                    pv.precio_compra,
                    pv.stock
                  FROM productos p
                  LEFT JOIN producto_variaciones pv ON p.id = pv.producto_id AND pv.activo = 1
                  WHERE p.activo = 1 
                    AND p.tiene_variaciones = 0
                    AND (p.nombre LIKE :q 
                         OR p.codigo LIKE :q 
                         OR p.codigo_barras LIKE :q)
                  GROUP BY p.id
                  ORDER BY p.nombre
                  LIMIT 20";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':q', $searchTerm);
        $stmt->execute();
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Formatear resultados
    $productos = [];
    foreach ($resultados as $row) {
        // Si el producto tiene variaciones, incluir información de variación
        if ($row['tiene_variaciones'] == 1 && !empty($row['variacion_id'])) {
            $productos[] = [
                'id' => $row['id'],
                'nombre' => $row['nombre'],
                'codigo' => $row['codigo'],
                'codigo_barras' => $row['codigo_barras'],
                'tiene_variaciones' => $row['tiene_variaciones'],
                'variacion_id' => $row['variacion_id'],
                'sku' => $row['sku'],
                'atributo_nombre' => $row['atributo_nombre'],
                'atributo_valor' => $row['atributo_valor'],
                'precio_venta' => $row['precio_venta'],
                'precio_compra' => $row['precio_compra'],
                'stock' => $row['stock']
            ];
        } else {
            // Producto simple
            $productos[] = [
                'id' => $row['id'],
                'nombre' => $row['nombre'],
                'codigo' => $row['codigo'],
                'codigo_barras' => $row['codigo_barras'],
                'tiene_variaciones' => $row['tiene_variaciones'],
                'precio_venta' => $row['precio_venta'],
                'precio_compra' => $row['precio_compra'],
                'stock' => $row['stock']
            ];
        }
    }
    
    echo json_encode($productos);
    
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}