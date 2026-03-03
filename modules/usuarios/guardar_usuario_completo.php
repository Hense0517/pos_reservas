<?php
/**
 * ============================================
 * ARCHIVO: guardar_usuario_completo.php
 * UBICACIÓN: /modules/usuarios/guardar_usuario_completo.php
 * PROPÓSITO: Guardar/Actualizar usuario con múltiples roles y permisos
 * ============================================
 */

session_start();

// Incluir configuración
require_once '../../includes/config.php';

// Verificar permisos
if (!$auth || !$auth->hasPermission('usuarios', 'escritura')) {
    $_SESSION['error'] = "No tienes permisos para esta acción";
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario_id = $_POST['usuario_id'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $activo = isset($_POST['activo']) ? 1 : 0;
    $roles = $_POST['roles'] ?? []; // Array de IDs de roles
    $permisos = $_POST['permisos'] ?? [];
    
    // Validaciones básicas
    $errors = [];
    
    if (empty($username)) {
        $errors[] = "El nombre de usuario es obligatorio";
    } elseif (strlen($username) < 3) {
        $errors[] = "El nombre de usuario debe tener al menos 3 caracteres";
    }
    
    if (empty($nombre)) {
        $errors[] = "El nombre completo es obligatorio";
    }
    
    if (empty($roles)) {
        $errors[] = "Debe seleccionar al menos un rol";
    }
    
    // Validar contraseña si se proporciona (solo para nuevos usuarios o cambio)
    if (empty($usuario_id) && empty($password)) {
        $errors[] = "La contraseña es obligatoria para nuevos usuarios";
    } elseif (!empty($password) && strlen($password) < 6) {
        $errors[] = "La contraseña debe tener al menos 6 caracteres";
    }
    
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "El formato del email no es válido";
    }
    
    if (!empty($errors)) {
        $_SESSION['error'] = implode('<br>', $errors);
        $redirect = empty($usuario_id) ? "crear.php" : "editar.php?id=" . $usuario_id;
        header("Location: " . $redirect);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        // Verificar si el usuario ya existe (excluyendo el actual)
        if (empty($usuario_id)) {
            $check_query = "SELECT id FROM usuarios WHERE username = :username";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':username', $username);
        } else {
            $check_query = "SELECT id FROM usuarios WHERE username = :username AND id != :id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':username', $username);
            $check_stmt->bindParam(':id', $usuario_id);
        }
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() > 0) {
            $_SESSION['error'] = "El nombre de usuario ya existe";
            $redirect = empty($usuario_id) ? "crear.php" : "editar.php?id=" . $usuario_id;
            header("Location: " . $redirect);
            exit;
        }
        
        if (empty($usuario_id)) {
            // Crear nuevo usuario
            $query = "INSERT INTO usuarios (username, password, nombre, email, telefono, activo, created_at, updated_at) 
                      VALUES (:username, :password, :nombre, :email, :telefono, :activo, NOW(), NOW())";
            $stmt = $db->prepare($query);
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt->bindParam(':password', $hashed_password);
        } else {
            // Actualizar usuario existente
            if (!empty($password)) {
                $query = "UPDATE usuarios SET 
                         username = :username, 
                         password = :password, 
                         nombre = :nombre, 
                         email = :email, 
                         telefono = :telefono, 
                         activo = :activo, 
                         updated_at = NOW() 
                         WHERE id = :id";
                $stmt = $db->prepare($query);
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt->bindParam(':password', $hashed_password);
            } else {
                $query = "UPDATE usuarios SET 
                         username = :username, 
                         nombre = :nombre, 
                         email = :email, 
                         telefono = :telefono, 
                         activo = :activo, 
                         updated_at = NOW() 
                         WHERE id = :id";
                $stmt = $db->prepare($query);
            }
            $stmt->bindParam(':id', $usuario_id);
        }
        
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':nombre', $nombre);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':telefono', $telefono);
        $stmt->bindParam(':activo', $activo, PDO::PARAM_INT);
        $stmt->execute();
        
        if (empty($usuario_id)) {
            $usuario_id = $db->lastInsertId();
        }
        
        // Eliminar roles existentes
        $delete_roles = "DELETE FROM usuarios_roles WHERE usuario_id = :usuario_id";
        $delete_roles_stmt = $db->prepare($delete_roles);
        $delete_roles_stmt->bindParam(':usuario_id', $usuario_id);
        $delete_roles_stmt->execute();
        
        // Insertar nuevos roles
        if (!empty($roles)) {
            $insert_rol = "INSERT INTO usuarios_roles (usuario_id, rol_id, asignado_por) VALUES (:usuario_id, :rol_id, :asignado_por)";
            $insert_rol_stmt = $db->prepare($insert_rol);
            $asignado_por = $_SESSION['usuario_id'];
            
            foreach ($roles as $rol_id) {
                $insert_rol_stmt->bindParam(':usuario_id', $usuario_id);
                $insert_rol_stmt->bindParam(':rol_id', $rol_id);
                $insert_rol_stmt->bindParam(':asignado_por', $asignado_por);
                $insert_rol_stmt->execute();
            }
        }
        
        // Eliminar permisos existentes
        $delete_permisos = "DELETE FROM permisos WHERE usuario_id = :usuario_id";
        $delete_permisos_stmt = $db->prepare($delete_permisos);
        $delete_permisos_stmt->bindParam(':usuario_id', $usuario_id);
        $delete_permisos_stmt->execute();
        
        // Insertar nuevos permisos
        foreach ($permisos as $modulo => $acciones) {
            $leer = isset($acciones['leer']) ? 1 : 0;
            $crear = isset($acciones['crear']) ? 1 : 0;
            $editar = isset($acciones['editar']) ? 1 : 0;
            $eliminar = isset($acciones['eliminar']) ? 1 : 0;
            
            if ($leer || $crear || $editar || $eliminar) {
                $insert_permiso = "INSERT INTO permisos 
                                  (usuario_id, modulo, leer, crear, editar, eliminar) 
                                  VALUES 
                                  (:usuario_id, :modulo, :leer, :crear, :editar, :eliminar)";
                $insert_permiso_stmt = $db->prepare($insert_permiso);
                $insert_permiso_stmt->bindParam(':usuario_id', $usuario_id);
                $insert_permiso_stmt->bindParam(':modulo', $modulo);
                $insert_permiso_stmt->bindParam(':leer', $leer, PDO::PARAM_INT);
                $insert_permiso_stmt->bindParam(':crear', $crear, PDO::PARAM_INT);
                $insert_permiso_stmt->bindParam(':editar', $editar, PDO::PARAM_INT);
                $insert_permiso_stmt->bindParam(':eliminar', $eliminar, PDO::PARAM_INT);
                $insert_permiso_stmt->execute();
            }
        }
        
        $db->commit();
        
        $_SESSION['success'] = empty($usuario_id) ? 
            "Usuario creado correctamente con " . count($roles) . " rol(es)" : 
            "Usuario y permisos actualizados correctamente";
        
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Error en guardar_usuario_completo.php: " . $e->getMessage());
        $_SESSION['error'] = "Error del sistema al guardar: " . $e->getMessage();
    }
    
    header("Location: index.php");
    exit;
} else {
    $_SESSION['error'] = "Método no permitido";
    header("Location: index.php");
    exit;
}
?>