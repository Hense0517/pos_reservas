<?php
/**
 * ============================================
 * ARCHIVO: guardar_edicion.php
 * UBICACIÓN: /modules/reservas/guardar_edicion.php
 * PROPÓSITO: Procesar la edición de una reserva
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
if (!$auth->hasPermission('reservas', 'editar')) {
    $_SESSION['error'] = "No tienes permisos para editar reservas";
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}

$database = Database::getInstance();
$db = $database->getConnection();

$reserva_id = intval($_POST['reserva_id'] ?? 0);
$usuario_id = !empty($_POST['usuario_id']) ? intval($_POST['usuario_id']) : null;
$fecha_hora = $_POST['fecha_hora'] ?? '';
$servicios_json = $_POST['servicios_json'] ?? '[]';
$observaciones = trim($_POST['observaciones'] ?? '');

// Validaciones
if ($reserva_id <= 0) {
    $_SESSION['error'] = "ID de reserva no válido";
    header("Location: index.php");
    exit();
}

if (empty($fecha_hora)) {
    $_SESSION['error'] = "La fecha y hora son requeridas";
    header("Location: editar.php?id=" . $reserva_id);
    exit();
}

$servicios = json_decode($servicios_json, true);
if (!is_array($servicios) || empty($servicios)) {
    $_SESSION['error'] = "Debe seleccionar al menos un servicio";
    header("Location: editar.php?id=" . $reserva_id);
    exit();
}

// Verificar que la reserva existe y no está completada
$query_check = "SELECT estado FROM reservas WHERE id = ?";
$stmt_check = $db->prepare($query_check);
$stmt_check->execute([$reserva_id]);
$reserva = $stmt_check->fetch(PDO::FETCH_ASSOC);

if (!$reserva) {
    $_SESSION['error'] = "Reserva no encontrada";
    header("Location: index.php");
    exit();
}

if ($reserva['estado'] == 'completada') {
    $_SESSION['error'] = "No se puede editar una reserva completada";
    header("Location: ver.php?id=" . $reserva_id);
    exit();
}

try {
    $db->beginTransaction();
    
    // Separar fecha y hora
    $fecha_reserva = date('Y-m-d', strtotime($fecha_hora));
    $hora_reserva = date('H:i:s', strtotime($fecha_hora));
    
    // Calcular totales
    $total_servicios = 0;
    foreach ($servicios as $s) {
        $total_servicios += floatval($s['precio'] ?? 0);
    }
    
    // Actualizar reserva
    $query = "UPDATE reservas SET 
                fecha_reserva = :fecha_reserva,
                hora_reserva = :hora_reserva,
                fecha_hora_reserva = :fecha_hora_reserva,
                usuario_id = :usuario_id,
                observaciones = :observaciones,
                total_servicios = :total_servicios,
                total_general = :total_general,
                updated_at = NOW()
              WHERE id = :id";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':fecha_reserva', $fecha_reserva);
    $stmt->bindParam(':hora_reserva', $hora_reserva);
    $stmt->bindParam(':fecha_hora_reserva', $fecha_hora);
    $stmt->bindParam(':usuario_id', $usuario_id);
    $stmt->bindParam(':observaciones', $observaciones);
    $stmt->bindParam(':total_servicios', $total_servicios);
    $stmt->bindParam(':total_general', $total_servicios);
    $stmt->bindParam(':id', $reserva_id);
    $stmt->execute();
    
    // Eliminar servicios anteriores
    $delete = "DELETE FROM reserva_detalles_servicios WHERE reserva_id = ?";
    $stmt_delete = $db->prepare($delete);
    $stmt_delete->execute([$reserva_id]);
    
    // Insertar nuevos servicios
    $insert = "INSERT INTO reserva_detalles_servicios (
                reserva_id, servicio_id, nombre_servicio, precio_original, precio_final, cantidad, subtotal
              ) VALUES (?, ?, ?, ?, ?, 1, ?)";
    
    $stmt_insert = $db->prepare($insert);
    
    foreach ($servicios as $s) {
        $precio = floatval($s['precio']);
        $stmt_insert->execute([
            $reserva_id,
            $s['id'],
            $s['nombre'],
            $precio,
            $precio,
            $precio
        ]);
    }
    
    $db->commit();
    
    $_SESSION['success'] = "Reserva actualizada correctamente";
    header("Location: ver.php?id=" . $reserva_id);
    exit();
    
} catch (Exception $e) {
    $db->rollBack();
    error_log("Error al actualizar reserva: " . $e->getMessage());
    $_SESSION['error'] = "Error al actualizar la reserva: " . $e->getMessage();
    header("Location: editar.php?id=" . $reserva_id);
    exit();
}
?>