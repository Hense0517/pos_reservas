<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/auth.php';

$database = Database::getInstance();
$auth = new Auth($database);
$db = $database->getConnection();

// Verificar autenticación
$auth->checkAuth();

// Obtener información del usuario actual
$current_user = $auth->getUserInfo();

// Determinar si es edición del propio perfil o de otro usuario (solo admin)
if (isset($_POST['usuario_id']) && $auth->isAdmin()) {
    // Admin editando otro usuario
    $usuario_id = $_POST['usuario_id'];
} else {
    // Usuario editando su propio perfil
    $usuario_id = $current_user['id'];
}

if ($_POST) {
    try {
        $nombre = trim($_POST['nombre']);
        $email = trim($_POST['email'] ?? '');
        
        // Validaciones básicas
        if (empty($nombre)) {
            $_SESSION['error'] = "El nombre es obligatorio";
            header("Location: ver_perfil.php");
            exit;
        }

        // Verificar si el email ya existe (si se proporciona)
        if (!empty($email)) {
            $check_query = "SELECT id FROM usuarios WHERE email = :email AND id != :id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':email', $email);
            $check_stmt->bindParam(':id', $usuario_id);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                $_SESSION['error'] = "El email ya está en uso por otro usuario";
                header("Location: ver_perfil.php");
                exit;
            }
        }

        // Manejo de contraseñas
        $password_actual = $_POST['password_actual'] ?? '';
        $nueva_password = $_POST['nueva_password'] ?? '';

        // Si se proporciona nueva contraseña, validar
        if (!empty($nueva_password)) {
            // Si el usuario NO es admin o está editando su propio perfil, verificar contraseña actual
            if (!$auth->isAdmin() || $usuario_id == $current_user['id']) {
                if (empty($password_actual)) {
                    $_SESSION['error'] = "Debes ingresar tu contraseña actual para cambiarla";
                    header("Location: ver_perfil.php");
                    exit;
                }

                // Verificar contraseña actual
                $query = "SELECT password FROM usuarios WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $usuario_id);
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!password_verify($password_actual, $user['password'])) {
                        $_SESSION['error'] = "La contraseña actual es incorrecta";
                        header("Location: ver_perfil.php");
                        exit;
                    }
                }
            }

            // Actualizar con nueva contraseña
            $hashed_password = password_hash($nueva_password, PASSWORD_DEFAULT);
            $query = "UPDATE usuarios SET nombre = :nombre, email = :email, 
                     password = :password, updated_at = NOW() 
                     WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':password', $hashed_password);
        } else {
            // Actualizar sin cambiar contraseña
            $query = "UPDATE usuarios SET nombre = :nombre, email = :email, 
                     updated_at = NOW() WHERE id = :id";
            $stmt = $db->prepare($query);
        }

        $stmt->bindParam(':nombre', $nombre);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':id', $usuario_id);

        if ($stmt->execute()) {
            // Actualizar datos en sesión si es el usuario actual
            if ($usuario_id == $current_user['id']) {
                $_SESSION['usuario_nombre'] = $nombre;
            }
            
            $_SESSION['success'] = "Perfil actualizado correctamente";
        } else {
            $_SESSION['error'] = "Error al actualizar el perfil";
        }

    } catch (PDOException $e) {
        error_log("Error en actualizar_perfil.php: " . $e->getMessage());
        $_SESSION['error'] = "Error del sistema al actualizar el perfil";
    }

    header("Location: ver_perfil.php");
    exit;
} else {
    $_SESSION['error'] = "Método no permitido";
    header("Location: ver_perfil.php");
    exit;
}
?>