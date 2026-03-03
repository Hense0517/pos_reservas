<?php
/**
 * ============================================
 * ARCHIVO: toggle_usuario.php
 * UBICACIÓN: /modules/usuarios/toggle_usuario.php
 * PROPÓSITO: Activar o desactivar usuarios
 * VERSIÓN: Simplificada para evitar errores
 * ============================================
 */

session_start();

// Incluir configuración principal
require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

// Solo admin puede cambiar estados
if ($_SESSION['usuario_rol'] != 'admin') {
    $_SESSION['error'] = "No tienes permisos para esta acción";
    header("Location: index.php");
    exit();
}

// Verificar parámetros
if (!isset($_GET['id']) || !isset($_GET['action'])) {
    $_SESSION['error'] = "Parámetros incorrectos";
    header("Location: index.php");
    exit();
}

$id = intval($_GET['id']);
$action = $_GET['action'];

// Validar acción
if ($action != 'activar' && $action != 'desactivar') {
    $_SESSION['error'] = "Acción no válida";
    header("Location: index.php");
    exit();
}

// No permitir desactivarse a sí mismo
if ($id == $_SESSION['usuario_id'] && $action == 'desactivar') {
    $_SESSION['error'] = "No puedes desactivar tu propia cuenta";
    header("Location: index.php");
    exit();
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    $nuevo_estado = ($action == 'activar') ? 1 : 0;
    
    // Verificar que el usuario existe
    $check = $db->prepare("SELECT id, username, nombre FROM usuarios WHERE id = ?");
    $check->execute([$id]);
    $usuario = $check->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        $_SESSION['error'] = "Usuario no encontrado";
        header("Location: index.php");
        exit();
    }
    
    // Actualizar estado
    $query = "UPDATE usuarios SET activo = ?, updated_at = NOW() WHERE id = ?";
    $stmt = $db->prepare($query);
    $result = $stmt->execute([$nuevo_estado, $id]);
    
    if ($result && $stmt->rowCount() > 0) {
        $mensaje = $action == 'activar' ? "activado" : "desactivado";
        $_SESSION['success'] = "Usuario '{$usuario['username']}' ha sido $mensaje correctamente";
    } else {
        $_SESSION['error'] = "No se pudo cambiar el estado del usuario";
    }
    
} catch (Exception $e) {
    error_log("Error en toggle_usuario: " . $e->getMessage());
    $_SESSION['error'] = "Error del sistema";
}

header("Location: index.php");
exit();
?>