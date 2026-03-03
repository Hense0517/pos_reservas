<?php
/**
 * ============================================
 * ARCHIVO: ajax_buscar_productos.php
 * UBICACIÓN: /modules/reservas/ajax_buscar_productos.php
 * PROPÓSITO: Buscar productos para agregar a la reserva
 * ============================================
 */

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$termino = $_POST['termino'] ?? '';

if (strlen($termino) < 2) {
    echo json_encode([]);
    exit;
}

try {
    $termino_busqueda = "%$termino%";
    
    $query = "SELECT id, nombre, codigo, precio_venta, stock 
              FROM productos 
              WHERE activo = 1 AND es_servicio = 0
              AND (nombre LIKE ? OR codigo LIKE ? OR codigo_barras LIKE ?)
              ORDER BY nombre
              LIMIT 15";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$termino_busqueda, $termino_busqueda, $termino_busqueda]);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convertir a números para evitar errores de JavaScript
    foreach ($productos as &$p) {
        $p['precio_venta'] = floatval($p['precio_venta'] ?? 0);
        $p['stock'] = intval($p['stock'] ?? 0);
        $p['id'] = intval($p['id']);
    }
    
    echo json_encode($productos);
    
} catch (Exception $e) {
    error_log("Error en ajax_buscar_productos: " . $e->getMessage());
    echo json_encode([]);
}
?>