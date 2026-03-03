<?php
// Iniciar sesión al inicio
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar que es una solicitud POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Método no permitido";
    header('Location: index.php');
    exit();
}

// Verificar que el ID existe
if (!isset($_POST['id']) || empty($_POST['id'])) {
    $_SESSION['error'] = "ID de proveedor no especificado";
    header('Location: index.php');
    exit();
}

$proveedor_id = intval($_POST['id']);

try {
    // CORRECCIÓN: Usar la ruta correcta a database.php
    require_once '../../config/database.php';
    
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception("No se pudo conectar a la base de datos");
    }

    // Recoger y validar datos
    $nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
    $ruc = isset($_POST['ruc']) ? trim($_POST['ruc']) : '';
    $contacto = isset($_POST['contacto']) ? trim($_POST['contacto']) : '';
    $telefono = isset($_POST['telefono']) ? trim($_POST['telefono']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $direccion = isset($_POST['direccion']) ? trim($_POST['direccion']) : '';
    $estado = isset($_POST['estado']) ? $_POST['estado'] : 'activo';

    // Validaciones
    $errores = [];

    if (empty($nombre)) {
        $errores[] = "El nombre del proveedor es obligatorio";
    }

    // Verificar si el RUC ya existe (excluyendo el actual)
    if (!empty($ruc)) {
        $query_ruc = "SELECT id FROM proveedores WHERE ruc = :ruc AND id != :id";
        $stmt_ruc = $db->prepare($query_ruc);
        $stmt_ruc->bindParam(':ruc', $ruc);
        $stmt_ruc->bindParam(':id', $proveedor_id, PDO::PARAM_INT);
        $stmt_ruc->execute();
        
        if ($stmt_ruc->fetch()) {
            $errores[] = "El RUC ya está registrado en otro proveedor";
        }
    }

    // Verificar si el email ya existe (excluyendo el actual)
    if (!empty($email)) {
        // Validar formato de email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errores[] = "El formato del email no es válido";
        } else {
            $query_email = "SELECT id FROM proveedores WHERE email = :email AND id != :id";
            $stmt_email = $db->prepare($query_email);
            $stmt_email->bindParam(':email', $email);
            $stmt_email->bindParam(':id', $proveedor_id, PDO::PARAM_INT);
            $stmt_email->execute();
            
            if ($stmt_email->fetch()) {
                $errores[] = "El email ya está registrado en otro proveedor";
            }
        }
    }

    if (!empty($errores)) {
        $_SESSION['error'] = implode('<br>', $errores);
        header('Location: editar.php?id=' . $proveedor_id);
        exit();
    }

    // Actualizar proveedor usando parámetros nombrados
    $query = "UPDATE proveedores SET 
              nombre = :nombre, 
              ruc = :ruc, 
              contacto = :contacto, 
              telefono = :telefono, 
              email = :email, 
              direccion = :direccion, 
              estado = :estado, 
              updated_at = NOW() 
              WHERE id = :id";
    
    $stmt = $db->prepare($query);
    
    // Bind parameters
    $stmt->bindParam(':nombre', $nombre);
    $stmt->bindParam(':ruc', $ruc);
    $stmt->bindParam(':contacto', $contacto);
    $stmt->bindParam(':telefono', $telefono);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':direccion', $direccion);
    $stmt->bindParam(':estado', $estado);
    $stmt->bindParam(':id', $proveedor_id, PDO::PARAM_INT);
    
    // Ejecutar la consulta
    if ($stmt->execute()) {
        $_SESSION['success'] = "✅ Proveedor actualizado exitosamente";
        header('Location: index.php');
        exit();
    } else {
        $errorInfo = $stmt->errorInfo();
        throw new Exception("Error al ejecutar la actualización: " . ($errorInfo[2] ?? 'Error desconocido'));
    }

} catch (PDOException $e) {
    $_SESSION['error'] = "❌ Error de base de datos: " . $e->getMessage();
    error_log("Error en actualizar.php (PDO): " . $e->getMessage());
    header('Location: editar.php?id=' . $proveedor_id);
    exit();
} catch (Exception $e) {
    $_SESSION['error'] = "❌ Error al actualizar el proveedor: " . $e->getMessage();
    error_log("Error en actualizar.php: " . $e->getMessage());
    header('Location: editar.php?id=' . $proveedor_id);
    exit();
}