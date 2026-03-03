<?php
/**
 * ============================================
 * ARCHIVO: cancelar.php
 * UBICACIÓN: /modules/compras/cancelar.php
 * PROPÓSITO: Cancelar una compra (solo si está pendiente)
 * ============================================
 */

session_start();
require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
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
    
    // Verificar que la compra existe y está pendiente
    $stmt = $db->prepare("SELECT estado, numero_factura FROM compras WHERE id = ?");
    $stmt->execute([$id]);
    $compra = $stmt->fetch();
    
    if (!$compra) {
        throw new Exception("Compra no encontrada");
    }
    
    if ($compra['estado'] != 'pendiente') {
        throw new Exception("Solo se pueden cancelar compras en estado pendiente");
    }
    
    // Cancelar compra
    $stmt = $db->prepare("UPDATE compras SET estado = 'cancelada', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);
    
    $_SESSION['success'] = "✅ Compra #{$compra['numero_factura']} cancelada exitosamente";
    
} catch (Exception $e) {
    error_log("Error al cancelar compra: " . $e->getMessage());
    $_SESSION['error'] = "❌ Error: " . $e->getMessage();
}

header("Location: index.php");
exit();
?>