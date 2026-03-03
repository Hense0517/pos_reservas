<?php
// modules/reservas/servicios/eliminar.php
require_once __DIR__ . '/../../../includes/config.php';

if (!$auth->hasPermission('reservas', 'eliminar')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

$id = $_GET['id'] ?? 0;

try {
    // Verificar si el servicio tiene reservas asociadas
    $query = "SELECT COUNT(*) as total FROM reserva_detalles_servicios WHERE servicio_id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['total'] > 0) {
        // Si tiene reservas, solo desactivar
        $query = "UPDATE servicios SET activo = 0 WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        $mensaje = "El servicio tiene reservas asociadas. Se ha desactivado en lugar de eliminar.";
        $tipo = "warning";
    } else {
        // Si no tiene reservas, eliminar físicamente
        $query = "DELETE FROM servicios WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        $mensaje = "Servicio eliminado correctamente";
        $tipo = "success";
    }
    
    header("Location: index.php?mensaje=" . urlencode($mensaje) . "&tipo=" . $tipo);
    
} catch (Exception $e) {
    error_log("Error al eliminar servicio: " . $e->getMessage());
    header("Location: index.php?error=Error al eliminar el servicio");
}
exit;