<?php
/**
 * ============================================
 * ARCHIVO: procesar_eliminar.php
 * UBICACIÓN: /modules/reservas/procesar_eliminar.php
 * PROPÓSITO: Procesar la cancelación de una reserva
 * ============================================
 */

session_start();

require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

// Verificar permiso
if (!$auth->hasPermission('reservas', 'eliminar')) {
    $_SESSION['error'] = "No tienes permisos para eliminar reservas";
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}

$database = Database::getInstance();
$db = $database->getConnection();

$id = intval($_POST['id'] ?? 0);
$motivo = trim($_POST['motivo'] ?? '');

if ($id <= 0) {
    $_SESSION['error'] = "ID de reserva no válido";
    header("Location: index.php");
    exit();
}

if (empty($motivo)) {
    $_SESSION['error'] = "Debe ingresar un motivo de cancelación";
    header("Location: eliminar.php?id=" . $id);
    exit();
}

try {
    // Verificar que la reserva existe
    $query_check = "SELECT estado FROM reservas WHERE id = ?";
    $stmt_check = $db->prepare($query_check);
    $stmt_check->execute([$id]);
    $reserva = $stmt_check->fetch(PDO::FETCH_ASSOC);
    
    if (!$reserva) {
        $_SESSION['error'] = "Reserva no encontrada";
        header("Location: index.php");
        exit();
    }
    
    if ($reserva['estado'] == 'completada') {
        $_SESSION['error'] = "No se puede cancelar una reserva completada";
        header("Location: ver.php?id=" . $id);
        exit();
    }
    
    // Actualizar estado a cancelada
    $query = "UPDATE reservas SET estado = 'cancelada', motivo_cancelacion = ?, updated_at = NOW() WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$motivo, $id]);
    
    $_SESSION['success'] = "Reserva cancelada correctamente";
    header("Location: ver.php?id=" . $id);
    exit();
    
} catch (Exception $e) {
    error_log("Error al cancelar reserva: " . $e->getMessage());
    $_SESSION['error'] = "Error al cancelar la reserva";
    header("Location: eliminar.php?id=" . $id);
    exit();
}
?>