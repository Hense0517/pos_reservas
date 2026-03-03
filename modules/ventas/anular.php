<?php
ob_start();
session_start();
include '../../includes/header.php';

// Verificar permisos
if ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'vendedor') {
    header('Location: /sistema_pos/index.php');
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

if ($_POST) {
    $venta_id = $_POST['venta_id'];
    $motivo_anulacion = $_POST['motivo_anulacion'];
    
    try {
        $db->beginTransaction();
        
        // Verificar que la venta existe y no está anulada
        $query_venta = "SELECT * FROM ventas WHERE id = ? AND anulada = 0";
        $stmt_venta = $db->prepare($query_venta);
        $stmt_venta->execute([$venta_id]);
        $venta = $stmt_venta->fetch(PDO::FETCH_ASSOC);
        
        if (!$venta) {
            throw new Exception("Venta no encontrada o ya está anulada.");
        }
        
        // Obtener detalles de la venta para restaurar stock
        $query_detalles = "SELECT * FROM venta_detalles WHERE venta_id = ?";
        $stmt_detalles = $db->prepare($query_detalles);
        $stmt_detalles->execute([$venta_id]);
        $detalles = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);
        
        // Restaurar stock de productos
        foreach ($detalles as $detalle) {
            $query_restore_stock = "UPDATE productos SET stock = stock + ? WHERE id = ?";
            $stmt_restore_stock = $db->prepare($query_restore_stock);
            $stmt_restore_stock->execute([$detalle['cantidad'], $detalle['producto_id']]);
        }
        
        // Marcar venta como anulada
        $query_anular = "UPDATE ventas SET anulada = 1, motivo_anulacion = ?, estado = 'cancelada' WHERE id = ?";
        $stmt_anular = $db->prepare($query_anular);
        $stmt_anular->execute([$motivo_anulacion, $venta_id]);
        
        $db->commit();
        
        $_SESSION['success'] = "Venta anulada correctamente. El stock ha sido restaurado.";
        
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = "Error al anular la venta: " . $e->getMessage();
    }
    
    header('Location: index.php');
    exit;
} else {
    header('Location: index.php');
    exit;
}
?>