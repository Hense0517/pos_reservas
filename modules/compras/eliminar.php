<?php
/**
 * ============================================
 * ARCHIVO: eliminar.php
 * UBICACIÓN: /modules/compras/eliminar.php
 * PROPÓSITO: Eliminar permanentemente una compra (solo admin)
 * ============================================
 */

session_start();
require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

// Verificar permisos (solo admin)
if ($_SESSION['usuario_rol'] != 'admin') {
    $_SESSION['error'] = "No tienes permisos para eliminar compras";
    header("Location: index.php");
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    $_SESSION['error'] = "ID de compra no válido";
    header("Location: index.php");
    exit();
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    $db->beginTransaction();
    
    // Obtener información de la compra
    $stmt = $db->prepare("SELECT estado, numero_factura FROM compras WHERE id = ?");
    $stmt->execute([$id]);
    $compra = $stmt->fetch();
    
    if (!$compra) {
        throw new Exception("Compra no encontrada");
    }
    
    // Si la compra estaba recibida, debemos descontar el stock
    if ($compra['estado'] == 'recibida') {
        // Obtener detalles para descontar stock - CORREGIDO: compra_detalles
        $stmt = $db->prepare("SELECT producto_id, cantidad FROM compra_detalles WHERE compra_id = ?");
        $stmt->execute([$id]);
        $detalles = $stmt->fetchAll();
        
        $update_producto = $db->prepare("UPDATE productos SET stock = stock - ? WHERE id = ?");
        
        foreach ($detalles as $detalle) {
            // Verificar que hay suficiente stock para descontar
            $check = $db->prepare("SELECT stock FROM productos WHERE id = ?");
            $check->execute([$detalle['producto_id']]);
            $producto = $check->fetch();
            
            if ($producto['stock'] < $detalle['cantidad']) {
                throw new Exception("No se puede eliminar: stock insuficiente para producto ID " . $detalle['producto_id']);
            }
            
            $update_producto->execute([$detalle['cantidad'], $detalle['producto_id']]);
        }
    }
    
    // Eliminar detalles de la compra - CORREGIDO: compra_detalles
    $stmt = $db->prepare("DELETE FROM compra_detalles WHERE compra_id = ?");
    $stmt->execute([$id]);
    
    // Eliminar la compra
    $stmt = $db->prepare("DELETE FROM compras WHERE id = ?");
    $stmt->execute([$id]);
    
    $db->commit();
    
    $_SESSION['success'] = "✅ Compra #{$compra['numero_factura']} eliminada permanentemente";
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error al eliminar compra: " . $e->getMessage());
    $_SESSION['error'] = "❌ Error al eliminar: " . $e->getMessage();
}

header("Location: index.php");
exit();
?>