<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// Iniciar buffer de salida
ob_start();

include '../../includes/config.php';

// Verificar permisos
if (!$auth->hasPermission('gastos', 'completo')) {
    header("Location: ../../index.php");
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

$id = $_GET['id'] ?? 0;

if ($id) {
    try {
        $query = "DELETE FROM gastos WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$id]);
        
        $_SESSION['success'] = "Gasto eliminado correctamente";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error al eliminar el gasto: " . $e->getMessage();
    }
} else {
    $_SESSION['error'] = "ID de gasto no especificado";
}

// Limpiar buffer antes de redireccionar
ob_end_clean();
header("Location: index.php");
exit;
?>