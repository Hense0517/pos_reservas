<?php
/**
 * ============================================
 * ARCHIVO: eliminar_usuario.php
 * UBICACIÓN: /modules/usuarios/eliminar_usuario.php
 * PROPÓSITO: Eliminar un usuario del sistema
 * 
 * FUNCIONALIDADES:
 * - Verificar permisos de administrador
 * - No permitir auto-eliminación
 * - Eliminar usuario y sus datos relacionados
 * - Registrar acción en logs
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

// Verificar permisos (solo admin puede eliminar usuarios)
if ($_SESSION['usuario_rol'] != 'admin') {
    $_SESSION['error'] = "No tienes permisos para eliminar usuarios";
    header("Location: index.php");
    exit();
}

// Verificar ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "ID de usuario no especificado";
    header("Location: index.php");
    exit();
}

$id = intval($_GET['id']);

// No permitir que el usuario se elimine a sí mismo
if ($id == $_SESSION['usuario_id']) {
    $_SESSION['error'] = "No puedes eliminar tu propio usuario";
    header("Location: index.php");
    exit();
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    $db->beginTransaction();
    
    // Verificar que el usuario existe
    $check = $db->prepare("SELECT id, username, nombre FROM usuarios WHERE id = ?");
    $check->execute([$id]);
    $usuario = $check->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        $_SESSION['error'] = "Usuario no encontrado";
        header("Location: index.php");
        exit();
    }
    
    // Registrar en log de auditoría (si la tabla existe)
    try {
        $log_sql = "INSERT INTO logs_acciones (usuario_id, accion, detalle, ip, created_at) 
                    VALUES (?, 'ELIMINAR_USUARIO', ?, ?, NOW())";
        $log_stmt = $db->prepare($log_sql);
        $detalle = "Usuario eliminado: {$usuario['username']} (ID: $id) - Nombre: {$usuario['nombre']}";
        $log_stmt->execute([
            $_SESSION['usuario_id'],
            $detalle,
            $_SERVER['REMOTE_ADDR']
        ]);
    } catch (Exception $e) {
        // Si no existe la tabla, continuar de todos modos
        error_log("No se pudo registrar log: " . $e->getMessage());
    }
    
    // Eliminar permisos del usuario (si existen)
    try {
        $delete_permisos = $db->prepare("DELETE FROM permisos WHERE usuario_id = ?");
        $delete_permisos->execute([$id]);
    } catch (Exception $e) {
        // Si no existe la tabla, continuar
    }
    
    // Eliminar usuario
    $query = "DELETE FROM usuarios WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$id]);
    
    if ($stmt->rowCount() > 0) {
        $db->commit();
        $_SESSION['success'] = "Usuario '{$usuario['username']}' eliminado correctamente";
    } else {
        $db->rollBack();
        $_SESSION['error'] = "Error al eliminar el usuario";
    }
    
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error al eliminar usuario: " . $e->getMessage());
    $_SESSION['error'] = "Error de base de datos: " . $e->getMessage();
}

header("Location: index.php");
exit();
?>