<?php
session_start();
require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    // Recoger y validar datos
    $nombre = trim($_POST['nombre']);
    $tipo_documento = $_POST['tipo_documento'];
    $numero_documento = trim($_POST['numero_documento']);
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');

    // Validaciones
    $errores = [];

    if (empty($nombre)) {
        $errores[] = "El nombre del cliente es obligatorio";
    }

    if (empty($numero_documento)) {
        $errores[] = "El número de documento es obligatorio";
    }

    // Verificar si el número de documento ya existe
    $query_documento = "SELECT id FROM clientes WHERE numero_documento = ?";
    $stmt_documento = $db->prepare($query_documento);
    $stmt_documento->execute([$numero_documento]);
    if ($stmt_documento->fetch()) {
        $errores[] = "El número de documento ya está registrado";
    }

    // Verificar si el email ya existe
    if (!empty($email)) {
        $query_email = "SELECT id FROM clientes WHERE email = ?";
        $stmt_email = $db->prepare($query_email);
        $stmt_email->execute([$email]);
        if ($stmt_email->fetch()) {
            $errores[] = "El email ya está registrado";
        }
    }

    if (!empty($errores)) {
        $_SESSION['error'] = implode('<br>', $errores);
        header('Location: crear.php');
        exit;
    }

    // Insertar cliente
    $query = "INSERT INTO clientes (nombre, tipo_documento, numero_documento, telefono, email, direccion) 
              VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $db->prepare($query);
    $stmt->execute([$nombre, $tipo_documento, $numero_documento, $telefono, $email, $direccion]);

    $_SESSION['success'] = "Cliente creado exitosamente";
    header('Location: index.php');

} catch (Exception $e) {
    $_SESSION['error'] = "Error al crear el cliente: " . $e->getMessage();
    header('Location: crear.php');
}
exit;
?>