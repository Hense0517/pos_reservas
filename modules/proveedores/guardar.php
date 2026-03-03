<?php
/**
 * ============================================
 * ARCHIVO: guardar.php
 * UBICACIÓN: /modules/proveedores/guardar.php
 * PROPÓSITO: Procesar el formulario de nuevo proveedor
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

// Verificar permisos
if (!$auth->hasPermission('proveedores', 'crear')) {
    $_SESSION['error'] = "No tienes permisos para crear proveedores";
    header("Location: index.php");
    exit();
}

// Verificar que es una solicitud POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Método no permitido";
    header('Location: crear.php');
    exit();
}

// Verificar que los datos requeridos están presentes
if (!isset($_POST['nombre']) || empty(trim($_POST['nombre']))) {
    $_SESSION['error'] = "El nombre del proveedor es obligatorio";
    header('Location: crear.php');
    exit();
}

try {
    // Obtener conexión a base de datos
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception("No se pudo conectar a la base de datos");
    }
    
    $db->beginTransaction();
    
    // Recoger y sanitizar datos
    $nombre = trim($_POST['nombre']);
    $ruc = isset($_POST['ruc']) ? trim($_POST['ruc']) : '';
    $contacto = isset($_POST['contacto']) ? trim($_POST['contacto']) : '';
    $telefono = isset($_POST['telefono']) ? trim($_POST['telefono']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $direccion = isset($_POST['direccion']) ? trim($_POST['direccion']) : '';
    $estado = isset($_POST['estado']) ? $_POST['estado'] : 'activo';
    
    // Array para errores
    $errores = [];
    
    // Validar nombre (obligatorio)
    if (empty($nombre)) {
        $errores[] = "El nombre del proveedor es obligatorio";
    }
    
    // Validar RUC si existe
    if (!empty($ruc)) {
        // Verificar si el RUC ya existe
        $query_ruc = "SELECT id FROM proveedores WHERE ruc = ?";
        $stmt_ruc = $db->prepare($query_ruc);
        $stmt_ruc->execute([$ruc]);
        
        if ($stmt_ruc->rowCount() > 0) {
            $errores[] = "El RUC '$ruc' ya está registrado por otro proveedor";
        }
    }
    
    // Validar email si existe
    if (!empty($email)) {
        // Validar formato de email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errores[] = "El formato del email no es válido";
        } else {
            // Verificar si el email ya existe
            $query_email = "SELECT id FROM proveedores WHERE email = ?";
            $stmt_email = $db->prepare($query_email);
            $stmt_email->execute([$email]);
            
            if ($stmt_email->rowCount() > 0) {
                $errores[] = "El email '$email' ya está registrado por otro proveedor";
            }
        }
    }
    
    // Si hay errores, redirigir
    if (!empty($errores)) {
        $_SESSION['error'] = implode('<br>', $errores);
        header('Location: crear.php');
        exit();
    }
    
    // Preparar la consulta de inserción
    $query = "INSERT INTO proveedores (nombre, ruc, contacto, telefono, email, direccion, estado, created_at) 
              VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $db->prepare($query);
    $result = $stmt->execute([
        $nombre,
        $ruc ?: null,
        $contacto ?: null,
        $telefono ?: null,
        $email ?: null,
        $direccion ?: null,
        $estado
    ]);
    
    if ($result) {
        $proveedor_id = $db->lastInsertId();
        
        // Registrar en log de auditoría
        try {
            $log_sql = "INSERT INTO logs_acciones (usuario_id, accion, detalle, ip, created_at) 
                        VALUES (?, 'CREAR_PROVEEDOR', ?, ?, NOW())";
            $log_stmt = $db->prepare($log_sql);
            $detalle = "Proveedor creado: $nombre (ID: $proveedor_id)";
            $log_stmt->execute([
                $_SESSION['usuario_id'],
                $detalle,
                $_SERVER['REMOTE_ADDR']
            ]);
        } catch (Exception $e) {
            // Si no existe la tabla logs, continuar
        }
        
        $db->commit();
        $_SESSION['success'] = "✅ Proveedor '$nombre' creado exitosamente";
        header('Location: ver.php?id=' . $proveedor_id);
        exit();
    } else {
        $db->rollBack();
        $errorInfo = $stmt->errorInfo();
        throw new Exception("Error al guardar: " . ($errorInfo[2] ?? 'Error desconocido'));
    }
    
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error PDO en guardar_proveedor: " . $e->getMessage());
    $_SESSION['error'] = "❌ Error de base de datos: " . $e->getMessage();
    header('Location: crear.php');
    exit();
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error en guardar_proveedor: " . $e->getMessage());
    $_SESSION['error'] = "❌ Error al crear el proveedor: " . $e->getMessage();
    header('Location: crear.php');
    exit();
}
?>