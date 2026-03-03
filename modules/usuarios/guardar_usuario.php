<?php
/**
 * ============================================
 * ARCHIVO: guardar_usuario.php
 * UBICACIÓN: /modules/usuarios/guardar_usuario.php
 * VERSIÓN: Simplificada (solución error 500)
 * ============================================
 */

// Activar errores temporalmente para depuración
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Incluir configuración básica
require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación básica
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

// Verificar que sea POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Método no permitido";
    header("Location: index.php");
    exit();
}

// Obtener conexión
$database = Database::getInstance();
$db = $database->getConnection();

if (!$db) {
    $_SESSION['error'] = "Error de conexión a la base de datos";
    header("Location: index.php");
    exit();
}

// Obtener datos del formulario
$usuario_id = isset($_POST['usuario_id']) && !empty($_POST['usuario_id']) ? intval($_POST['usuario_id']) : 0;
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$nombre = trim($_POST['nombre'] ?? '');
$email = trim($_POST['email'] ?? '');
$telefono = trim($_POST['telefono'] ?? '');
$rol = $_POST['rol'] ?? '';
$activo = isset($_POST['activo']) ? 1 : 0;

// Validaciones básicas
if (empty($username) || strlen($username) < 3) {
    $_SESSION['error'] = "El nombre de usuario es obligatorio (mínimo 3 caracteres)";
    header("Location: " . ($usuario_id ? "editar.php?id=$usuario_id" : "crear.php"));
    exit();
}

if (empty($nombre)) {
    $_SESSION['error'] = "El nombre completo es obligatorio";
    header("Location: " . ($usuario_id ? "editar.php?id=$usuario_id" : "crear.php"));
    exit();
}

if (empty($rol)) {
    $_SESSION['error'] = "Debe seleccionar un rol";
    header("Location: " . ($usuario_id ? "editar.php?id=$usuario_id" : "crear.php"));
    exit();
}

// Para nuevo usuario, validar contraseña
if ($usuario_id == 0 && empty($password)) {
    $_SESSION['error'] = "La contraseña es obligatoria para nuevos usuarios";
    header("Location: crear.php");
    exit();
}

try {
    if ($usuario_id == 0) {
        // CREAR NUEVO USUARIO
        
        // Verificar si el username ya existe
        $check = $db->prepare("SELECT id FROM usuarios WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) {
            $_SESSION['error'] = "El nombre de usuario '$username' ya existe";
            header("Location: crear.php");
            exit();
        }
        
        // Insertar
        $sql = "INSERT INTO usuarios (username, password, nombre, email, telefono, rol, activo, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $db->prepare($sql);
        
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $result = $stmt->execute([
            $username,
            $hashed_password,
            $nombre,
            $email ?: null,
            $telefono ?: null,
            $rol,
            $activo
        ]);
        
        if ($result) {
            $_SESSION['success'] = "Usuario '$username' creado correctamente";
        } else {
            $_SESSION['error'] = "Error al crear el usuario";
        }
        
    } else {
        // ACTUALIZAR USUARIO
        
        // Verificar si el username ya existe para otro usuario
        $check = $db->prepare("SELECT id FROM usuarios WHERE username = ? AND id != ?");
        $check->execute([$username, $usuario_id]);
        if ($check->fetch()) {
            $_SESSION['error'] = "El nombre de usuario '$username' ya existe";
            header("Location: editar.php?id=$usuario_id");
            exit();
        }
        
        if (!empty($password)) {
            // Actualizar con contraseña
            $sql = "UPDATE usuarios SET username = ?, password = ?, nombre = ?, email = ?, telefono = ?, rol = ?, activo = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $db->prepare($sql);
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $result = $stmt->execute([
                $username,
                $hashed_password,
                $nombre,
                $email ?: null,
                $telefono ?: null,
                $rol,
                $activo,
                $usuario_id
            ]);
        } else {
            // Actualizar sin contraseña
            $sql = "UPDATE usuarios SET username = ?, nombre = ?, email = ?, telefono = ?, rol = ?, activo = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([
                $username,
                $nombre,
                $email ?: null,
                $telefono ?: null,
                $rol,
                $activo,
                $usuario_id
            ]);
        }
        
        if ($result) {
            $_SESSION['success'] = "Usuario actualizado correctamente";
        } else {
            $_SESSION['error'] = "Error al actualizar el usuario";
        }
    }
    
} catch (PDOException $e) {
    error_log("Error en guardar_usuario: " . $e->getMessage());
    $_SESSION['error'] = "Error en la base de datos: " . $e->getMessage();
}

// Redirigir
if ($usuario_id > 0) {
    header("Location: editar.php?id=" . $usuario_id);
} else {
    header("Location: index.php");
}
exit();
?>