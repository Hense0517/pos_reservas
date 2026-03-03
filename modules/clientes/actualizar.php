<?php
session_start();
require_once '../../config/database.php'; // Cambiado para que coincida con guardar.php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$cliente_id = $_POST['id'];

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

    // Verificar si el número de documento ya existe (excluyendo el actual)
    $query_documento = "SELECT id FROM clientes WHERE numero_documento = ? AND id != ?";
    $stmt_documento = $db->prepare($query_documento);
    $stmt_documento->execute([$numero_documento, $cliente_id]);
    if ($stmt_documento->fetch()) {
        $errores[] = "El número de documento ya está registrado en otro cliente";
    }

    // Verificar si el email ya existe (excluyendo el actual)
    if (!empty($email)) {
        $query_email = "SELECT id FROM clientes WHERE email = ? AND id != ?";
        $stmt_email = $db->prepare($query_email);
        $stmt_email->execute([$email, $cliente_id]);
        if ($stmt_email->fetch()) {
            $errores[] = "El email ya está registrado en otro cliente";
        }
    }

    if (!empty($errores)) {
        $_SESSION['error'] = implode('<br>', $errores);
        header('Location: editar.php?id=' . $cliente_id);
        exit;
    }

    // Actualizar cliente
    $query = "UPDATE clientes SET nombre = ?, tipo_documento = ?, numero_documento = ?, 
              telefono = ?, email = ?, direccion = ?, updated_at = CURRENT_TIMESTAMP 
              WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$nombre, $tipo_documento, $numero_documento, $telefono, $email, $direccion, $cliente_id]);

    $_SESSION['success'] = "Cliente actualizado exitosamente";
    header('Location: index.php');

} catch (Exception $e) {
    $_SESSION['success'] = "Error al actualizar el cliente: " . $e->getMessage();
    header('Location: editar.php?id=' . $cliente_id);
}
exit;
?>