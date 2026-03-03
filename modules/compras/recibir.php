<?php
/**
 * ============================================
 * ARCHIVO: recibir.php
 * UBICACIÓN: /modules/compras/recibir.php
 * PROPÓSITO: Marcar una compra como recibida y actualizar inventario
 * ============================================
 */

// Activar errores temporalmente para depuración (quitar en producción)
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

// Verificar permisos (solo admin)
if ($_SESSION['usuario_rol'] != 'admin') {
    $_SESSION['error'] = "No tienes permisos para realizar esta acción";
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
    
    // 1. Verificar que la compra existe y está pendiente
    $stmt = $db->prepare("SELECT id, estado, numero_factura FROM compras WHERE id = ?");
    $stmt->execute([$id]);
    $compra = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$compra) {
        throw new Exception("Compra no encontrada");
    }
    
    if ($compra['estado'] != 'pendiente') {
        throw new Exception("Solo se pueden recibir compras en estado pendiente. Estado actual: " . $compra['estado']);
    }
    
    // 2. Obtener detalles de la compra
    $stmt = $db->prepare("SELECT cd.producto_id, cd.cantidad, p.nombre as producto_nombre, p.stock as stock_actual 
                          FROM compra_detalles cd 
                          INNER JOIN productos p ON cd.producto_id = p.id 
                          WHERE cd.compra_id = ?");
    $stmt->execute([$id]);
    $detalles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($detalles)) {
        throw new Exception("La compra no tiene productos asociados");
    }
    
    // 3. Mostrar qué productos se van a actualizar (para depuración)
    error_log("Procesando compra ID: " . $id);
    foreach ($detalles as $detalle) {
        error_log("Producto: " . $detalle['producto_nombre'] . " - Cantidad: " . $detalle['cantidad'] . " - Stock actual: " . $detalle['stock_actual']);
    }
    
    // 4. Actualizar stock de cada producto
    $update_producto = $db->prepare("UPDATE productos SET stock = stock + ?, updated_at = NOW() WHERE id = ?");
    
    foreach ($detalles as $detalle) {
        $result = $update_producto->execute([$detalle['cantidad'], $detalle['producto_id']]);
        
        if (!$result) {
            $errorInfo = $update_producto->errorInfo();
            throw new Exception("Error al actualizar stock del producto: " . $detalle['producto_nombre'] . " - " . $errorInfo[2]);
        }
        
        // Verificar que se actualizó
        $check = $db->prepare("SELECT stock FROM productos WHERE id = ?");
        $check->execute([$detalle['producto_id']]);
        $nuevo_stock = $check->fetch(PDO::FETCH_ASSOC);
        error_log("Stock actualizado: " . $detalle['producto_nombre'] . " - Nuevo stock: " . $nuevo_stock['stock']);
    }
    
    // 5. Marcar compra como recibida
    $stmt = $db->prepare("UPDATE compras SET estado = 'recibida', updated_at = NOW() WHERE id = ?");
    $result = $stmt->execute([$id]);
    
    if (!$result) {
        $errorInfo = $stmt->errorInfo();
        throw new Exception("Error al actualizar el estado de la compra: " . $errorInfo[2]);
    }
    
    // 6. Confirmar transacción
    $db->commit();
    
    $_SESSION['success'] = "✅ Compra #{$compra['numero_factura']} recibida exitosamente. Stock actualizado.";
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error al recibir compra: " . $e->getMessage());
    $_SESSION['error'] = "❌ Error: " . $e->getMessage();
}

header("Location: index.php");
exit();
?>